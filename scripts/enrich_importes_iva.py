#!/usr/bin/env python3
"""
Enriquece contratos existentes en la BD con importe_adjudicacion_con_iva desde BQuant.

PLACSP XML no tiene TaxInclusiveAmount, pero BQuant sí tiene importe_adj_con_iva
para ~75% de los contratos. Este script actualiza directamente la BD SQLite.

Uso:
    python scripts/enrich_importes_iva.py storage/app/licitaciones_espana.parquet --db database/database.sqlite
    python scripts/enrich_importes_iva.py storage/app/licitaciones_espana.parquet --dry-run
"""

import argparse
import sqlite3
import sys
from pathlib import Path

try:
    import pandas as pd
except ImportError:
    print("Error: pandas no instalado. Ejecuta: pip install pandas pyarrow")
    sys.exit(1)


def extract_numeric_id(url: str) -> str:
    if pd.isna(url):
        return ""
    parts = str(url).rsplit("/", 1)
    return parts[-1] if len(parts) > 1 else str(url)


def enrich(input_path: str, db_path: str, dry_run: bool = False) -> None:
    input_file = Path(input_path)
    if not input_file.exists():
        print(f"Error: fichero no encontrado: {input_file}")
        sys.exit(1)

    db_file = Path(db_path)
    if not db_file.exists():
        print(f"Error: BD no encontrada: {db_path}")
        sys.exit(1)

    print(f"Leyendo {input_file}...")
    df = pd.read_parquet(input_file, columns=["id", "importe_adj_con_iva"])
    print(f"  Total registros BQuant: {len(df):,}")

    # Extraer ID numérico y deduplicar
    df["id_num"] = df["id"].apply(extract_numeric_id)
    df = df.drop_duplicates(subset=["id_num"], keep="last")

    # Solo registros con importe_adj_con_iva
    df = df[df["importe_adj_con_iva"].notna()].copy()
    print(f"  Con importe_adj_con_iva: {len(df):,}")

    # Leer IDs de contratos sin importe_adjudicacion_con_iva en BD
    conn = sqlite3.connect(db_path)
    cursor = conn.execute(
        "SELECT placsp_id FROM contratos WHERE importe_adjudicacion_con_iva IS NULL"
    )
    null_ids = {row[0] for row in cursor}
    print(f"  Contratos BD sin importe_con_iva: {len(null_ids):,}")

    # Intersección
    enrichable = df[df["id_num"].isin(null_ids)]
    print(f"  Contratos enriquecibles: {len(enrichable):,}")

    if len(enrichable) == 0:
        print("No hay contratos para enriquecer.")
        conn.close()
        return

    if dry_run:
        print(f"[DRY-RUN] Se actualizarían {len(enrichable):,} contratos.")

        # Mostrar muestra
        sample = enrichable.head(5)
        for _, row in sample.iterrows():
            print(f"  {row['id_num']}: importe_con_iva = {row['importe_adj_con_iva']:.2f}")

        conn.close()
        return

    # Actualizar en batches
    print("Actualizando BD...")
    batch_size = 1000
    updated = 0
    total = len(enrichable)

    for start in range(0, total, batch_size):
        batch = enrichable.iloc[start : start + batch_size]
        for _, row in batch.iterrows():
            conn.execute(
                "UPDATE contratos SET importe_adjudicacion_con_iva = ? WHERE placsp_id = ?",
                (float(row["importe_adj_con_iva"]), row["id_num"]),
            )
        conn.commit()
        updated += len(batch)
        if updated % 100000 == 0 or updated == total:
            print(f"  {updated:,}/{total:,} actualizados ({100*updated/total:.1f}%)")

    conn.close()
    print(f"Completado: {updated:,} contratos enriquecidos con importe_adjudicacion_con_iva.")


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Enriquece contratos existentes con importe_adj_con_iva de BQuant"
    )
    parser.add_argument("input", help="Ruta al .parquet de BQuant")
    parser.add_argument(
        "--db",
        default="database/database.sqlite",
        help="Ruta a la BD SQLite",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Solo mostrar qué se haría, sin modificar BD",
    )
    args = parser.parse_args()
    enrich(args.input, args.db, args.dry_run)


if __name__ == "__main__":
    main()

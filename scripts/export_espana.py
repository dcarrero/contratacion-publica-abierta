#!/usr/bin/env python3
"""
Exporta registros de contratación pública de toda España desde el dataset BQuant (Parquet).

Exporta solo registros que NO estén ya en la BD SQLite (por placsp_id numérico).
Genera CSV compatible con `php artisan bootstrap:csv`.

Uso:
    python scripts/export_espana.py storage/app/licitaciones_espana.parquet storage/app/espana_bquant_nuevos.csv --db database/database.sqlite

El CSV resultante se importa con:
    php artisan bootstrap:csv storage/app/espana_bquant_nuevos.csv --chunk=500
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


# Columnas BQuant → nombre CSV esperado por bootstrap:csv
COLUMNS_RENAME = {
    "importe_adj_con_iva": "importe_adjudicacion_con_iva",
}

# Columnas de salida (nombres finales tras renombrar)
OUTPUT_COLUMNS = [
    "id",
    "expediente",
    "objeto",
    "url",
    "organo_contratante",
    "nif_organo",
    "dir3_organo",
    "adjudicatario",
    "nif_adjudicatario",
    "es_pyme",
    "tipo_contrato",
    "procedimiento",
    "estado",
    "importe_sin_iva",
    "importe_con_iva",
    "importe_adjudicacion",
    "importe_adjudicacion_con_iva",
    "nuts",
    "cpv_principal",
    "ubicacion",
    "num_ofertas",
    "fecha_publicacion",
    "fecha_limite",
    "fecha_adjudicacion",
    "fecha_updated",
    "duracion",
    "duracion_unidad",
]


def extract_numeric_id(url: str) -> str:
    """Extrae el ID numérico del final de una URL PLACSP."""
    if pd.isna(url):
        return ""
    parts = str(url).rsplit("/", 1)
    return parts[-1] if len(parts) > 1 else str(url)


def load_existing_ids(db_path: str) -> set:
    """Lee los placsp_id existentes de la BD SQLite."""
    db_file = Path(db_path)
    if not db_file.exists():
        print(f"  BD no encontrada: {db_path}, exportando todo.")
        return set()

    conn = sqlite3.connect(db_path)
    cursor = conn.execute("SELECT placsp_id FROM contratos")
    ids = {row[0] for row in cursor}
    conn.close()
    print(f"  IDs existentes en BD: {len(ids):,}")
    return ids


def export_nuevos(input_path: str, output_path: str, db_path: str) -> None:
    input_file = Path(input_path)
    if not input_file.exists():
        print(f"Error: fichero no encontrado: {input_file}")
        sys.exit(1)

    print(f"Leyendo {input_file}...")
    df = pd.read_parquet(input_file)
    print(f"  Total registros BQuant: {len(df):,}")

    # Renombrar columnas
    rename_map = {k: v for k, v in COLUMNS_RENAME.items() if k in df.columns}
    df = df.rename(columns=rename_map)

    # Extraer ID numérico
    df["id_num"] = df["id"].apply(extract_numeric_id)

    # Deduplicar por id_num (mantener último, que suele tener más datos)
    before_dedup = len(df)
    df = df.drop_duplicates(subset=["id_num"], keep="last")
    dupes = before_dedup - len(df)
    if dupes > 0:
        print(f"  Duplicados eliminados: {dupes:,}")
    print(f"  IDs únicos BQuant: {len(df):,}")

    # Cargar IDs existentes en BD
    existing_ids = load_existing_ids(db_path)

    # Filtrar solo nuevos
    if existing_ids:
        df_new = df[~df["id_num"].isin(existing_ids)].copy()
        print(f"  Registros nuevos (no en BD): {len(df_new):,}")
    else:
        df_new = df.copy()
        print(f"  Exportando todos: {len(df_new):,}")

    # Reemplazar id URL por id numérico para compatibilidad con bootstrap:csv
    df_new["id"] = df_new["id_num"]

    # Filtrar sin nif_organo
    has_nif = df_new["nif_organo"].notna() & (df_new["nif_organo"] != "")
    sin_nif = (~has_nif).sum()
    df_new = df_new[has_nif]
    if sin_nif > 0:
        print(f"  Sin nif_organo (descartados): {sin_nif:,}")

    print(f"  Registros exportables: {len(df_new):,}")

    if len(df_new) == 0:
        print("No hay registros nuevos para exportar.")
        return

    # Seleccionar columnas de salida
    available = [c for c in OUTPUT_COLUMNS if c in df_new.columns]
    missing = [c for c in OUTPUT_COLUMNS if c not in df_new.columns]
    if missing:
        print(f"  Columnas sin datos: {missing}")

    df_out = df_new[available]

    # Exportar
    output_file = Path(output_path)
    df_out.to_csv(output_file, index=False, encoding="utf-8")
    size_mb = output_file.stat().st_size / (1024 * 1024)
    print(f"  Exportado: {output_file} ({len(df_out):,} filas, {size_mb:.1f} MB)")


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Exporta registros nuevos de BQuant (toda España) a CSV"
    )
    parser.add_argument("input", help="Ruta al .parquet de BQuant")
    parser.add_argument(
        "output",
        nargs="?",
        default="storage/app/espana_bquant_nuevos.csv",
        help="Ruta del CSV de salida",
    )
    parser.add_argument(
        "--db",
        default="database/database.sqlite",
        help="Ruta a la BD SQLite para excluir IDs existentes",
    )
    args = parser.parse_args()
    export_nuevos(args.input, args.output, args.db)


if __name__ == "__main__":
    main()

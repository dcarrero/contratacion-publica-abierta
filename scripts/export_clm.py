#!/usr/bin/env python3
"""
Exporta registros de contratación pública de CLM desde el dataset BQuant (Parquet).

Filtra por NUTS ES42 (Castilla-La Mancha) y exporta a CSV con las columnas
necesarias para el command bootstrap:csv de Laravel.

Uso:
    pip install pandas pyarrow
    python scripts/export_clm.py licitaciones_espana.parquet clm_bootstrap.csv

El CSV resultante se copia a storage/app/ para importar con:
    php artisan bootstrap:csv storage/app/clm_bootstrap.csv
"""

import argparse
import sys
from pathlib import Path

try:
    import pandas as pd
except ImportError:
    print("Error: pandas no instalado. Ejecuta: pip install pandas pyarrow")
    sys.exit(1)


# Columnas BQuant que necesitan renombrarse (origen → destino CSV)
# La mayoría ya tienen el nombre correcto; solo renombramos las que difieren.
COLUMNS_RENAME = {
    "importe_adj_con_iva": "importe_adjudicacion_con_iva",
    "objeto": "objeto",  # ya coincide, incluido por claridad
}

# Columnas que queremos en el CSV de salida (nombres finales tras renombrar)
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


def export_clm(input_path: str, output_path: str, nuts_code: str = "ES42") -> None:
    input_file = Path(input_path)
    if not input_file.exists():
        print(f"Error: fichero no encontrado: {input_file}")
        sys.exit(1)

    print(f"Leyendo {input_file}...")
    df = pd.read_parquet(input_file)
    print(f"  Total registros: {len(df):,}")

    # Renombrar columnas que difieren
    rename_map = {k: v for k, v in COLUMNS_RENAME.items() if k in df.columns}
    df = df.rename(columns=rename_map)

    # Filtrar por NUTS CLM (ES42*)
    if "nuts" not in df.columns:
        print("Error: columna 'nuts' no encontrada en el Parquet.")
        sys.exit(1)

    df_clm = df[df["nuts"].astype(str).str.startswith(nuts_code, na=False)].copy()
    print(f"  Registros CLM ({nuts_code}*): {len(df_clm):,}")

    if len(df_clm) == 0:
        print("Aviso: 0 registros para CLM. Revisa el filtro NUTS.")
        sys.exit(0)

    # Deduplicar por id (el dataset puede tener filas duplicadas por lote/adjudicatario)
    before_dedup = len(df_clm)
    df_clm = df_clm.drop_duplicates(subset=["id"], keep="last")
    dupes = before_dedup - len(df_clm)
    if dupes > 0:
        print(f"  Duplicados eliminados (por id): {dupes:,}")

    # Filtrar filas sin nif_organo (no se pueden importar)
    has_nif = df_clm["nif_organo"].notna() & (df_clm["nif_organo"] != "")
    sin_nif = (~has_nif).sum()
    df_clm = df_clm[has_nif]
    if sin_nif > 0:
        print(f"  Registros sin nif_organo descartados: {sin_nif:,}")

    print(f"  Registros validos: {len(df_clm):,}")

    # Seleccionar solo columnas de salida que existan
    available_output = [c for c in OUTPUT_COLUMNS if c in df_clm.columns]
    missing = [c for c in OUTPUT_COLUMNS if c not in df_clm.columns]
    if missing:
        print(f"  Columnas sin datos (se omiten): {missing}")

    df_out = df_clm[available_output]

    # Exportar
    output_file = Path(output_path)
    df_out.to_csv(output_file, index=False, encoding="utf-8")
    print(f"  Exportado: {output_file} ({len(df_out):,} filas)")


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Exporta registros CLM desde dataset BQuant Parquet a CSV"
    )
    parser.add_argument("input", help="Ruta al fichero .parquet de BQuant")
    parser.add_argument(
        "output",
        nargs="?",
        default="clm_bootstrap.csv",
        help="Ruta del CSV de salida (default: clm_bootstrap.csv)",
    )
    parser.add_argument(
        "--nuts",
        default="ES42",
        help="Prefijo NUTS para filtrar (default: ES42)",
    )
    args = parser.parse_args()
    export_clm(args.input, args.output, args.nuts)


if __name__ == "__main__":
    main()

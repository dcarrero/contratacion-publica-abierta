#!/usr/bin/env python3
"""
Importación rápida de BQuant a SQLite mediante bulk inserts.

~50x más rápido que bootstrap:csv (Eloquent) al usar INSERT OR IGNORE
directo con transacciones por batch.

Uso:
    python scripts/bulk_import_bquant.py storage/app/espana_bquant_nuevos.csv --db database/database.sqlite
"""

import argparse
import csv
import hashlib
import json
import re
import sqlite3
import sys
import time
import unicodedata
from datetime import datetime
from pathlib import Path


FUENTE_DATOS_ID = 5  # bquant-bootstrap


def normalize_name(name: str) -> str:
    """Replica NormalizeName de PHP: ASCII uppercase + formas societarias."""
    if not name:
        return ""
    # Unicode → ASCII
    nfkd = unicodedata.normalize("NFKD", name)
    ascii_str = nfkd.encode("ascii", "ignore").decode("ascii")
    result = ascii_str.upper().strip()
    # Normalizar formas societarias
    replacements = {
        r"\bS\.L\.U\.\b": "SLU",
        r"\bS\.L\.U\b": "SLU",
        r"\bS\.L\.\b": "SL",
        r"\bS\.L\b": "SL",
        r"\bS\.A\.U\.\b": "SAU",
        r"\bS\.A\.U\b": "SAU",
        r"\bS\.A\.\b": "SA",
        r"\bS\.A\b": "SA",
        r"\bS\.COOP\.\b": "SCOOP",
        r"\bS\.C\.\b": "SC",
    }
    for pattern, repl in replacements.items():
        result = re.sub(pattern, repl, result)
    # Colapsar espacios
    result = re.sub(r"\s+", " ", result).strip()
    return result


def detect_tipo_identificador(nif: str, pais: str = "ES") -> str:
    if pais != "ES":
        if re.match(r"^[A-Z]{2}\d", nif):
            return "VAT"
        return "OTHER"
    if re.match(r"^[XYZ]\d", nif):
        return "NIE"
    return "NIF"


def compute_hash(data: dict) -> str:
    exclude = {"hash_contenido", "organismo_id", "adjudicatario_id", "fuente_datos_id"}
    hash_data = {k: v for k, v in data.items() if k not in exclude}
    keys = sorted(hash_data.keys())
    ordered = {k: hash_data[k] for k in keys}
    return hashlib.sha256(json.dumps(ordered, default=str).encode()).hexdigest()


def safe_float(val):
    if val is None or val == "":
        return None
    try:
        return float(val)
    except (ValueError, TypeError):
        return None


def safe_int(val):
    if val is None or val == "":
        return None
    try:
        return int(float(val))
    except (ValueError, TypeError):
        return None


def safe_date(val):
    if val is None or val == "":
        return None
    val = str(val).strip()[:10]
    # Validar formato YYYY-MM-DD
    if re.match(r"^\d{4}-\d{2}-\d{2}$", val):
        return val
    return None


def bulk_import(csv_path: str, db_path: str, batch_size: int = 5000) -> None:
    csv_file = Path(csv_path)
    if not csv_file.exists():
        print(f"Error: {csv_file} no encontrado")
        sys.exit(1)

    conn = sqlite3.connect(db_path)
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA synchronous=NORMAL")
    conn.execute("PRAGMA cache_size=-64000")  # 64MB cache
    conn.execute("PRAGMA temp_store=MEMORY")

    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    # Cargar IDs existentes
    print("Cargando datos existentes...")
    existing_placsp = set()
    for row in conn.execute("SELECT placsp_id FROM contratos"):
        existing_placsp.add(row[0])
    print(f"  Contratos existentes: {len(existing_placsp):,}")

    # Cargar organismos existentes (nif → id)
    org_map = {}
    for row in conn.execute("SELECT id, nif FROM organismos"):
        org_map[row[1]] = row[0]
    print(f"  Organismos existentes: {len(org_map):,}")

    # Cargar adjudicatarios existentes (nif → id)
    adj_map = {}
    for row in conn.execute("SELECT id, nif FROM adjudicatarios"):
        adj_map[row[1]] = row[0]
    print(f"  Adjudicatarios existentes: {len(adj_map):,}")

    # Contar total de líneas
    print("Contando registros CSV...")
    with open(csv_file, "r", encoding="utf-8") as f:
        total_lines = sum(1 for _ in f) - 1  # menos header
    print(f"  Total registros CSV: {total_lines:,}")

    # Procesar CSV
    print("Importando...")
    start_time = time.time()
    processed = 0
    created = 0
    skipped = 0
    errors = 0
    orgs_created = 0
    adjs_created = 0

    with open(csv_file, "r", encoding="utf-8") as f:
        reader = csv.DictReader(f)

        contrato_batch = []

        for row in reader:
            processed += 1

            # Extraer placsp_id
            placsp_id = row.get("id", "").strip()
            if not placsp_id:
                skipped += 1
                continue

            # Ya existe?
            if placsp_id in existing_placsp:
                skipped += 1
                continue

            nif_organo = (row.get("nif_organo") or "").strip().upper()
            if not nif_organo:
                skipped += 1
                continue

            # Resolver organismo
            if nif_organo not in org_map:
                nombre_org = row.get("organo_contratante") or nif_organo
                nombre_norm = normalize_name(nombre_org)
                nuts_contrato = row.get("nuts") or ""
                nuts_org = nuts_contrato[:4] if len(nuts_contrato) >= 4 else None

                cursor = conn.execute(
                    """INSERT OR IGNORE INTO organismos
                    (nif, nombre, nombre_normalizado, dir3, nuts, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)""",
                    (nif_organo, nombre_org, nombre_norm,
                     row.get("dir3_organo") or None, nuts_org, now, now),
                )
                if cursor.lastrowid and cursor.rowcount > 0:
                    org_map[nif_organo] = cursor.lastrowid
                    orgs_created += 1
                else:
                    # Ya fue insertado por otro thread/batch, leer su id
                    r = conn.execute(
                        "SELECT id FROM organismos WHERE nif=?", (nif_organo,)
                    ).fetchone()
                    if r:
                        org_map[nif_organo] = r[0]
                    else:
                        errors += 1
                        continue

            organismo_id = org_map[nif_organo]

            # Resolver adjudicatario
            nif_adj = (row.get("nif_adjudicatario") or "").strip().upper()
            adjudicatario_id = None
            if nif_adj:
                if nif_adj not in adj_map:
                    nombre_adj = row.get("adjudicatario") or nif_adj
                    nombre_adj_norm = normalize_name(nombre_adj)
                    es_pyme_val = (row.get("es_pyme") or "").lower()
                    es_pyme = 1 if es_pyme_val in ("true", "1", "si", "yes", "sí") else (0 if es_pyme_val in ("false", "0", "no") else None)
                    tipo_id = detect_tipo_identificador(nif_adj, "ES")

                    cursor = conn.execute(
                        """INSERT OR IGNORE INTO adjudicatarios
                        (nif, nombre, nombre_normalizado, es_pyme, pais, tipo_identificador, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)""",
                        (nif_adj, nombre_adj, nombre_adj_norm, es_pyme,
                         "ES", tipo_id, now, now),
                    )
                    if cursor.lastrowid and cursor.rowcount > 0:
                        adj_map[nif_adj] = cursor.lastrowid
                        adjs_created += 1
                    else:
                        r = conn.execute(
                            "SELECT id FROM adjudicatarios WHERE nif=?", (nif_adj,)
                        ).fetchone()
                        if r:
                            adj_map[nif_adj] = r[0]

                adjudicatario_id = adj_map.get(nif_adj)

            # Preparar datos contrato
            nuts = row.get("nuts") or None
            duracion = row.get("duracion") or None
            duracion_unidad = row.get("duracion_unidad") or None
            if duracion and duracion_unidad:
                duracion = f"{duracion} {duracion_unidad}"

            contrato_data = {
                "placsp_id": placsp_id,
                "expediente": row.get("expediente") or None,
                "url_placsp": row.get("url") or None,
                "organismo_id": organismo_id,
                "adjudicatario_id": adjudicatario_id,
                "nif_organo": nif_organo,
                "nif_adjudicatario": nif_adj or None,
                "nombre_adjudicatario": row.get("adjudicatario") or None,
                "objeto": row.get("objeto") or None,
                "tipo_contrato": row.get("tipo_contrato") or None,
                "procedimiento": row.get("procedimiento") or None,
                "estado": row.get("estado") or None,
                "importe_licitacion": safe_float(row.get("importe_sin_iva")),
                "importe_licitacion_con_iva": safe_float(row.get("importe_con_iva")),
                "importe_adjudicacion": safe_float(row.get("importe_adjudicacion")),
                "importe_adjudicacion_con_iva": safe_float(row.get("importe_adjudicacion_con_iva")),
                "duracion": duracion,
                "cpv": row.get("cpv_principal") or None,
                "nuts": nuts,
                "lugar_ejecucion": row.get("ubicacion") or None,
                "num_ofertas": safe_int(row.get("num_ofertas")),
                "es_menor": 0,
                "es_clm": 1 if (nuts or "").startswith("ES42") else 0,
                "fecha_publicacion": safe_date(row.get("fecha_publicacion")),
                "fecha_limite": safe_date(row.get("fecha_limite")),
                "fecha_adjudicacion": safe_date(row.get("fecha_adjudicacion")),
                "fecha_updated": row.get("fecha_updated") or None,
                "fuente_datos_id": FUENTE_DATOS_ID,
                "tipo_registro": "licitacion",
                "moneda": "EUR",
                "version": 1,
                "created_at": now,
                "updated_at": now,
            }

            contrato_data["hash_contenido"] = compute_hash(contrato_data)

            contrato_batch.append(contrato_data)
            existing_placsp.add(placsp_id)

            if len(contrato_batch) >= batch_size:
                _insert_batch(conn, contrato_batch)
                created += len(contrato_batch)
                contrato_batch = []

                elapsed = time.time() - start_time
                rate = processed / elapsed
                eta = (total_lines - processed) / rate if rate > 0 else 0
                pct = 100 * processed / total_lines
                print(
                    f"  {processed:,}/{total_lines:,} ({pct:.1f}%) | "
                    f"Creados: {created:,} | Skip: {skipped:,} | "
                    f"Orgs+: {orgs_created:,} | Adjs+: {adjs_created:,} | "
                    f"{rate:.0f} rec/s | ETA: {eta/60:.0f}min"
                )

        # Último batch
        if contrato_batch:
            _insert_batch(conn, contrato_batch)
            created += len(contrato_batch)

    conn.commit()
    conn.close()

    elapsed = time.time() - start_time
    print(f"\nCompletado en {elapsed/60:.1f} minutos:")
    print(f"  Procesados: {processed:,}")
    print(f"  Creados: {created:,}")
    print(f"  Saltados: {skipped:,}")
    print(f"  Errores: {errors:,}")
    print(f"  Organismos nuevos: {orgs_created:,}")
    print(f"  Adjudicatarios nuevos: {adjs_created:,}")


def _insert_batch(conn, batch):
    """Inserta un batch de contratos con INSERT OR IGNORE."""
    if not batch:
        return
    keys = list(batch[0].keys())
    placeholders = ", ".join(["?"] * len(keys))
    cols = ", ".join(f'"{k}"' for k in keys)
    sql = f"INSERT OR IGNORE INTO contratos ({cols}) VALUES ({placeholders})"

    values = [tuple(row[k] for k in keys) for row in batch]
    conn.executemany(sql, values)
    conn.commit()


def main():
    parser = argparse.ArgumentParser(
        description="Importación rápida de BQuant CSV a SQLite"
    )
    parser.add_argument("csv", help="Ruta al CSV exportado")
    parser.add_argument(
        "--db", default="database/database.sqlite", help="Ruta a la BD SQLite"
    )
    parser.add_argument(
        "--batch", type=int, default=5000, help="Tamaño de batch (default: 5000)"
    )
    args = parser.parse_args()
    bulk_import(args.csv, args.db, args.batch)


if __name__ == "__main__":
    main()

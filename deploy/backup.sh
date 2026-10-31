#!/usr/bin/env bash
# =============================================================================
# Copia de seguridad de la base de datos. Pensado para cron en la VM de PostgreSQL.
#
#   0 3 * * * /opt/contratacion/deploy/backup.sh >> /var/log/backup-contratacion.log 2>&1
#
# Complementa, no sustituye, a los snapshots de Proxmox: un snapshot restaura la VM
# entera; esto permite recuperar solo la base, o una tabla, sin tocar el resto.
# =============================================================================
set -euo pipefail

DESTINO="${BACKUP_DIR:-/var/backups/contratacion}"
RETENER_DIAS="${BACKUP_KEEP_DAYS:-14}"
FICHERO="$DESTINO/contratacion-$(date +%Y%m%d-%H%M).dump"

mkdir -p "$DESTINO"

echo "[$(date '+%F %T')] Iniciando copia..."
pg_dump -U contratacion -d contratacion -Fc > "$FICHERO"

# Un volcado truncado es peor que no tener copia, porque da falsa seguridad.
if [ "$(head -c 5 "$FICHERO")" != "PGDMP" ]; then
  echo "[$(date '+%F %T')] ERROR: cabecera inválida, la copia puede estar truncada." >&2
  exit 1
fi

TAM=$(du -h "$FICHERO" | cut -f1)
echo "[$(date '+%F %T')] Copia correcta: $FICHERO ($TAM)"

# Retención: se borran las copias más antiguas, nunca la última.
find "$DESTINO" -name 'contratacion-*.dump' -type f -mtime +"$RETENER_DIAS" -print -delete

echo "[$(date '+%F %T')] Copias conservadas: $(ls -1 "$DESTINO"/contratacion-*.dump 2>/dev/null | wc -l)"

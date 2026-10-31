#!/usr/bin/env bash
# =============================================================================
# Migra la base de datos de desarrollo (Docker local) a la VM de PostgreSQL.
#
# Uso, desde el equipo de desarrollo:
#   deploy/migrar-datos.sh 10.0.0.10
#
# Hace: volcado desde el contenedor local -> restauración en la VM -> verificación
# de recuentos por fuente. Si los recuentos no cuadran, aborta antes de dar por
# buena la migración.
# =============================================================================
set -euo pipefail

DESTINO_HOST="${1:-}"
DESTINO_DB="${2:-contratacion}"
DESTINO_USER="${3:-contratacion}"
DUMP="backups/migracion-$(date +%Y%m%d-%H%M).dump"

if [ -z "$DESTINO_HOST" ]; then
  echo "Uso: $0 <ip-privada-postgres> [base] [usuario]" >&2
  exit 1
fi

echo "==> 1/5 Volcando la base local (formato custom, comprimido)..."
docker compose exec -T postgres pg_dump -U contratacion -d contratacion -Fc > "$DUMP"
echo "    $DUMP ($(du -h "$DUMP" | cut -f1))"

# Un dump truncado restaura "sin errores" y deja la base a medias: se comprueba la cabecera.
if [ "$(head -c 5 "$DUMP")" != "PGDMP" ]; then
  echo "ERROR: el volcado no tiene cabecera PGDMP; puede estar truncado." >&2
  exit 1
fi

echo "==> 2/5 Recuentos en ORIGEN..."
docker compose exec -T postgres psql -U contratacion -d contratacion -t -A -F'|' -c "
  SELECT f.slug, count(c.id) FROM fuentes_datos f
  LEFT JOIN contratos c ON c.fuente_datos_id = f.id
  GROUP BY f.slug ORDER BY f.slug;" > /tmp/recuento-origen.txt
echo "    $(wc -l < /tmp/recuento-origen.txt) fuentes"

echo "==> 3/5 Preparando el destino..."
# La base debe existir y estar vacía. unaccent es necesaria para las búsquedas sin acentos;
# pgvector NO hace falta: el proyecto no usa columnas de tipo vector.
psql -h "$DESTINO_HOST" -U "$DESTINO_USER" -d "$DESTINO_DB" -c "CREATE EXTENSION IF NOT EXISTS unaccent;"

echo "==> 4/5 Restaurando (esto tarda: son ~27 GB descomprimidos)..."
pg_restore -h "$DESTINO_HOST" -U "$DESTINO_USER" -d "$DESTINO_DB" \
  --no-owner --no-privileges --jobs=4 "$DUMP"

echo "==> 5/5 Verificando recuentos en DESTINO..."
psql -h "$DESTINO_HOST" -U "$DESTINO_USER" -d "$DESTINO_DB" -t -A -F'|' -c "
  SELECT f.slug, count(c.id) FROM fuentes_datos f
  LEFT JOIN contratos c ON c.fuente_datos_id = f.id
  GROUP BY f.slug ORDER BY f.slug;" > /tmp/recuento-destino.txt

if diff -q /tmp/recuento-origen.txt /tmp/recuento-destino.txt >/dev/null; then
  echo "✅ Migración correcta: los recuentos por fuente coinciden."
else
  echo "❌ DIFERENCIAS entre origen y destino:" >&2
  diff /tmp/recuento-origen.txt /tmp/recuento-destino.txt >&2 || true
  exit 1
fi

echo
echo "Siguiente paso: ANALYZE para que el planificador tenga estadísticas frescas."
psql -h "$DESTINO_HOST" -U "$DESTINO_USER" -d "$DESTINO_DB" -c "ANALYZE;"
echo "Hecho."

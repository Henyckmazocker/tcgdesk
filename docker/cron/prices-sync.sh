#!/usr/bin/env bash
# =============================================================================
# prices-sync.sh — sincroniza los precios del día (pensado para el cron)
# =============================================================================
# Lo que hay que saber antes de tocar esto:
#
#   1. Se ejecuta como www-data DENTRO del contenedor, no como root. Si corre
#      como root, Monolog crea el log del día con owner root y Apache —que es
#      www-data— deja de poder escribir en él: TODA petición HTTP empieza a
#      devolver 500. Ya pasó una vez.
#
#   2. El código de salida se propaga tal cual. Es lo único que el cron mira, y
#      un fallo que devolviera 0 dejaría un agujero en el histórico de precios
#      que NO se puede rellenar después: MTGJSON solo retiene 90 días.
#
#   3. La hora importa. MTGJSON publica su build a las 9:00 EST, así que el cron
#      va después de las 15:00 CEST.
#
# Instalación (en el host, no en el contenedor):
#
#   crontab -e
#   30 15 * * *  /home/david/Documents/workspace/tcgdesk/docker/cron/prices-sync.sh >> /var/log/tcgdesk-prices.log 2>&1
#
# Comprobar que funcionó, con el número de precios actualizados:
#
#   docker compose exec backend tail -2 storage/logs/api-$(date +%F).log
# =============================================================================

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

cd "$ROOT_DIR" || exit 1

echo "[$(date --iso-8601=seconds)] prices:sync — inicio"

docker compose exec -T -u www-data backend php bin/tcgdesk prices:sync
CODIGO=$?

if [[ $CODIGO -eq 0 ]]; then
  echo "[$(date --iso-8601=seconds)] prices:sync — OK"
else
  echo "[$(date --iso-8601=seconds)] prices:sync — FALLÓ con código $CODIGO" >&2
fi

exit $CODIGO

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
#   4. Al final se lanza `prices:health`, y su código de salida también sale de
#      aquí. Un sync que devuelve 0 no demuestra que el histórico esté al día
#      —puede llevar días sin escribir una fila y terminar "bien"—, y eso es
#      justo lo que pasó durante 33 días. Ver el bloque del final.
#
# Instalación (en el host, no en el contenedor) — INSTALADO el 2026-09-14:
#
#   crontab -e
#   30 15 * * *  /home/david/Documents/workspace/tcgdesk/docker/cron/prices-sync.sh >> /home/david/tcgdesk-prices.log 2>&1
#
#   El log va al home y NO a /var/log: ese directorio no lo puede escribir el
#   usuario del crontab, y bash abre el redirect antes de ejecutar el comando,
#   así que un `>> /var/log/...` no «pierde el log» — impide que el job corra.
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

# La señal de salud va DESPUÉS del sync y se lanza pase lo que pase: compara el
# último día de mtg_price_daily con hoy y devuelve 1 si el desfase pasa de dos
# días. Con el sync caído es lo que distingue «hoy falló y mañana se recupera
# solo» de «lleva una semana sin escribir una fila».
docker compose exec -T -u www-data backend php bin/tcgdesk prices:health
SALUD=$?

# Qué código sale de aquí, que es lo único que el cron mira:
#
#   · Si el sync falló, manda SU código. Es la causa; la salud solo el síntoma, y
#     tapar la causa con el síntoma haría que un fallo de red se leyera como un
#     histórico viejo.
#   · Si el sync fue bien pero la salud dice que el histórico se quedó atrás,
#     sale != 0 igualmente. Salir con 0 ahí sería exactamente el fallo que este
#     plan existe para cerrar: un job "correcto" todos los días encima de una
#     tabla que lleva 33 días parada.
if [[ $CODIGO -ne 0 ]]; then
  exit $CODIGO
fi

exit $SALUD

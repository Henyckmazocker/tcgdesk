#!/usr/bin/env bash
# =============================================================================
# images-cache.sh — baja a disco las imágenes de las cartas coleccionadas
# =============================================================================
# Hermano de prices-sync.sh, y por los mismos motivos:
#
#   1. Se ejecuta como www-data DENTRO del contenedor, no como root. Si corre
#      como root, Monolog crea el log del día con owner root y Apache —que es
#      www-data— deja de poder escribir en él: TODA petición HTTP empieza a
#      devolver 500. Ya pasó una vez. Y aquí hay un motivo extra: las imágenes
#      se escriben bajo storage/, que también es de www-data.
#
#   2. El código de salida se propaga tal cual. `images:cache` devuelve ≠ 0
#      cuando NO consiguió bajar nada habiendo trabajo pendiente —la red caída,
#      el CDN bloqueado, storage/ sin permisos—, que es lo accionable. Una carta
#      suelta que falla se reintenta mañana sola y no tiñe de rojo la ejecución:
#      un cron que falla todos los días para siempre es un cron que nadie mira.
#
#   3. La hora NO importa mucho. A diferencia de los precios, aquí no hay
#      ventana que respetar: se baja lo que se haya añadido a la colección desde
#      ayer. Va de madrugada simplemente para no competir con nada.
#
#   4. Es idempotente y barato en reposo. Pendiente = estar en la colección y no
#      estar en mtg_image_cache, así que una noche sin cartas nuevas son dos
#      consultas y cero peticiones a Scryfall.
#
# Ritmo: 10 req/s, el máximo que pide Scryfall ([[TCGDesk/Fuentes de Datos]]).
# Lo impone el propio comando; --rps solo sirve para bajarlo, nunca para subirlo.
#
# Instalación (en el host, no en el contenedor) — INSTALADO el 2026-09-14:
#
#   crontab -e
#   15 4 * * *  /home/david/Documents/workspace/tcgdesk/docker/cron/images-cache.sh >> /home/david/tcgdesk-images.log 2>&1
#
#   El log va al home y NO a /var/log: ese directorio no lo puede escribir el
#   usuario del crontab, y bash abre el redirect antes de ejecutar el comando,
#   así que un `>> /var/log/...` no «pierde el log» — impide que el job corra.
#
# Comprobar que funcionó:
#
#   docker compose exec backend tail -5 storage/logs/api-$(date +%F).log
#   docker compose exec -T mysql sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" tcgdesk_db -e "SELECT COUNT(*) FROM mtg_image_cache"'
# =============================================================================

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

cd "$ROOT_DIR" || exit 1

echo "[$(date --iso-8601=seconds)] images:cache — inicio"

docker compose exec -T -u www-data backend php bin/tcgdesk images:cache
CODIGO=$?

if [[ $CODIGO -eq 0 ]]; then
  echo "[$(date --iso-8601=seconds)] images:cache — OK"
else
  echo "[$(date --iso-8601=seconds)] images:cache — FALLÓ con código $CODIGO" >&2
fi

exit $CODIGO

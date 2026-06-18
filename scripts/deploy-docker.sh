#!/usr/bin/env bash
# deploy-docker.sh — atomic tar deploy of nc_wireguard into cloud_app
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONTAINER="${CONTAINER:-cloud_app}"
APP_ID="nc_wireguard"
APP_BASE="/var/www/html/custom_apps"

if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
  echo "ERROR: container ${CONTAINER} not running" >&2
  exit 2
fi

if [[ -f "$ROOT/package.json" ]] && [[ "${SKIP_NPM_BUILD:-0}" != "1" ]]; then
  NC_GCS_NM="/media/4TB/nc-gcs/apps/nc_gcs/node_modules"
  export PATH="${NC_GCS_NM}/.bin:${ROOT}/node_modules/.bin:${PATH}"
  (cd "$ROOT" && npm run build)
fi

if command -v composer >/dev/null 2>&1 && [[ -f "$ROOT/composer.json" ]] && [[ ! -f "$ROOT/vendor/autoload.php" ]]; then
  (cd "$ROOT" && composer install --no-dev --optimize-autoloader)
fi

staging="${APP_BASE}/${APP_ID}.staging"
target="${APP_BASE}/${APP_ID}"
docker exec "$CONTAINER" sh -c "rm -rf '${staging}' && mkdir -p '${staging}'"
tar_excludes=(--exclude='./node_modules' --exclude='./.git' --exclude='./tests')
if [[ ! -f "$ROOT/vendor/autoload.php" ]]; then
  tar_excludes+=(--exclude='./vendor')
fi
(cd "$ROOT" && tar "${tar_excludes[@]}" -cf - .) \
  | docker exec -i "$CONTAINER" tar -xf - -C "${staging}/"
docker exec "$CONTAINER" sh -c "rm -rf '${target}' && mv '${staging}' '${target}'"
docker exec "$CONTAINER" chown -R www-data:www-data "${target}"
docker exec -u www-data "$CONTAINER" php occ app:enable "$APP_ID" 2>&1 | sed 's/^/  /'
APP_NAME="$APP_ID" CONTAINER="$CONTAINER" bash "$ROOT/scripts/post-deploy.sh"
echo "Deployed ${APP_ID} → ${CONTAINER}"

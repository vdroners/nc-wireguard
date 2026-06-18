#!/usr/bin/env bash
set -euo pipefail
CONTAINER="${CONTAINER:-cloud_app}"
APP_NAME="${APP_NAME:-nc_wireguard}"
docker exec "$CONTAINER" chown -R www-data:www-data "/var/www/html/custom_apps/${APP_NAME}"
docker exec -u www-data "$CONTAINER" php occ upgrade --no-interaction 2>&1 | sed 's/^/  /'
docker exec -u www-data "$CONTAINER" php occ app:enable "$APP_NAME" 2>&1 | sed 's/^/  /' || true
docker exec "$CONTAINER" php -r 'if (function_exists("opcache_reset")) { opcache_reset(); echo "opcache_reset\n"; }'

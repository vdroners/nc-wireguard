#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SIDECAR="${SIDECAR_URL:-http://127.0.0.1:8185}"
NC_URL="${NC_URL:-https://cloud-vdroners.ddns.net}"
APP="$ROOT"
NC_GCS="/media/4TB/nc-gcs"

echo "=== G0 build ==="
(cd "$APP" && npm run build)

echo "=== G1a sidecar health ==="
curl -sf "$SIDECAR/api/health" | grep -q '"status"'

echo "=== G1 sidecar summary ==="
curl -sf "$SIDECAR/api/summary" | grep -q '"clients"'

echo "=== PHPUnit ==="
if [[ -f "$APP/vendor/bin/phpunit" ]]; then
  (cd "$APP" && vendor/bin/phpunit -c phpunit.xml.dist)
else
  (cd "$APP" && composer install --no-interaction && vendor/bin/phpunit -c phpunit.xml.dist)
fi

echo "=== G0 deploy (optional SKIP_DEPLOY=1) ==="
if [[ "${SKIP_DEPLOY:-0}" != "1" ]]; then
  bash "$APP/scripts/deploy-docker.sh"
  docker exec cloud_app grep '<version>' "/var/www/html/custom_apps/nc_wireguard/appinfo/info.xml"
fi

echo "=== ALL GATES PASSED ==="

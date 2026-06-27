#!/usr/bin/env bash
# Native-backend verification script (post-v2.0). Backs up metrics and runs poll smoke.
set -euo pipefail
CONTAINER="${CONTAINER:-cloud_app}"
APP_SCRIPT_DIR="/var/www/html/custom_apps/nc_wireguard/scripts"

log() { echo "[cutover-native] $*"; }
die() { log "ERROR: $*"; exit 1; }

log "=== Step 1: backup NC metrics tables ==="
/media/4TB/nc-wireguard/scripts/backup-wireguard-metrics.sh

log "=== Step 2: poll smoke ==="
docker exec "$CONTAINER" php occ nc_wireguard:poll-metrics --no-lock 2>/dev/null || \
	docker exec "$CONTAINER" php occ nc_wireguard:poll-metrics

log "=== Step 3: native smoke + status verify ==="
docker exec "$CONTAINER" php "${APP_SCRIPT_DIR}/smoke-native.php"
docker exec "$CONTAINER" php "${APP_SCRIPT_DIR}/verify-status-native.php" 120

log "=== cutover-native complete ==="
log "Install systemd timers: docs/ops/nc-wireguard-poll-metrics.timer + nc-wireguard-prune-metrics.timer"

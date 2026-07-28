#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NC_URL="${NC_URL:-https://cloud-vdroners.ddns.net}"
APP="$ROOT"
CONTAINER="${CONTAINER:-cloud_app}"
APP_SCRIPT_DIR="/var/www/html/custom_apps/nc_wireguard/scripts"

echo "=== G0 build ==="
(cd "$APP" && npm run build)

echo "=== G0b lint ==="
(cd "$APP" && npm run lint)

if ! command -v docker >/dev/null 2>&1 || ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
	echo "ERROR: $CONTAINER not running — native gate requires cloud_app"
	exit 1
fi

echo "=== G1 native backend smoke ==="
docker exec "$CONTAINER" php "${APP_SCRIPT_DIR}/smoke-native.php"

if [[ "${SKIP_POLL_SMOKE:-0}" != "1" ]]; then
	echo "=== G1b poll + verify ==="
	docker exec "$CONTAINER" php occ nc_wireguard:poll-metrics --no-lock 2>/dev/null || \
		docker exec "$CONTAINER" php occ nc_wireguard:poll-metrics
	docker exec "$CONTAINER" php "${APP_SCRIPT_DIR}/verify-status-native.php" 120
fi

echo "=== PHPUnit ==="
if command -v php >/dev/null 2>&1 && [[ -f "$APP/vendor/bin/phpunit" ]]; then
	(cd "$APP" && vendor/bin/phpunit -c phpunit.xml.dist)
elif command -v docker >/dev/null 2>&1; then
	docker run --rm -v "$APP:/app" -w /app composer:2 sh -c 'composer install --no-interaction -q && vendor/bin/phpunit -c phpunit.xml.dist'
else
	echo "SKIP PHPUnit (no php/docker)"
fi

echo "=== G1c schema-check (optional SKIP_SCHEMA=1) ==="
if [[ "${SKIP_SCHEMA:-0}" != "1" ]]; then
	docker exec "$CONTAINER" php occ nc_wireguard:schema-check
fi

echo "=== G0 deploy (optional SKIP_DEPLOY=1) ==="
if [[ "${SKIP_DEPLOY:-0}" != "1" ]]; then
	bash "$APP/scripts/deploy-docker.sh"
	docker exec "$CONTAINER" grep '<version>' "/var/www/html/custom_apps/nc_wireguard/appinfo/info.xml"
fi

# Peer-write + public OTL smoke when engine is reachable (opt-in).
# Default off so CI/hosts without wg-easy credentials still pass the gate.
if [[ "${GATE_PEER_WRITES:-0}" == "1" ]]; then
	echo "=== G2 peer-write + public OTL (GATE_PEER_WRITES=1) ==="
	docker exec "$CONTAINER" php "${APP_SCRIPT_DIR}/smoke-peer-writes.php"
	# Public OTL redeem path (no cookie) — mint via smoke script leftover or skip if unavailable
	if [[ -n "${GATE_OTL_TOKEN:-}" ]]; then
		code=$(curl -sS -o /tmp/nc-wg-otl-body -w '%{http_code}' \
			"${NC_URL%/}/index.php/apps/nc_wireguard/api/peers/otl/${GATE_OTL_TOKEN}" || true)
		echo "public OTL redeem HTTP $code (expect 200 then 410 on replay)"
		[[ "$code" == "200" || "$code" == "410" || "$code" == "429" ]] || {
			echo "ERROR: unexpected OTL HTTP $code" >&2
			exit 1
		}
	else
		echo "SKIP public OTL curl (set GATE_OTL_TOKEN to exercise redeem)"
	fi
fi

echo "=== ALL GATES PASSED ==="
echo ""
echo "Browser matrix (375 / 768 / 1440): manual admin smoke — all five tabs, peer config modal, #bandwidth deep-link."
echo "Peer writes + public OTL: GATE_PEER_WRITES=1 [GATE_OTL_TOKEN=…] make gate-local"
echo "24h poll health: install systemd timers from docs/ops/ (nc-wireguard-poll-metrics.timer, nc-wireguard-prune-metrics.timer)."

#!/usr/bin/env bash
# Backup wg-easy config and NC native metrics tables (nc_wg_*).
set -euo pipefail
WG_ROOT="${WG_ROOT:-/media/4TB/wireguard}"
DEST="${1:-/tmp/wireguard-backup-$(date +%Y%m%d-%H%M%S)}"
CONTAINER="${CONTAINER:-cloud_app}"
DB_CONTAINER="${DB_CONTAINER:-cloud_db}"

mkdir -p "$DEST"

if [[ -f "$WG_ROOT/config/wg-easy.db" ]]; then
	cp -a "$WG_ROOT/config/wg-easy.db" "$DEST/"
fi

# Optional archived sidecar SQLite (pre-v2.0 migration)
if [[ -f "$WG_ROOT/dashboard.archived/data/dashboard.db" ]]; then
	cp -a "$WG_ROOT/dashboard.archived/data/dashboard.db" "$DEST/archived-sidecar-dashboard.db"
fi

# NC native metrics tables
NC_TABLES=(
	nc_wg_bandwidth_log
	nc_wg_connection_log
	nc_wg_geoip_cache
	nc_wg_system_metrics
	nc_wg_poll_state
	nc_wg_metrics_heartbeat
)

if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' | grep -qx "$DB_CONTAINER"; then
	DUMP_BIN=$(docker exec "$DB_CONTAINER" sh -c 'command -v mariadb-dump || command -v mysqldump' 2>/dev/null || true)
	if [[ -n "$DUMP_BIN" ]]; then
		docker exec "$DB_CONTAINER" sh -c \
			'mariadb-dump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" \
			nc_wg_bandwidth_log nc_wg_connection_log nc_wg_geoip_cache \
			nc_wg_system_metrics nc_wg_poll_state nc_wg_metrics_heartbeat' \
			> "$DEST/nc_wireguard_metrics.sql" 2>/dev/null || true
		if [[ -s "$DEST/nc_wireguard_metrics.sql" ]]; then
			echo "NC metrics dump: $(wc -l < "$DEST/nc_wireguard_metrics.sql") lines"
		else
			rm -f "$DEST/nc_wireguard_metrics.sql"
			echo "WARN: NC metrics mysqldump empty or failed"
		fi
	fi
else
	echo "WARN: $DB_CONTAINER not running — skipping NC metrics dump"
fi

# App config snapshot (poll settings)
if docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
	docker exec "$CONTAINER" php occ config:list nc_wireguard --private 2>/dev/null \
		> "$DEST/nc_wireguard_occ_config.json" || true
fi

tar -czf "${DEST}.tar.gz" -C "$(dirname "$DEST")" "$(basename "$DEST")"
echo "Backup: ${DEST}.tar.gz"

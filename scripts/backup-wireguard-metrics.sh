#!/usr/bin/env bash
# Backup wg-dashboard SQLite + wg-easy config
set -euo pipefail
WG_ROOT="${WG_ROOT:-/media/4TB/wireguard}"
DEST="${1:-/tmp/wireguard-backup-$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$DEST"
cp -a "$WG_ROOT/dashboard/data/dashboard.db" "$DEST/" 2>/dev/null || true
cp -a "$WG_ROOT/config/wg-easy.db" "$DEST/" 2>/dev/null || true
tar -czf "${DEST}.tar.gz" -C "$(dirname "$DEST")" "$(basename "$DEST")"
echo "Backup: ${DEST}.tar.gz"

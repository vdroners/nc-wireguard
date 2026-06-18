#!/usr/bin/env bash
# Sync nc_gcs src mirror for NcGcsAppShell + theme bootstrap.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="/media/4TB/nc-gcs/apps/nc_gcs/src"
DEST="$ROOT/src/_nc_gcs_src_mirror"
if [[ ! -d "$SRC" ]]; then
  echo "ERROR: nc_gcs src not found at $SRC" >&2
  exit 1
fi
rsync -a --delete "$SRC/" "$DEST/"
echo "Synced full nc_gcs src mirror to $DEST"

#!/usr/bin/env bash
# export-peers.sh — secret-aware peer inventory from live wg-easy.
#
# List endpoints omit private keys; this script uses get-one + .conf download.
# Writes under /media/4TB/wireguard/exports/ (NOT git): dir 0700, files 0600.
#
# Usage:
#   WG_EASY_URL=http://127.0.0.1:51821 WG_EASY_USER=admin WG_EASY_PASS=... \
#     bash scripts/export-peers.sh
# Or from cloud_app network:
#   docker exec cloud_app ... (prefer host with loopback 51821 + SSH)

set -euo pipefail

EXPORT_ROOT="${EXPORT_ROOT:-/media/4TB/wireguard/exports}"
WG_EASY_URL="${WG_EASY_URL:-http://127.0.0.1:51821}"
WG_EASY_USER="${WG_EASY_USER:-admin}"
COOKIE_JAR="$(mktemp)"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT_DIR="${EXPORT_ROOT}/${STAMP}"

cleanup() { rm -f "$COOKIE_JAR"; }
trap cleanup EXIT

if [[ -z "${WG_EASY_PASS:-}" ]]; then
  echo "ERROR: set WG_EASY_PASS (do not pass on argv — it lands in shell history)." >&2
  exit 2
fi

mkdir -p "$EXPORT_ROOT"
chmod 700 "$EXPORT_ROOT"
mkdir -p "$OUT_DIR/peers" "$OUT_DIR/conf"
chmod 700 "$OUT_DIR"

echo "=== Login ${WG_EASY_URL} ==="
login_code=$(curl -sS -o /tmp/wg-export-login.json -w '%{http_code}' \
  -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -H 'Content-Type: application/json' \
  -d "{\"username\":\"${WG_EASY_USER}\",\"password\":\"${WG_EASY_PASS}\",\"remember\":true}" \
  "${WG_EASY_URL%/}/api/session")
chmod 600 /tmp/wg-export-login.json 2>/dev/null || true
if [[ "$login_code" != "200" ]]; then
  echo "ERROR: login HTTP $login_code" >&2
  cat /tmp/wg-export-login.json >&2 || true
  exit 1
fi

echo "=== List clients ==="
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  "${WG_EASY_URL%/}/api/client" -o "$OUT_DIR/clients-list.json"
chmod 600 "$OUT_DIR/clients-list.json"

# Extract integer ids (jq preferred; python fallback)
mapfile -t IDS < <(python3 - <<'PY' "$OUT_DIR/clients-list.json"
import json,sys
data=json.load(open(sys.argv[1]))
for c in data:
    if isinstance(c, dict) and c.get("id") is not None:
        print(int(c["id"]))
PY
)

echo "Found ${#IDS[@]} peers"
MANIFEST="$OUT_DIR/manifest.jsonl"
: > "$MANIFEST"
chmod 600 "$MANIFEST"

for id in "${IDS[@]}"; do
  echo "--- peer $id (get-one + conf) ---"
  curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "${WG_EASY_URL%/}/api/client/${id}" -o "$OUT_DIR/peers/${id}.json"
  chmod 600 "$OUT_DIR/peers/${id}.json"

  conf_code=$(curl -sS -o "$OUT_DIR/conf/${id}.conf" -w '%{http_code}' \
    -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "${WG_EASY_URL%/}/api/client/${id}/configuration" || true)
  if [[ "$conf_code" != "200" ]]; then
    curl -sS -o "$OUT_DIR/conf/${id}.conf" -w '' \
      -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
      "${WG_EASY_URL%/}/api/wireguard/client/${id}/configuration" || true
  fi
  chmod 600 "$OUT_DIR/conf/${id}.conf" 2>/dev/null || true

  python3 - <<'PY' "$OUT_DIR/peers/${id}.json" "$MANIFEST"
import json,sys
peer=json.load(open(sys.argv[1]))
row={
  "id": peer.get("id"),
  "name": peer.get("name"),
  "publicKey": peer.get("publicKey") or peer.get("public_key"),
  "enabled": peer.get("enabled"),
  "ipv4Address": peer.get("ipv4Address") or peer.get("ipv4"),
  "persistentKeepalive": peer.get("persistentKeepalive"),
  "hasPrivateKey": bool(peer.get("privateKey") or peer.get("private_key")),
  "hasPresharedKey": bool(peer.get("preSharedKey") or peer.get("presharedKey")),
}
with open(sys.argv[2],"a") as f:
    f.write(json.dumps(row)+"\n")
PY
done

# Capture PostUp + sysctls next to this export (verbatim, keys redacted from conf dump)
{
  echo "# Captured with export $STAMP"
  echo "## Compose sysctls"
  grep -A5 'sysctls:' /media/4TB/wireguard/docker-compose.yml 2>/dev/null || true
  echo
  echo "## Live PostUp/PostDown (redacted)"
  if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx wg-easy; then
    docker exec wg-easy sh -c 'grep -E "^(PostUp|PostDown|Address|ListenPort|MTU)" /etc/wireguard/wg0.conf' \
      | sed -E 's/(PrivateKey|PresharedKey).*/\1 = <REDACTED>/'
  fi
  echo
  echo "## Host sysctl"
  sysctl net.ipv4.ip_forward net.ipv6.conf.all.forwarding net.ipv4.conf.all.src_valid_mark 2>/dev/null || true
  echo
  echo "## wg-easy.db mode (chmod 600 recommended before cutover)"
  ls -la /media/4TB/wireguard/config/wg-easy.db 2>/dev/null \
    || ls -la /media/4TB/wireguard/config/*.db 2>/dev/null || true
} > "$OUT_DIR/engine-nat-sysctl.md"
chmod 600 "$OUT_DIR/engine-nat-sysctl.md"

# Lock down tree
find "$OUT_DIR" -type d -exec chmod 700 {} \;
find "$OUT_DIR" -type f -exec chmod 600 {} \;

echo
echo "Export complete: $OUT_DIR"
echo "Files are mode 0600 under dir 0700. Do NOT commit this tree."
echo "Runbook: backup before any engine work — see docs/ops/PEER_EXPORT.md"

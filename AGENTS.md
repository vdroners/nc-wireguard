# AGENTS.md — NC WireGuard

Standalone repo: `/media/4TB/nc-wireguard` (GitHub: `vdroners/nc-wireguard`).

## Commands

```bash
make sync-theme build deploy-docker
make health gate-local
curl -s http://127.0.0.1:8185/api/health
```

## Sidecar stack

`/media/4TB/wireguard/` — `wg-easy` + `wg-dashboard`. Not the same as `gcs_vpn_manager` in nc-gcs.

## Deploy target

`cloud_app` container at `/var/www/html/custom_apps/nc_wireguard/`.

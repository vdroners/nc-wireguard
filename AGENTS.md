# NC WireGuard — Agent quick reference

Admin-only Nextcloud app with **native** wg-easy metrics poller and Vue dashboard. **Not** `gcs_vpn_manager` (host outbound VPN in nc-gcs).

## Commands

```bash
cd /media/4TB/nc-wireguard
make build deploy-docker
make health          # poll + native smoke
make gate-local      # full gate (no sidecar)
```

## Health checks

```bash
docker exec cloud_app php occ nc_wireguard:poll-metrics --no-lock
docker exec cloud_app php /var/www/html/custom_apps/nc_wireguard/scripts/smoke-native.php
docker exec cloud_app curl -sf http://wg-easy:51821/api/client
```

## Env overrides (gate-local)

| Var | Purpose |
|-----|---------|
| `CONTAINER` | Nextcloud container (default `cloud_app`) |
| `SKIP_DEPLOY=1` | Skip deploy step in gate |
| `SKIP_POLL_SMOKE=1` | Skip poll verify in gate |

wg-easy from inside `cloud_app`: **`http://wg-easy:51821`** on network `wireguard_default`.

Systemd timers: `docs/ops/nc-wireguard-poll-metrics.timer`, `docs/ops/nc-wireguard-prune-metrics.timer`.

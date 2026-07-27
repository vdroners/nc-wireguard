# NC WireGuard — Agent quick reference

Admin-only Nextcloud **peer controller** + native wg-easy metrics poller and Vue
dashboard (v2.2). **Not** `gcs_vpn_manager` (host outbound VPN in nc-gcs).

## Commands

```bash
cd /media/4TB/nc-wireguard
make build deploy-docker
make health          # poll + native smoke
make test            # PHPUnit
make gate-local      # full gate (no sidecar)
```

Peer write smoke (after deploy):

```bash
docker exec -u www-data cloud_app php \
  /var/www/html/custom_apps/nc_wireguard/scripts/smoke-peer-writes.php
```

Public OTL (no NC cookie): mint via UI or smoke, then

```bash
curl -sf "http://10.0.0.84:8080/index.php/apps/nc_wireguard/api/peers/otl/<token>" -o peer.conf
# second fetch of the same token must fail (410 already_redeemed)
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
Engine admin UI should stay on **loopback only** (`127.0.0.1:51821`).

Systemd timers: `docs/ops/nc-wireguard-poll-metrics.timer`, `docs/ops/nc-wireguard-prune-metrics.timer`.

Docs: `README.md`, `docs/OPERATOR_RUNBOOK.md`, `docs/API_PARITY.md`,
`.cursor/plans/wg-2.2-gap-fill.md`.

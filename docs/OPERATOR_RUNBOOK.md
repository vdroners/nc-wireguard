# NC WireGuard — Operator Runbook

## Native backend (v2.0+)

All dashboard data is stored in Nextcloud MySQL (`nc_wg_*` tables) and refreshed by the metrics poller. There is **no** wg-dashboard sidecar dependency.

## wg-easy reachability

`cloud_app` must be on the `wireguard_default` Docker network (persisted in `/media/4TB/cloud/docker-compose.yml`).

Default internal wg-easy URL: `http://wg-easy:51821`.

If poll or summary fails after a cloud stack recreate:

```bash
docker exec cloud_app curl -sf http://wg-easy:51821/api/client
docker network connect wireguard_default cloud_app   # only if missing
```

## Metrics poller (systemd)

Install and enable the poll + prune timers shipped in this repo:

```bash
sudo cp /media/4TB/nc-wireguard/docs/ops/nc-wireguard-poll-metrics.{service,timer} /etc/systemd/system/
sudo cp /media/4TB/nc-wireguard/docs/ops/nc-wireguard-prune-metrics.{service,timer} /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now nc-wireguard-poll-metrics.timer nc-wireguard-prune-metrics.timer
```

Verify 24h poll health:

```bash
systemctl list-timers 'nc-wireguard-*'
docker exec cloud_app php occ nc_wireguard:poll-metrics --no-lock
docker exec cloud_app php /var/www/html/custom_apps/nc_wireguard/scripts/verify-status-native.php 120
```

Poll interval and retention are configured in **Nextcloud → Settings → Administration → NC WireGuard**.

## Host system metrics

Mount read-only host `/proc` into `cloud_app` for CPU/memory/network charts on the System tab. See [docs/ops/host-proc-mount.md](ops/host-proc-mount.md).

## Deploy / upgrade app

```bash
cd /media/4TB/nc-wireguard
make gate-local
# or deploy only:
make deploy-docker
docker exec cloud_app grep '<version>' /var/www/html/custom_apps/nc_wireguard/appinfo/info.xml
```

## Backup (cron example)

```cron
0 3 * * * /media/4TB/nc-wireguard/scripts/backup-wireguard-metrics.sh /var/backups/wireguard
```

Backs up `nc_wg_*` MySQL tables, wg-easy DB, and `occ config:list` snapshot.

## One-time migration import

If upgrading from pre-v2.0 sidecar SQLite:

```bash
docker exec cloud_app php occ nc_wireguard:import-sidecar-db /path/to/dashboard.db
docker exec cloud_app php occ nc_wireguard:verify-import /path/to/dashboard.db
```

Archived sidecar source lives at `/media/4TB/wireguard/dashboard.archived/` (not deployed).

## Sign-off checklist

- [x] Native backend only (v2.0.0)
- [x] wg-dashboard sidecar archived / removed from compose
- [x] `cloud_app` on `wireguard_default`
- [ ] systemd poll + prune timers enabled
- [ ] Admin browser smoke: all 5 tabs + mobile More menu + peer config modal (375 / 768 / 1440 px)
- [ ] Deep-link `#bandwidth` — client filter populated without visiting Overview first
- [ ] 24h poll health verified (`verify-status-native.php`)

## URLs reference

| Surface | URL |
|---------|-----|
| NC dashboard | `https://cloud-vdroners.ddns.net/apps/nc_wireguard/` |
| wg-easy admin | `https://vpn-vdroners.ddns.net/` |

# NC WireGuard — Operator Runbook

## Native backend (v2.0+)

All dashboard data is stored in Nextcloud MySQL (`nc_wg_*` tables) and refreshed by the metrics poller. There is **no** wg-dashboard sidecar dependency.

## Peer controller (v2.1+)

Nextcloud is the operator UI for peer management. Create, edit, enable/disable
and delete peers from the Overview tab; wg-easy remains the WireGuard engine but
its own admin UI is not needed for day-to-day work.

### wg-easy service account: 2FA must stay OFF

wg-easy answers an API login with **HTTP 200** and `{"status":"TOTP_REQUIRED"}`
when the account has 2FA enabled, and offers no non-interactive TOTP path. A
2FA-enabled account therefore cannot hold an API session, and every peer write
fails with `reason: totp_required`.

Keep 2FA off for the account configured in **Settings → Administration → NC
WireGuard**, and use a separate human account if you need 2FA on the wg-easy UI.
Confirm with the admin **Test wg-easy** button — it now names this condition
explicitly instead of reporting a generic client-list failure.

### One-time links

**Generate OTL** in the peer config modal mints a single-use config link. The
engine expires it about **5 minutes** after generation, and redeeming it (either
path) erases it immediately, so mint it at the moment you need it.

The returned **NC redeem URL** (`/apps/nc_wireguard/api/peers/otl/{token}`) runs
through the same admin gate as the other write routes. That makes it a
convenience for *you* — it pulls the config through Nextcloud while the engine UI
stays unpublished — but it is **not** a link a non-admin recipient can open.

To hand a config to a field user, use the **Download .conf** button or the QR in
the same modal and send that. Only the engine's own `/cnf/{token}` route is
unauthenticated, and after cutover it is reachable on the Docker network only.

### Hiding the wg-easy deep links

`hide_wg_easy_admin_link` defaults to **on** from v2.1, so the dashboard shows no
"open wg-easy" links. Turn it off in admin settings if you still need the engine
UI during cutover.

### Verifying peer writes

Peer writes are session- and CSRF-protected. Plain `curl` against `/api/peers`
returns `401 {"message":"Current user is not logged in"}`, and a logged-in
request without a request token returns 412 — both are the guards working, not a
broken route. To
exercise the real write contract, run the service-layer smoke instead. It creates
a temporary `zz-nc-smoke-*` peer, drives create → update → disable → enable →
one-time link → configuration → redeem, and always deletes the peer again:

```bash
docker exec -u www-data cloud_app php \
  /var/www/html/custom_apps/nc_wireguard/scripts/smoke-peer-writes.php
```

Every line must read `OK` and the last line `smoke-peer-writes OK`. A
`login FAIL` naming `TOTP_REQUIRED` means 2FA got enabled on the service
account.

Audit trail for every peer change (actor UID, action, client id, HTTP code):

```bash
docker exec cloud_app grep nc_wireguard /var/www/html/data/nextcloud.log | tail -20
```

Pin the engine image (`ghcr.io/wg-easy/wg-easy:15`) and re-smoke create / update
/ delete after any wg-easy upgrade — the write contract is version-sensitive. See
the verified contract table in [API_PARITY.md](API_PARITY.md).

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
- [ ] wg-easy service account has 2FA off (admin **Test wg-easy** passes)
- [ ] Peer CRUD smoke against a disposable peer: create → edit → disable → enable → delete
- [ ] Peer writes rejected without a CSRF token (expect HTTP 412)
- [ ] wg-easy `:51821` and Caddy admin UI unpublished after CRUD smoke passes
- [ ] Deep-link `#bandwidth` — client filter populated without visiting Overview first
- [ ] 24h poll health verified (`verify-status-native.php`)

## URLs reference

| Surface | URL |
|---------|-----|
| NC dashboard | `https://cloud-vdroners.ddns.net/apps/nc_wireguard/` |
| wg-easy admin | `https://vpn-vdroners.ddns.net/` |

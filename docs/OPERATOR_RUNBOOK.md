# NC WireGuard — Operator Runbook

## Sidecar reachability

`cloud_app` is on the `wireguard_default` Docker network (persisted in `/media/4TB/cloud/docker-compose.yml`).

Default NC app internal URL: `http://wg-dashboard:8185`.

If sidecar health fails after a cloud stack recreate:

```bash
docker exec cloud_app curl -sf http://wg-dashboard:8185/api/health
```

## Backup (cron example)

```cron
0 3 * * * /media/4TB/nc-wireguard/scripts/backup-wireguard-metrics.sh /var/backups/wireguard
```

## Cutover status (2026-06-18)

- Caddy proxy host **id=6** (`vpn-vdroners.ddns.net`): `/dashboard/*` location rule **removed**
- `wg-dashboard` publishes **host loopback only**: `127.0.0.1:8185`
- Primary operator UI: `https://cloud-vdroners.ddns.net/apps/nc_wireguard/` (admin-only)

Public `https://vpn-vdroners.ddns.net/dashboard/` now falls through to wg-easy (302 → `/login`), not the legacy sidecar UI.

## Rollback

If NC WireGuard fails after cutover:

1. Re-add Caddy location `/dashboard/*` → `wg-dashboard:8185`
2. In `/media/4TB/wireguard/docker-compose.yml` change ports to `10.0.0.84:8185:8185` if LAN access needed
3. `docker compose -f /media/4TB/wireguard/docker-compose.yml up -d wg-dashboard`
4. Verify `https://vpn-vdroners.ddns.net/dashboard/` serves sidecar again

## Sign-off checklist

- [x] Sidecar bound to `127.0.0.1:8185` on host
- [x] Public `/dashboard` removed from Caddy
- [x] `cloud_app` on `wireguard_default`
- [ ] Admin browser smoke: all 5 tabs + peer config modal
- [ ] `make gate-local` passes on build host

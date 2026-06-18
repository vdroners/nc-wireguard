# NC WireGuard — Operator Runbook

## Sidecar reachability

`cloud_app` must be on the `wireguard_default` Docker network:

```bash
docker network connect wireguard_default cloud_app
```

Default NC app internal URL: `http://wg-dashboard:8185`.

```cron
0 3 * * * /media/4TB/nc-wireguard/scripts/backup-wireguard-metrics.sh /var/backups/wireguard
```

## Caddy `/dashboard` removal (post NC app verification)

1. Open Caddy Proxy Manager UI (`10.0.0.84:3080`)
2. Edit proxy host **WireGuard VPN** (id=6)
3. Remove location rule `/dashboard/*` → `wg-dashboard:8185`
4. Save and reload

## Rollback

If NC WireGuard fails after cutover:

1. Re-add Caddy location `/dashboard/*` → `wg-dashboard:8185` (or `10.0.0.84:8185` if not on Docker network)
2. In `/media/4TB/wireguard/docker-compose.yml` change ports to `10.0.0.84:8185:8185`
3. `docker compose up -d wg-dashboard`
4. Verify `https://vpn-vdroners.ddns.net/dashboard/`

## Sign-off checklist

- [ ] Admin can open `/apps/nc_wireguard/` and all 5 tabs load
- [ ] Overview polls every 15s
- [ ] Peer config modal works
- [ ] `make gate-local` passes
- [ ] Sidecar bound to `127.0.0.1:8185` only
- [ ] Public `/dashboard` removed from Caddy

# NC WireGuard

Nextcloud app for monitoring the **wg-easy VPN server** via the `wg-dashboard` sidecar.

## URLs

| Surface | URL |
|---------|-----|
| NC app (primary) | `https://cloud-vdroners.ddns.net/apps/nc_wireguard/` |
| wg-easy admin | `https://vpn-vdroners.ddns.net/` |
| Sidecar (localhost only post-cutover) | `http://127.0.0.1:8185/` |

## Requirements

- Nextcloud 28–33
- `nc_gcs` enabled (for `ThemeAssetLoader` / shared shell CSS)
- `wg-dashboard` sidecar running (`/media/4TB/wireguard`)
- **Nextcloud administrators only**

## Sidecar reachability

`cloud_app` must reach `wg-dashboard` on the Docker network:

```bash
docker network connect wireguard_default cloud_app
```

Default internal URL: `http://wg-dashboard:8185` (not host loopback — sidecar publishes `127.0.0.1:8185` on the **host** only).

```bash
cd /media/4TB/nc-wireguard
make sync-theme
npm ci
make deploy-docker
```

## Version

| File | Version |
|------|---------|
| `appinfo/info.xml` | 1.0.0 |
| `package.json` | 1.0.0 |

## Related

- Host outbound VPN: NC-GCS **VPN Manager** (`gcs_vpn_manager` on :8190) — different product.

# NC WireGuard

[![Version](https://img.shields.io/badge/version-2.1.0-blue)](appinfo/info.xml)

Nextcloud app for monitoring a **wg-easy** VPN server with a native metrics poller. **Admin-only** access; optional NC-GCS theming when that app is installed; works standalone without NC-GCS.

**Not affiliated with or endorsed by WireGuard or wg-easy.** WireGuard is a registered trademark of Jason A. Donenfeld.

**Not** the same product as NC-GCS **VPN Manager** (`gcs_vpn_manager`), which manages host outbound VPN profiles.

## Quick start

1. Install and enable the app from the Nextcloud App Store or a signed release tarball.
2. Open **Settings → Administration → NC WireGuard**.
3. Set the **wg-easy API URL** (internal, reachable from the Nextcloud server), username, and password.
4. Optionally set the **wg-easy admin URL** (external link shown in the dashboard banner).
5. Run the poller on a schedule, e.g. `occ nc_wireguard:poll-metrics` (see `docs/ops/` for systemd units).

Hash routes: `#overview`, `#bandwidth`, `#connections`, `#map`, `#system`.

## Configuration

| Setting | Default | Notes |
|---------|---------|-------|
| Dashboard enabled | on | Master switch for the SPA |
| wg-easy API URL | *(empty)* | Internal URL for poller — configure before use |
| wg-easy admin URL | *(empty)* | External UI link in banner |
| Poll interval | 30 s | 10–300 |
| Retention | 30 days | Metrics prune |
| GeoIP | **off** | Opt-in; sends peer public IPs to configured provider |

## Architecture (short)

```
Browser (Vue SPA) → NC PHP API → DB metrics tables + WgEasyClient
                              ↑
                    occ nc_wireguard:poll-metrics (timer/cron)
```

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for module layout and data flow.

## Development

```bash
npm ci && npm run build
composer install && vendor/bin/phpunit
make deploy-docker   # optional: lab Nextcloud container
```

## App Store publication

See [docs/APPSTORE_ONBOARDING.md](docs/APPSTORE_ONBOARDING.md) for signing, release automation, and store listing steps.

## Licence

AGPL-3.0-or-later — see [LICENSE](LICENSE).

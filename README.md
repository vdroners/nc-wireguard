# NC WireGuard

[![Version](https://img.shields.io/badge/version-2.3.3-blue)](appinfo/info.xml)

Nextcloud **peer controller and metrics dashboard** for a **wg-easy** WireGuard
engine. Admin-only SPA: create/edit/enable/disable peers, Field/Admin presets,
shareable one-time config links, bandwidth/connection history, optional GeoIP
map, and host metrics. Optional NC-GCS theming when that app is installed;
works standalone without NC-GCS.

**Not affiliated with or endorsed by WireGuard or wg-easy.** WireGuard is a
registered trademark of Jason A. Donenfeld.

**Not** the same product as NC-GCS **VPN Manager** (`gcs_vpn_manager`), which
manages host outbound VPN profiles (ZeroTier / client tunnels).

## What 2.2 provides

| Area | Notes |
|------|--------|
| Peer CRUD | Overview tab — create, edit, enable/disable, delete |
| Presets | Field (`10.0.0.0/24,10.8.0.0/24`, keepalive 25) and Admin full-tunnel |
| Bulk Field policy | Multi-select peers → Apply Field preset (skips peer named `Server`) |
| Config handoff | `.conf` download, QR, **public** one-time NC redeem URL (~5 min, single-use) |
| Server card | System tab — read-only wg-easy interface/CIDR/MTU (no write in 2.2) |
| Engine UI | Keep unpublished (`127.0.0.1:51821`); NC owns day-to-day peer control |

Deferred (break-glass engine UI only): server defaults write, hooks, restart,
Amnezia. Portainer has no WireGuard surface on the lab host.

## Quick start

Stranger / App Store path: **[INSTALL.md](INSTALL.md)** — upstream
`ghcr.io/wg-easy/wg-easy:15`, admin engine URL, optional wg-sync note.

1. Install and enable the app from the Nextcloud App Store or a signed release tarball.
2. Open **Settings → Administration → NC WireGuard**.
3. Set the **Engine API URL** (reachable from the Nextcloud server), username, and password.
4. Keep the engine service account **without 2FA** (API sessions cannot use TOTP).
5. Leave **Hide engine admin link** on after cutover; set an admin URL only for break-glass.
6. Run the poller on a schedule, e.g. `occ nc_wireguard:poll-metrics`.

Hash routes: `#overview`, `#bandwidth`, `#connections`, `#map`, `#system`.

Operator details: [docs/OPERATOR_RUNBOOK.md](docs/OPERATOR_RUNBOOK.md).  
API map: [docs/API_PARITY.md](docs/API_PARITY.md).

## Configuration

| Setting | Default | Notes |
|---------|---------|-------|
| Dashboard enabled | on | Master switch for the SPA + public OTL redeem |
| wg-easy API URL | *(empty)* | Internal URL for poller / writes — configure before use |
| wg-easy admin URL | *(empty)* | Optional external UI link (hidden when hide-link is on) |
| Hide wg-easy admin link | **on** | Prefer NC as the peer controller |
| Poll interval | 30 s | 10–300 |
| Retention | 30 days | Metrics prune |
| GeoIP | **off** | Opt-in; sends peer public IPs to configured provider |

## Architecture (short)

```
Browser (Vue SPA) → NC PHP API → DB metrics tables + WgEasyClient → wg-easy
                              ↑
                    occ nc_wireguard:poll-metrics (timer/cron)

Public OTL: GET /apps/nc_wireguard/api/peers/otl/{token}
  → rate-limited → WgEasyClient /cnf/{token} (single-use)
```

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for module layout and data flow.

## Development

```bash
npm ci && npm run build
make test            # PHPUnit (composer Docker fallback if no host PHP)
make deploy-docker   # lab Nextcloud container
make health          # poll + native smoke
```

Peer write smoke (live engine):

```bash
docker exec -u www-data cloud_app php \
  /var/www/html/custom_apps/nc_wireguard/scripts/smoke-peer-writes.php
```

More: [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md).

## App Store publication

See [docs/APPSTORE_ONBOARDING.md](docs/APPSTORE_ONBOARDING.md) for signing,
release automation, and store listing steps. L10n scaffolding and Psalm baseline
notes: [docs/STATIC_ANALYSIS.md](docs/STATIC_ANALYSIS.md) (full Vue `t()` wrapping
is follow-up).

## Licence

AGPL-3.0-or-later — see [LICENSE](LICENSE).

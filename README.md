# NC WireGuard

[![Version](https://img.shields.io/badge/version-2.0.0-blue)](appinfo/info.xml)

Nextcloud app for monitoring the **wg-easy VPN server** with a native metrics poller. Admin-only access; NC-GCS theming; five-tab Vue dashboard (Overview, Bandwidth, Connections, Map, System).

**Not** the same product as NC-GCS **VPN Manager** (`gcs_vpn_manager` on :8190), which manages host outbound VPN profiles.

## URLs

| Surface | URL | Access |
|---------|-----|--------|
| NC app (primary) | `https://cloud-vdroners.ddns.net/apps/nc_wireguard/` | Nextcloud admins |
| wg-easy admin | `https://vpn-vdroners.ddns.net/` | wg-easy credentials |

Hash routes: `#overview`, `#bandwidth`, `#connections`, `#map`, `#system`.

## Architecture (short)

```
Browser (Vue SPA) → NC PHP API → MySQL metrics tables + WgEasyClient
                              ↑
                    occ nc_wireguard:poll-metrics (systemd timer)
```

- **Frontend:** Vue 2.7 SPA in `src/`; shared summary polling via `useDashboardSummary.js`.
- **Backend:** PHP controllers serve dashboard routes from NC DB; wg-easy session for live peers and config.
- **Stack:** `/media/4TB/wireguard/` (`wg-easy` only; legacy sidecar archived in v2.0).

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for module layout and data flow.

## Requirements

| Component | Requirement |
|-----------|-------------|
| Nextcloud | 28–33 |
| NC-GCS | Enabled (`ThemeAssetLoader` + shared shell CSS) |
| wg-easy | Reachable from `cloud_app` on `wireguard_default` |
| Host metrics | Read-only `/proc` mount on `cloud_app` (see `docs/ops/host-proc-mount.md`) |
| Poller | systemd timer or cron for `occ nc_wireguard:poll-metrics` |
| Access | Nextcloud **administrators** only |

## Quick start (operators)

```bash
cd /media/4TB/nc-wireguard
make sync-theme build deploy-docker
make health
make gate-local
```

Install systemd timers (see [docs/OPERATOR_RUNBOOK.md](docs/OPERATOR_RUNBOOK.md)):

```bash
sudo cp docs/ops/nc-wireguard-poll-metrics.{service,timer} /etc/systemd/system/
sudo cp docs/ops/nc-wireguard-prune-metrics.{service,timer} /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now nc-wireguard-poll-metrics.timer nc-wireguard-prune-metrics.timer
```

Verify in browser: all five tabs, peer config modal, client filter on `#bandwidth` deep-link.

## Quick start (developers)

```bash
cd /media/4TB/nc-wireguard
make sync-theme          # refresh NC-GCS shell mirror + theme CSS
npm ci
npm run dev              # webpack watch (optional)
make build lint test
```

See [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) for file layout, conventions, and release steps.

## Makefile targets

| Target | Purpose |
|--------|---------|
| `make sync-theme` | Copy NC-GCS theme/shell assets into build inputs |
| `make build` | SCSS + webpack production bundle |
| `make deploy-docker` | Deploy into `cloud_app` at `/var/www/html/custom_apps/nc_wireguard/` |
| `make health` | Run poll + native smoke inside `cloud_app` |
| `make gate-local` | Build + native smoke + poll verify + PHPUnit + deploy |
| `make lint` | ESLint on app `src/` (excludes `_nc_gcs_src_mirror`) |
| `make test` | PHPUnit unit tests |
| `make bump-patch` / `make bump-minor` / `make bump-major` | Version bump |

## Configuration

Admin settings: **Nextcloud → Settings → Administration → NC WireGuard**

| Setting | Default | Notes |
|---------|---------|-------|
| Dashboard enabled | on | When off, UI shows disable message |
| wg-easy API URL | `http://wg-easy:51821` | Internal URL for poller |
| wg-easy admin URL | `https://vpn-vdroners.ddns.net/` | External link in banner |
| Poll interval | 30 s | 10–300 s |
| Retention | 30 days | Pruned by `occ nc_wireguard:prune-metrics` |

## Documentation

| Doc | Audience |
|-----|----------|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Developers — modules, polling, routes |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | Developers — build, test, version, commit |
| [docs/API_PARITY.md](docs/API_PARITY.md) | Integrators — dashboard API shapes |
| [docs/OPERATOR_RUNBOOK.md](docs/OPERATOR_RUNBOOK.md) | Operators — timers, backup, smoke |
| [AGENTS.md](AGENTS.md) | Cursor/AI agents — commands and boundaries |
| [CHANGELOG.md](CHANGELOG.md) | Release history |

## Version

| File | Version |
|------|---------|
| `appinfo/info.xml` | 2.0.0 |
| `package.json` | 2.0.0 |

## Related repos

| Path / repo | Role |
|-------------|------|
| [`vdroners/nc-wireguard`](https://github.com/vdroners/nc-wireguard) | This app |
| [`vdroners/NC-GCS`](https://github.com/vdroners/NC-GCS) | Theme shell, admin link in VPN settings |
| `/media/4TB/wireguard` | wg-easy compose (sidecar archived v2.0) |

## License

AGPL-3.0-or-later

# NC WireGuard

[![Version](https://img.shields.io/badge/version-1.1.0-blue)](appinfo/info.xml)

Nextcloud app for monitoring the **wg-easy VPN server** via the `wg-dashboard` sidecar. Admin-only access; NC-GCS theming; five-tab Vue dashboard (Overview, Bandwidth, Connections, Map, System).

**Not** the same product as NC-GCS **VPN Manager** (`gcs_vpn_manager` on :8190), which manages host outbound VPN profiles.

## URLs

| Surface | URL | Access |
|---------|-----|--------|
| NC app (primary) | `https://cloud-vdroners.ddns.net/apps/nc_wireguard/` | Nextcloud admins |
| wg-easy admin | `https://vpn-vdroners.ddns.net/` | wg-easy credentials |
| Sidecar (host loopback) | `http://127.0.0.1:8185/` | localhost only (post-cutover) |

Hash routes: `#overview`, `#bandwidth`, `#connections`, `#map`, `#system`.

## Architecture (short)

```
Browser (Vue SPA) → NC PHP proxy → wg-dashboard sidecar → wg-easy API + SQLite metrics
```

- **Frontend:** Vue 2.7 SPA in `src/`; shared summary polling via `useDashboardSummary.js`.
- **Backend:** PHP controllers proxy read-only sidecar paths; admin gate on every API call.
- **Sidecar stack:** `/media/4TB/wireguard/` (`wg-easy` + `wg-dashboard`).

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for module layout and data flow.

## Requirements

| Component | Requirement |
|-----------|-------------|
| Nextcloud | 28–33 |
| NC-GCS | Enabled (`ThemeAssetLoader` + shared shell CSS) |
| Sidecar | `wg-dashboard` on Docker network `wireguard_default` |
| Access | Nextcloud **administrators** only |

`cloud_app` must reach the sidecar at **`http://wg-dashboard:8185`** (configured in `/media/4TB/cloud/docker-compose.yml`).

## Quick start (operators)

```bash
cd /media/4TB/nc-wireguard
make sync-theme build deploy-docker
make health
make gate-local
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
| `make health` | Curl sidecar `/api/health` and `/api/summary` |
| `make gate-local` | Build + sidecar smoke + PHPUnit + deploy |
| `make lint` | ESLint on app `src/` (excludes `_nc_gcs_src_mirror`) |
| `make test` | PHPUnit unit tests |
| `make bump-patch` / `make bump-minor` | Version bump (`info.xml`, `package.json`, `CHANGELOG.md`) |

## Configuration

Admin settings: **Nextcloud → Settings → Administration → NC WireGuard**

| Setting | Default | Notes |
|---------|---------|-------|
| Dashboard enabled | on | When off, UI shows disable message |
| Sidecar base URL | `http://wg-dashboard:8185` | Must be reachable from `cloud_app` |
| wg-easy admin URL | `https://vpn-vdroners.ddns.net/` | External link in banner + config modal |

## Documentation

| Doc | Audience |
|-----|----------|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Developers — modules, polling, proxy routes |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | Developers — build, test, version, commit |
| [docs/API_PARITY.md](docs/API_PARITY.md) | Integrators — sidecar ↔ NC proxy path map |
| [docs/OPERATOR_RUNBOOK.md](docs/OPERATOR_RUNBOOK.md) | Operators — cutover, backup, rollback |
| [AGENTS.md](AGENTS.md) | Cursor/AI agents — commands and boundaries |
| [CHANGELOG.md](CHANGELOG.md) | Release history |

## Version

| File | Version |
|------|---------|
| `appinfo/info.xml` | 1.1.0 |
| `package.json` | 1.1.0 |

## Related repos

| Path / repo | Role |
|-------------|------|
| [`vdroners/nc-wireguard`](https://github.com/vdroners/nc-wireguard) | This app |
| [`vdroners/NC-GCS`](https://github.com/vdroners/NC-GCS) | Theme shell, admin link in VPN settings |
| `/media/4TB/wireguard` | wg-easy + wg-dashboard compose (not git) |

## License

AGPL-3.0-or-later

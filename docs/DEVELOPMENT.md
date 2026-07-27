# NC WireGuard — Development

## Prerequisites

- Node.js ≥ 18, npm ≥ 9
- Docker (`cloud_app` running for integration deploy)
- NC-GCS repo at `/media/4TB/nc-gcs` (for `node_modules/.bin` on PATH via Makefile)
- PHP 8.x + Composer optional (PHPUnit runs in Docker if host PHP missing)

## Repository layout

```
nc-wireguard/
├── appinfo/           # Nextcloud manifest, routes
├── css/               # SCSS sources (+ generated style.css)
├── js/                # Webpack output (gitignored)
├── lib/               # PHP — controllers, services, background job
├── src/               # Vue frontend (app-owned code only)
├── templates/         # PHP page shells
├── tests/             # PHPUnit
├── scripts/           # deploy, gate, bump, backup
├── docs/              # Architecture, API, runbook
└── Makefile           # Primary entry points
```

## Daily workflow

```bash
# 1. Sync NC-GCS theme/shell if nc_gcs changed
make sync-theme

# 2. Install deps (first time or after package.json change)
npm ci

# 3. Dev watch (optional)
npm run dev

# 4. Production build
make build

# 5. Lint + unit tests
make lint test

# 6. Full gate (build, native smoke, phpunit, deploy)
make gate-local

# Skip deploy during gate:
SKIP_DEPLOY=1 make gate-local
```

## Code conventions

### Frontend

- **Vue 2.7 Options API** for SFCs; composables use `Vue.observable` singleton for shared poll state.
- **No duplicate summary fetches** — extend `useDashboardSummary.js`, do not add per-tab polling for clients.
- **API calls** go through `services/dashboard-api.js` only.
- **Formatting** — `utils/format.js` for bytes/time; `utils/peer.js` for peer badges/flags.
- **Styles** — `css/nc-wireguard-theme.scss`; prefer NC CSS variables over hard-coded hex.
- **ESLint** — `make lint` scopes to app code; `_nc_gcs_src_mirror` is ignored (upstream NC-GCS).

### Backend

- `declare(strict_types=1);` on all PHP files.
- Admin check before any dashboard API response.
- New dashboard paths: add to `DashboardController` whitelist **and** [API_PARITY.md](API_PARITY.md).
- Unit tests in `tests/Unit/` for sanitizers and pure logic.

## Version bump

Version must stay in sync:

- `appinfo/info.xml` → `<version>`
- `package.json` → `"version"`
- `CHANGELOG.md` → Keep a Changelog entry

```bash
make bump-patch   # 2.2.0 → 2.2.1
make bump-minor   # 2.2.0 → 2.3.0
make bump-major   # 2.2.0 → 3.0.0
```

Then `make build`, update README badge if present, `make gate-local`.

## Release checklist

1. `make lint test`
2. `make gate-local` (or `SKIP_DEPLOY=1` then manual deploy)
3. Browser smoke: all tabs at 375 / 768 / 1440 px, `#bandwidth` deep-link client filter, config modal copy toast
4. `docker exec … smoke-peer-writes.php` and a public OTL curl without cookie
5. `docker exec cloud_app grep '<version>' /var/www/html/custom_apps/nc_wireguard/appinfo/info.xml`
6. Commit with scoped message; tag; push `main`

## Debugging

```bash
# Native smoke (inside cloud_app)
docker exec cloud_app php /var/www/html/custom_apps/nc_wireguard/scripts/smoke-native.php

# Manual poll
docker exec cloud_app php occ nc_wireguard:poll-metrics --no-lock

# NC status (requires admin session cookie)
curl -s -b cookies.txt 'https://cloud-vdroners.ddns.net/apps/nc_wireguard/api/status'

# wg-easy from cloud container
docker exec cloud_app curl -sf http://wg-easy:51821/api/client
```

## Common pitfalls

| Symptom | Cause | Fix |
|---------|-------|-----|
| Empty client dropdown on Bandwidth | Summary not loaded at shell | Use `summaryStore.clients`; do not rely on Overview mount |
| 502 from summary | wg-easy unreachable | Check API URL/credentials in admin settings |
| System tab empty | No host proc mount | Mount `/proc:/host/proc:ro` on `cloud_app` |
| Stale charts | Poller not running | Install `nc-wireguard-poll-metrics.timer` |
| ESLint scans mirror | Missing ignore | Use root `.eslintrc.js` ignorePatterns |
| Build fails on `@/` imports | Stale mirror | `make sync-theme` |

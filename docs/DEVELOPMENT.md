# NC WireGuard — Development

## Prerequisites

- Node.js ≥ 18, npm ≥ 9
- Docker (`cloud_app` running for integration deploy)
- PHP 8.x + Composer optional (PHPUnit runs in Docker if host PHP missing)

This app is **standalone**. You do **not** need a checkout of NC-GCS to install, build, or deploy.

## Repository layout

```
nc-wireguard/
├── appinfo/           # Nextcloud manifest, routes
├── css/               # SCSS sources (+ generated style.css)
├── js/                # Webpack output (gitignored)
├── lib/               # PHP — controllers, services, background job
├── src/               # Vue frontend (app-owned)
├── templates/         # PHP page shells
├── tests/             # PHPUnit
├── scripts/           # deploy, gate, bump, backup, export
├── docs/              # Architecture, API, runbook
└── Makefile           # Primary entry points
```

## Daily workflow

```bash
# 1. Install deps (first time or after package.json change)
npm ci
# If npm blocks install scripts for @nextcloud/webpack-vue-config,
# approve them once: npm install-scripts approve <pkg>

# 2. Dev watch (optional)
npm run dev

# 3. Production build
make build

# 4. Lint + unit tests
make lint test

# 5. Full gate (build, native smoke, phpunit, deploy)
make gate-local

# Skip deploy during gate:
SKIP_DEPLOY=1 make gate-local

# Peer-write + public OTL smoke (engine reachable):
GATE_PEER_WRITES=1 make gate-local
```

## Code conventions

### Frontend

- **Vue 2.7 Options API** for SFCs; composables use `Vue.observable` singleton for shared poll state.
- **No duplicate summary fetches** — extend `useDashboardSummary.js`, do not add per-tab polling for clients.
- **API calls** go through `services/dashboard-api.js` only.
- **Formatting** — `utils/format.js` for bytes/time; `utils/peer.js` for peer badges/flags.
- **Styles** — `css/nc-wireguard-theme.scss`; prefer NC CSS variables over hard-coded hex.
- **Shell** — local `NcAppShell.vue` (`nc-wg-app-shell` DOM markers).

### Backend

- `declare(strict_types=1);` on all PHP files.
- Admin check before any dashboard API response.
- Engine access goes through `WireGuardEngineInterface` (default `WgEasyEngine`).
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
4. `GATE_PEER_WRITES=1` peer-write + public OTL curl without cookie
5. `docker exec cloud_app grep '<version>' /var/www/html/custom_apps/nc_wireguard/appinfo/info.xml`
6. Commit with scoped message; tag matching `info.xml` version (e.g. `2.3.0`, not `v2.3.0`); push `main`

## Debugging

```bash
# Native smoke (inside cloud_app)
docker exec cloud_app php /var/www/html/custom_apps/nc_wireguard/scripts/smoke-native.php

# Manual poll
docker exec cloud_app php occ nc_wireguard:poll-metrics --no-lock

# NC status (requires admin session cookie)
curl -s -b cookies.txt 'https://cloud-vdroners.ddns.net/apps/nc_wireguard/api/status'

# Engine from cloud container (wg-easy today)
docker exec cloud_app curl -sf http://wg-easy:51821/api/client
```

## Common pitfalls

| Symptom | Cause | Fix |
|---------|-------|-----|
| Empty client dropdown on Bandwidth | Summary not loaded at shell | Use `summaryStore.clients`; do not rely on Overview mount |
| 502 from summary | Engine unreachable | Check API URL/credentials in admin settings |
| System tab empty | No host proc mount | Mount `/proc:/host/proc:ro` on `cloud_app` |
| Stale charts | Poller not running | Install `nc-wireguard-poll-metrics.timer` |
| Build missing terser/webpack peers | npm blocked install scripts | Approve `@nextcloud/webpack-vue-config` scripts; ensure `terser-webpack-plugin` is installed |

# NC WireGuard — Development

## Prerequisites

- Node.js ≥ 18, npm ≥ 9
- Docker (`cloud_app`, `wg-dashboard` running for integration deploy)
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

# 6. Full gate (build, sidecar smoke, phpunit, deploy)
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
- Admin check before any sidecar proxy response.
- New sidecar paths: add to `DashboardProxyController` whitelist **and** [API_PARITY.md](API_PARITY.md).
- Unit tests in `tests/Unit/` for sanitizers and pure logic.

## Version bump

Version must stay in sync:

- `appinfo/info.xml` → `<version>`
- `package.json` → `"version"`
- `CHANGELOG.md` → Keep a Changelog entry

```bash
make bump-patch   # 1.1.0 → 1.1.1
make bump-minor   # 1.1.0 → 1.2.0
```

Then `make build`, update README badge if present, `make gate-local`.

## Release checklist

1. `make lint test`
2. `make gate-local` (or `SKIP_DEPLOY=1` then manual deploy)
3. Browser smoke: all tabs, `#bandwidth` deep-link client filter, config modal copy toast
4. `docker exec cloud_app grep '<version>' /var/www/html/custom_apps/nc_wireguard/appinfo/info.xml`
5. Commit with scoped message; push `main`

## Debugging

```bash
# Sidecar direct
curl -s http://127.0.0.1:8185/api/health | jq .
curl -s http://127.0.0.1:8185/api/summary | jq '.connectedCount'

# From cloud container
docker exec cloud_app curl -sf http://wg-dashboard:8185/api/health

# NC proxy (requires admin session cookie)
curl -s -b cookies.txt 'https://cloud-vdroners.ddns.net/apps/nc_wireguard/api/status'
```

## Common pitfalls

| Symptom | Cause | Fix |
|---------|-------|-----|
| Empty client dropdown on Bandwidth | Summary not loaded at shell | Use `summaryStore.clients`; do not rely on Overview mount |
| 502 from NC proxy | Wrong sidecar URL from container | Set `http://wg-dashboard:8185`, ensure `wireguard_default` network |
| ESLint scans mirror | Missing ignore | Use root `.eslintrc.js` ignorePatterns |
| Build fails on `@/` imports | Stale mirror | `make sync-theme` |

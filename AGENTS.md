# AGENTS.md — NC WireGuard

Standalone repo: `/media/4TB/nc-wireguard` → GitHub `vdroners/nc-wireguard`.

## What this is

Admin-only Nextcloud app that proxies a **read-only** Vue dashboard to the `wg-dashboard` sidecar (`/media/4TB/wireguard`). **Not** `gcs_vpn_manager` (host outbound VPN in nc-gcs).

## Commands

```bash
cd /media/4TB/nc-wireguard
make sync-theme build deploy-docker
make lint test health gate-local
curl -s http://127.0.0.1:8185/api/health
```

| Variable | Effect |
|----------|--------|
| `SKIP_DEPLOY=1` | `gate-local` skips deploy step |
| `SIDECAR_URL` | Override sidecar base (default `http://127.0.0.1:8185`) |

## Deploy target

`cloud_app:/var/www/html/custom_apps/nc_wireguard/`

Sidecar from inside container: **`http://wg-dashboard:8185`** on network `wireguard_default`.

## Docs (read before large changes)

| File | Use |
|------|-----|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Module layout, polling, proxy |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | Build, test, release |
| [docs/API_PARITY.md](docs/API_PARITY.md) | Sidecar path whitelist |
| [docs/OPERATOR_RUNBOOK.md](docs/OPERATOR_RUNBOOK.md) | Cutover, backup, rollback |

## Editing rules

- **Do not edit** `src/_nc_gcs_src_mirror/` for feature work — run `make sync-theme` from nc-gcs.
- **Single poll** — summary/clients via `useDashboardSummary.js` only.
- **PHP proxy** — new paths need whitelist + admin gate + API_PARITY doc update.
- **Version** — bump `info.xml`, `package.json`, `CHANGELOG.md` together on behavior changes.
- **Commit scope** — stage explicit paths only; do not `git add -A` (parallel work on server).
- **Push** — ask operator before push unless they explicitly request it.

## Current version

See `appinfo/info.xml` (1.1.0 as of 2026-06-18 UI pass).

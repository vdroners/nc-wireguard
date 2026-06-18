# NC WireGuard — Architecture

## System context

```
┌─────────────────────────────────────────────────────────────────┐
│ Nextcloud (cloud_app)                                           │
│  ┌──────────────────┐    ┌─────────────────────────────────┐  │
│  │ Vue SPA          │───▶│ PHP proxy (admin-only)          │  │
│  │ WireGuardDashboard│    │ DashboardProxyController        │  │
│  │ + 5 tabs         │    │ WgEasyReadProxyController       │  │
│  └──────────────────┘    └──────────────┬──────────────────┘  │
└──────────────────────────────────────────│────────────────────┘
                                           │ HTTP (wireguard_default)
                                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ wg-dashboard sidecar (:8185, loopback on host)                  │
│  Poller → SQLite (metrics, connection_log, geoip_cache)         │
│  Session bridge → wg-easy REST API                              │
└─────────────────────────────────────────────────────────────────┘
                                           │
                                           ▼
                              wg-easy (WireGuard UI + peers)
```

Public legacy URL `https://vpn-vdroners.ddns.net/dashboard/` was retired at cutover (2026-06-18). Operators use the NC app only.

## Frontend (`src/`)

Only files **outside** `src/_nc_gcs_src_mirror/` are owned by this app. The mirror is a build-time copy of NC-GCS shell assets (gitignored; refreshed via `make sync-theme`).

| Path | Role |
|------|------|
| `main.js` | Mount `AppRoot` (shell + dashboard) |
| `components/AppRoot.vue` | `NcGcsAppShell` wrapper; banner status chips |
| `components/WireGuardDashboard.vue` | Tab routing, polling lifecycle, modals |
| `components/TabBar.vue` | Desktop 5-tab bar; mobile Overview + More menu |
| `composables/useDashboardSummary.js` | **Single source of truth** for summary/health poll (15s, visibility-aware) |
| `composables/useClientList.js` | Thin wrapper over summary store clients |
| `services/dashboard-api.js` | Axios calls to `/apps/nc_wireguard/api/...` |
| `components/tabs/*.vue` | One SFC per dashboard tab |
| `components/HistoryToolbar.vue` | Shared time/client filter bar |
| `utils/format.js`, `utils/peer.js`, `utils/bandwidth-rates.js` | Pure helpers |

### Polling model

1. `WireGuardDashboard` calls `startSummaryPolling()` on mount.
2. `useDashboardSummary` fetches `/api/dashboard/summary` + `/api/status` every 15s.
3. Polling pauses when `document.hidden`; immediate refresh on tab visible.
4. All tabs read `summaryStore.clients` — no tab-order dependency (fixes deep-link filter bug).

### Responsive behavior

| Viewport | Tabs | Overview |
|----------|------|----------|
| `<768px` | Overview + More select | Peer cards |
| `≥768px` | Five tab buttons | Sortable table + expand rows |
| `≥1024px` | Five tab buttons | + side-by-side bandwidth charts, map split |

## Backend (`lib/`)

| Class | Role |
|-------|------|
| `DashboardProxyController` | Whitelist proxy to sidecar `/api/{summary,bandwidth,...}` |
| `WgEasyReadProxyController` | Read-only peer WireGuard config |
| `DashboardHttpClient` | HTTP client, timeout, base URL from app config |
| `PathSanitizer` | Block path traversal on proxy segments |
| `SidecarWatchdogJob` | Background job: sidecar health check |
| `CspListener` | CSP allowances for maps/charts |

Every dashboard API path checks **Nextcloud admin** before proxying. Non-admins receive HTTP 403 (UI shows full-page message).

Allowed proxy roots: `summary`, `bandwidth`, `connections`, `geoip`, `system`, `health`.

See [API_PARITY.md](API_PARITY.md) for the full path table.

## Deploy artifact

`make deploy-docker` tars the repo (excluding `node_modules`, `.git`, `tests`) into `cloud_app:/var/www/html/custom_apps/nc_wireguard/` and runs `occ upgrade`.

Built assets live in `js/` and `css/style.css` (generated; not committed).

## Security notes

- Sidecar binds **127.0.0.1:8185** on the host; NC reaches it via Docker network only.
- No write access to wg-easy from NC app (read-only config fetch).
- Proxy path whitelist + traversal guard on `{path}` route parameter.

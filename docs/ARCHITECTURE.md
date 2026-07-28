# NC WireGuard — Architecture

## System context

```
┌─────────────────────────────────────────────────────────────────┐
│ Nextcloud (cloud_app)                                           │
│  ┌──────────────────┐    ┌─────────────────────────────────┐  │
│  │ Vue SPA          │───▶│ PHP native API (admin-only*)    │  │
│  │ WireGuardDashboard│    │ DashboardController             │  │
│  │ + peer modals    │    │ PeerWriteController (+ public   │  │
│  │ + 5 tabs         │    │   OTL redeem)                   │  │
│  │                  │    │ WgEasyReadProxyController       │  │
│  └──────────────────┘    └──────────────┬──────────────────┘  │
│                                         │                       │
│  ┌──────────────────────────────────────▼──────────────────┐  │
│  │ MySQL nc_wg_* tables (bandwidth, connections, geoip, …)  │  │
│  │ MetricsPollService ← occ nc_wireguard:poll-metrics       │  │
│  └──────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────│────────────────────┘
                                           │ HTTP (wireguard_default)
                                           ▼
                              wg-easy (engine only; UI unpublished)
```

\* Dashboard / peer writes are admin-only. `GET …/api/peers/otl/{token}` is public (rate-limited, single-use).

Public legacy URL `https://vpn-vdroners.ddns.net/dashboard/` was retired at cutover (2026-06-18). Operators use the NC app only.

**v2.0.0** removed the wg-dashboard sidecar; all dashboard routes are served from NC MySQL tables populated by the native poller. **v2.1** added peer writes; **v2.2** added public OTL redeem, bulk Field preset, and a read-only server defaults card.

## Frontend (`src/`)

All of `src/` is owned by this app (no NC-GCS theme mirror).

| Path | Role |
|------|------|
| `main.js` | Mount `AppRoot` (shell + dashboard) |
| `components/AppRoot.vue` | Local `NcAppShell` wrapper; banner status chips |
| `components/NcAppShell.vue` | Standalone banner/body shell (`nc-wg-app-shell`) |
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
| `DashboardController` | Native dashboard routes: summary, bandwidth, connections, geoip, system, health, server |
| `WgEasyReadProxyController` | Peer WireGuard config via engine (`/api/peers/{id}/configuration`) |
| `PeerWriteController` | Peer CRUD + OTL mint (admin); public OTL redeem (v2.2) |
| `PeerFieldValidator` | Peer form input rules: name, AllowedIPs CIDR, DNS, MTU, keepalive, ipv4, serverEndpoint |
| `OtlRedeemRateLimiter` | Per-IP appconfig window for public OTL redeem |
| `OtlRedeemTracker` | NC-side single-use token memory (complements wg-easy erase) |
| `NativeDashboardService` | Builds dashboard JSON from MySQL mappers |
| `NativeHealthService` | Aggregates poller heartbeat, engine, host proc |
| `MetricsPollService` | Poll loop: engine peers → DB writes |
| `WireGuardEngineInterface` | Engine abstraction (`listPeers`, CRUD, stats by `public_key`, …) |
| `WgEasyEngine` | Default engine: wraps `WgEasyClient` |
| `WgEasyClient` | Session auth + REST calls to wg-easy (incl. `getServerDefaults`) |
| `HostProcCollector` | CPU/mem/disk/net from `/host/proc` or `/proc` |
| `DockerUrlResolver` | Rewrites `host.docker.internal` when DNS fails in container |
| `MetricsHealthJob` | Background job: stale poll / wg-easy failure logging |
| `PathSanitizer` | Block path traversal on API segments |
| `CspListener` | CSP allowances for maps/charts |

Dashboard / peer-write API paths check **Nextcloud admin** before responding
(except public OTL redeem). Non-admins receive HTTP 403.

Allowed dashboard roots: `summary`, `bandwidth`, `connections`, `geoip`, `system`, `health`, `server`.

See [API_PARITY.md](API_PARITY.md) for response shapes.

Golden JSON for parity tests: [`tests/fixtures/sidecar/`](../tests/fixtures/sidecar/) (historical reference; sidecar archived v2.0).

### Host metrics

Native poller host metrics use **`HostProcCollector`** with read-only `/host/proc` in `cloud_app`. Full audit: [HOST_METRICS_AUDIT.md](HOST_METRICS_AUDIT.md).

### Poller operations

| Command | Purpose |
|---------|---------|
| `occ nc_wireguard:poll-metrics` | Single poll (flock; use `--no-lock` for smoke) |
| `occ nc_wireguard:prune-metrics` | Delete rows older than retention |
| `occ nc_wireguard:schema-check` | Verify MySQL schema |

Systemd unit files: [`docs/ops/nc-wireguard-poll-metrics.timer`](ops/nc-wireguard-poll-metrics.timer), [`docs/ops/nc-wireguard-prune-metrics.timer`](ops/nc-wireguard-prune-metrics.timer).

## Deploy artifact

`make deploy-docker` tars the repo (excluding `node_modules`, `.git`, `tests`) into `cloud_app:/var/www/html/custom_apps/nc_wireguard/` and runs `occ upgrade`.

Built assets live in `js/` and `css/style.css` (generated; not committed).

## Security notes

- Peer writes (v2.1) are admin-only **and** CSRF-protected: no mutating route
  carries `NoCSRFRequired`. Every write is audit-logged with the actor UID.
- Peer private keys and full `.conf` bodies are never written to the log.
- Path whitelist + traversal guard on `{path}` route parameter.
- wg-easy credentials stored encrypted via `SecretCrypto`.
- The wg-easy service account must keep 2FA off — wg-easy answers HTTP 200 with
  `{"status":"TOTP_REQUIRED"}` and offers no non-interactive TOTP path, so a
  2FA-enabled account cannot hold an API session.

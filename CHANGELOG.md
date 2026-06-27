# Changelog

## [2.0.0] - 2026-06-27

### Added

- `lib/Util/DockerUrlResolver.php` — extracted from removed sidecar HTTP client
- P6 gate notes: browser matrix (375/768/1440) and systemd timer install in `gate-local.sh`

### Changed

- **Native-only backend** — removed wg-dashboard sidecar proxy path, `DashboardHttpClient`, `SidecarWatchdogJob`, and admin settings for sidecar URL / `use_native_backend`
- `DashboardProxyController`, `ApiController`, `WgEasyReadProxyController`, `SettingsController` serve native routes only
- `MetricsHealthJob` always active when dashboard + watchdog enabled
- `scripts/gate-local.sh` — native smoke only (no sidecar dependency)
- `make health` — poll + native smoke instead of sidecar curl
- Docs rewritten for v2.0 native architecture, systemd timers, backup

### Removed

- `lib/Service/DashboardHttpClient.php`
- `lib/BackgroundJob/SidecarWatchdogJob.php`
- Sidecar admin settings: `dashboard_internal_url`, proxy timeouts, `use_native_backend` toggle

### Notes

- Archived sidecar at `/media/4TB/wireguard/dashboard.archived/`; `wg-dashboard` removed from compose
- One-time import still available: `occ nc_wireguard:import-sidecar-db`
- Install poll timers: `docs/ops/nc-wireguard-poll-metrics.timer`

### Added (P5)

- `scripts/cutover-native.sh` — backup + poll smoke + native verify
- `scripts/smoke-native.php` and `scripts/verify-status-native.php` for service-layer gates without HTTP session
- `make bump-major` / `bump-version.sh major` for release versioning

### Changed (P5)

- `scripts/backup-wireguard-metrics.sh` — dumps `nc_wg_*` MySQL tables + `occ config:list` snapshot
- Default production mode is **native backend** after cutover

### Notes (P5)

- Host metrics require `/proc:/host/proc:ro` on `cloud_app` (see `docs/ops/host-proc-mount.md`)

## [1.3.0] - 2026-06-27

### Added

- `occ nc_wireguard:import-sidecar-db` — idempotent import from wg-dashboard SQLite into NC native metrics tables
- `occ nc_wireguard:verify-import` — row-count and poll_state key verification against source SQLite
- `SidecarImportService` with natural-key dedup and poll_state derivation from connection_log when sidecar has no poll_state table

### Notes

- P4 gate: run import before cutover so bandwidth/system charts retain pre-migration history on native backend.

## [1.3.0-rc] - 2026-06-27

### Added

- Native dashboard API when `use_native_backend=1`: same NC routes served from DB + `WgEasyClient` (summary, bandwidth, connections, geoip, system, peer config)
- `NativeDashboardService` and `NativeHealthService` with heartbeat + wg-easy health on `/api/status`
- PHPUnit parity tests against `tests/fixtures/sidecar/` golden JSON (structure/keys)
- Mock wg-easy configuration tests for CI (`WgEasyClientConfigurationTest`)

### Changed

- `DashboardProxyController` branches to native handlers when flag enabled; sidecar proxy remains fallback
- `WgEasyReadProxyController` uses direct `WgEasyClient` for configuration in native mode
- Vue banner shows **Native backend** vs **Sidecar backend** chip; System tab warns when host metrics unavailable
- Admin settings copy updated for P3 native toggle

### Notes

- Staging: enable `use_native_backend=1` to verify all five tabs + config modal; sidecar still available when flag is off.

## [1.2.0-beta] - 2026-06-27

### Added

- Native metrics poller services: `WgEasyClient`, `ConnectionStateMachine`, `GeoIpService`, `HostProcCollector`, `SystemMetricsCollector`, `MetricsPollService`, `MetricsPruneService`
- `occ nc_wireguard:poll-metrics` (flock-guarded) and `occ nc_wireguard:prune-metrics`
- `MetricsHealthJob` background watchdog when `use_native_backend=1`
- Systemd timer templates under `docs/ops/` and host `/proc` mount notes

### Changed

- `SidecarWatchdogJob` skips when `use_native_backend=1`
- `WgEasyClient` uses wg-easy v14+ config path `/api/client/{id}/configuration` with legacy fallback

### Notes

- Default remains proxy mode (`use_native_backend=0`); poller fills NC DB silently until P3/P5.

## [1.2.0-alpha] - 2026-06-27

### Added

- Native metrics DB schema: six Nextcloud tables (`nc_wg_*`) with entity mappers
- `occ nc_wireguard:schema-check` command to verify migrations
- Admin settings: wg-easy API URL/credentials (encrypted), poll interval, retention days, GeoIP toggle, `use_native_backend` flag
- Metrics health watchdog interval UI (minutes)
- Admin **Test wg-easy** endpoint (`POST /api/settings/test-wg-easy`)

### Notes

- Default remains proxy mode (`use_native_backend=0`); sidecar dashboard unchanged until P3/P5.

## [1.1.0] - 2026-06-18

### Added

- Shell-level summary polling (`useDashboardSummary`) — client filters work on deep-link tabs
- Responsive tab bar: 5 tabs on desktop, Overview + More menu on mobile
- Compact header: status chips in banner-extra, host metrics strip (CPU/Mem/Disk bars)
- Overview search/sort, progressive-disclosure table, mobile peer cards
- Shared `HistoryToolbar` for Bandwidth, Connections, System tabs
- Side-by-side bandwidth charts on wide screens; combined CPU+Memory system chart
- Map split layout with collapsible IP list
- Peer config modal: copy toast, wide QR layout, wg-easy edit link
- Tab badges, dynamic subtitle, disabled/expiring peer styling, uptime footer
- Visibility-aware polling pause; `nc_wireguard` banner icon in NcGcsAppShell

### Fixed

- Empty client dropdown when opening Bandwidth/Connections before Overview

## [1.0.0] - 2026-06-18

### Added

- Initial NC WireGuard app: 5-tab Vue dashboard (Overview, Bandwidth, Connections, Map, System)
- PHP proxy to wg-dashboard sidecar with admin-only gate
- Read-only peer WireGuard config modal + QR
- Admin settings + SidecarWatchdogJob
- Sidecar hardening: `/api/health`, IPv6 endpoint parsing, log prune, wg config route

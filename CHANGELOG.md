# Changelog

## [2.3.1] - 2026-08-03

### Security / packaging (App Store readiness)

- CSRF restored on `saveSettings` / engine test endpoints; `#[PasswordConfirmationRequired]`
  on settings save (engine password / GeoIP key); `#[UserRateLimit]` on connection probes
- Admin settings use `@nextcloud/axios` requesttoken + core `OC.PasswordConfirmation`
- `SidecarImportService` no longer defaults to a lab `/media/4TB/...` SQLite path;
  `import-sidecar-db` / `verify-import` require an explicit path
- `make appstore` excludes `services/`, `docs/ops/`, `scripts/`, `src/`, `.github`, tests
- Nested `<documentation><user>/<admin>` in `info.xml`; clearer external-engine + privacy copy
- Stranger install path documented in `INSTALL.md` (upstream `ghcr.io/wg-easy/wg-easy:15`)

## [2.3.0] - 2026-07-27

### Added — standalone finish (S1–S4)

- Break Vue `package-lock` link to NC-GCS; retire `dedupe-vue`; deploy PATH uses
  local `node_modules/.bin` only; `npm ci && npm run build` without NC-GCS
- Remove `ThemeAssetLoader` / `sync-theme-from-nc-gcs.sh` / `_nc_gcs_src_mirror`;
  rename shell CSS markers to `nc-wg-app-shell`
- `GATE_PEER_WRITES=1` peer-write (+ optional `GATE_OTL_TOKEN`) in `gate-local.sh`;
  release asset named from `info.xml` version; App Store contract: external engine
  required; screenshot placeholders refreshed
- Admin copy: “Test engine”, engine-agnostic watchdog wording; primary admin
  strings via `t()` + `l10n/en.json` / `de.json`

### Added — engine interface (P2)

- `WireGuardEngineInterface` + `WgEasyEngine` (default); runtime stats keyed by
  `public_key`; controllers/poller/summary go through the interface

### Added — peer export (P1)

- `scripts/export-peers.sh` (get-one + `.conf`, `0700`/`0600` under
  `/media/4TB/wireguard/exports/`); `docs/ops/PEER_EXPORT.md`

### Added — NC peer store (P3)

- Migration `Version000002Date20260727000000`: `nc_wg_peers` (`uuid` +
  `public_key` unique, nullable `wg_easy_id`, tunnel fields, `has_amnezia`),
  `nc_wg_peer_secrets` (encrypted private key + PSK), and the `nc_wg_server`
  singleton (host/port/CIDR/MTU, defaults, preserved `server_public_key`,
  `ipv4_only`). Nullable `peer_uuid` / `public_key` columns added to the metrics
  tables as remap headroom for cutover
- `PeerSecretCrypto` (`enc:peer:v1:`) — **throws** on any decrypt failure, unlike
  `SecretCrypto`, which returns the stored blob and would let ciphertext reach a
  peer config
- `PeerIpam` — `10.8.0.0/24` pool, server `.1` reserved, first free `/32`,
  collision-checked against stored peers; IPv4 only
- `PeerStoreService` — shadow store while `engine=wgeasy`; matches on public key
  then `wg_easy_id`; never generates a keypair and never overwrites stored key
  material without an explicit flag
- `occ nc_wireguard:import-peers [--from-export=DIR] [--dry-run]` — imports from
  the live engine (`getPeer` for secrets the list endpoint strips) or from an
  `export-peers.sh` directory; flags `keepalive=0` against the Field preset's
  25 s, flags Amnezia peers, preserves the `Server` break-glass peer
- `engine` (default `wgeasy`) and `otl_source` (default `wgeasy`) settings
- Checked-in plan `.cursor/plans/leave-wg-easy-engine.md`

### Added — native conf / QR / OTL (P4)

- `PeerConfBuilder` renders a peer `.conf` from the NC store: `[Interface]`
  private key / Address / DNS / MTU, `[Peer]` server public key, endpoint
  (`serverEndpoint` override else `host:port`), AllowedIPs and keepalive.
  Precedence is peer → `nc_wg_server` → preset. It refuses (rather than emits a
  config that cannot connect) when the private key, address, server public key,
  or endpoint is missing, and never writes `::/0` while `ipv4_only`
- `PeerPresets` — Field (`10.0.0.0/24, 10.8.0.0/24`, keepalive 25) and Admin
  (`0.0.0.0/0`, keepalive 0) as server-side constants, so the builder and the
  admin UI cannot drift. Documented in `docs/ops/NATIVE_CONF_DEFAULTS.md`
- `NcOtlService` — Nextcloud mints its own one-time links when `otl_source=nc`
  (appconfig-backed, single-use, expiry reported as 410 not 404).
  `redeemOtl` falls through to the engine for tokens it does not recognise, so
  links minted before the switch keep working. Default stays `wgeasy`

### Added — wg-sync sidecar + NativeEngine (P5, lab only)

- `services/wg-sync/` — stdlib-only Python HTTP service: `GET /health`,
  `POST /apply`, `GET /dump`, `POST /reload`, bearer-token auth, atomic config
  write plus `wg syncconf` so existing peers keep their handshakes.
  `entrypoint.sh` reproduces wg-easy's NAT/sysctl parity (`ip_forward`, IPv6
  forwarding, `src_valid_mark`, MASQUERADE, FORWARD ACCEPT)
- `docker-compose.lab.yml` — interface `wg-lab0`, UDP **51830**, API on
  loopback `51831`. `app.py` refuses `wg0` or `51820` unless
  `WG_SYNC_ALLOW_PROD=1`, so the lab runs beside production wg-easy
- `NativeEngine` — full `WireGuardEngineInterface` over the peer store plus the
  sidecar. Amnezia `j*`/`i*` and any IPv6 address are **refused**, never
  dropped; a stored Amnezia peer is excluded from the applied set and logged
- `ServerKeyStore` + `occ nc_wireguard:set-server-key` (reads stdin) — seals the
  interface private key with hard-fail crypto and mirrors the public half onto
  `nc_wg_server`
- `EngineResolver` — `engine=native` activates only with `import_complete` and a
  non-empty peer store; `Application.php` resolves the engine through it per
  request, so a rollback is one `occ config:app:set`
- `scripts/smoke-native-engine.php` — lab smoke that exits 0 (SKIP) when the
  sidecar is unreachable

### Added — cutover scaffolding (P6)

- `docs/ops/ENGINE_CUTOVER.md` — operator runbook: freeze → export/archive →
  re-import + remap → same-51820 swap with the preserved interface key → verify
  with a real field peer → unfreeze, plus the rollback (restore archive, pin
  `wg-easy:15`, `engine=wgeasy`)
- `peer_writes_frozen` — blocks peer CRUD and OTL mint with
  `503 writes_frozen`; downloads, redeems, and the poller keep working
- `occ nc_wireguard:remap-metrics [--apply]` (and
  `scripts/remap-metrics-peer-ids.php`) — backfills `peer_uuid` / `public_key`
  on the metrics tables from `wg_easy_id`. Dry run by default, idempotent, and
  leaves `client_id` as the audit trail
- `AppSettings::SETTING_ALIASES` — reads the new `engine_*` names and falls back
  to `wg_easy_*` for one minor, so existing `occ config:app:set` usage and
  scripted deploys survive the rename

### Fixed

- `admin.js` called `t()` without defining it and without `@nextcloud/l10n` as a
  dependency; it now delegates to Nextcloud's global `t()` when present and
  falls back to the source string

### Notes

- The migration only applies on `occ upgrade`, i.e. after an app version bump
- **Production stays on wg-easy.** Nothing in this change writes to the engine
  or switches engines; the cutover is an operator action against the runbook

## [2.2.0] - 2026-07-27

### Added

- **Public OTL redeem** — `GET /api/peers/otl/{token}` is `#[PublicPage]` +
  `#[NoCSRFRequired]` with `OtlRedeemRateLimiter` (per-IP appconfig window)
  and `OtlRedeemTracker` (NC-side single-use); mint stays admin+CSRF; config
  modal prefers the shareable NC URL
- Peer form **advanced** fields: optional `ipv4Address` + `serverEndpoint`
  (validated in `PeerFieldValidator`); Overview expand shows IPv6 read-only
- Overview **bulk Field preset** — multi-select + Apply Field preset
  (`10.0.0.0/24,10.8.0.0/24`, keepalive 25); skips peer named `Server`
- System tab **read-only server defaults** card via
  `GET /api/dashboard/server` → wg-easy `/api/admin/general` +
  `/api/admin/interface` (soft-fail; no write)
- Checked-in plan `.cursor/plans/wg-2.2-gap-fill.md`

### Changed

- Renamed `DashboardProxyController` → `DashboardController` (HTTP path
  `/api/dashboard/{path}` unchanged; route name `dashboard#proxy`)
- Operator runbook / API_PARITY document public OTL, bulk Field policy, and
  deferred server-write / Portainer scope

### Notes

- Server write / restart / hooks / Amnezia remain deferred (break-glass
  loopback `127.0.0.1:51821`)
- Portainer has no WireGuard surface on this host

## [2.1.0] - 2026-07-27

### Peer controller (monitor → controller)

Nextcloud becomes the operator UI for wg-easy peers. Verified against
`ghcr.io/wg-easy/wg-easy:15`; re-smoke the write paths on any wg-easy upgrade.

### Added

- `WgEasyClient` write methods: `createClient`, `updateClient`, `deleteClient`,
  `enableClient`, `disableClient`, `generateOneTimeLink`, plus shared
  request/re-login helpers and `TOTP_REQUIRED` detection on login
- `PeerWriteController` — admin-only peer writes with **CSRF required** (no
  `NoCSRFRequired` on any mutating route) and an audit log entry per action
  (actor UID, action, client id, HTTP code)
- `PeerFieldValidator` — name length, AllowedIPs CIDR list, DNS addresses, MTU
  range and keepalive validation with per-field error messages
- Routes: `POST /api/peers`, `POST|DELETE /api/peers/{id}`,
  `POST /api/peers/{id}/enable|disable`, `POST /api/peers/{id}/one-time-link`,
  `GET /api/peers/{id}/configuration`
- `hide_wg_easy_admin_link` app setting (**defaults on**) so the dashboard hides
  wg-easy deep links once Nextcloud owns peer management
- `PeerFormModal.vue` — name, expiry, AllowedIPs, DNS, MTU, keepalive with
  Field/site-GCS and Admin full-tunnel presets
- Peer config modal: `.conf` download and one-time-link generation

### Changed

- `GET /api/wg-easy/{id}/configuration` is now an alias of
  `GET /api/peers/{id}/configuration` and is kept for cached frontend bundles
- Admin **Test wg-easy** reports a TOTP-enabled service account explicitly
  instead of failing later with "client list fetch failed"
- Operator-facing copy prefers "WireGuard server" / "VPN peers" over "wg-easy"

### Notes

- The wg-easy service account must keep 2FA **off** — wg-easy has no
  non-interactive TOTP path for API sessions
- Create requires `expiresAt` key (`null` = no expiry); NC always sends it
- Field peers default AllowedIPs `10.0.0.0/24,10.8.0.0/24`, keepalive `25`, IPv4-only
- Engine admin UI unpublished (`127.0.0.1:51821` break-glass; CPM `vpn-vdroners` disabled)
- wg-easy's create schema accepts only `name` + `expiresAt`, so tunnel fields
  are applied by a follow-up update; its update schema is not partial, so
  updates read the peer first and send the full object
- `POST /generateOneTimeLink` returns only `{success:true}`, and wg-easy's
  single-client endpoint does not join the one-time-link table. The token is
  therefore read back from the client **list**, where it is nested as
  `oneTimeLink.oneTimeLink`; tokens carry a ~5 minute expiry and are erased on
  first redeem
- The NC redeem route (`GET /api/peers/otl/{token}`) is admin-gated like every
  other write route, so it is not a link that can be handed to a non-admin
  recipient — use the `.conf` download or QR for that

## [2.0.2] - 2026-07-04

### App Store publication readiness

- Decoupled frontend build from NC-GCS (local `NcAppShell`, webpack `@` → `src/`).
- Neutralized org runtime defaults (empty wg-easy URLs).
- GeoIP default **off**; configurable provider (ip-api Pro key or custom HTTPS URL).
- Admin-only page and APIs; stop polling on 403; hide admin URL from non-admins.
- WireGuard/wg-easy trademark disclaimer; root AGPL `LICENSE`.
- l10n, store metadata, screenshots, uninstall drops `nc_wg_*` tables + appconfig.
- Release automation: `make appstore`, CI + release GitHub workflows, operator onboarding doc.

## [2.0.1] - 2026-06-27

### Fixed

- `HostProcCollector` — parse aggregate CPU line as `cpu` (not `cpu:`) from `/proc/stat`; system metrics no longer stuck at 0% CPU

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

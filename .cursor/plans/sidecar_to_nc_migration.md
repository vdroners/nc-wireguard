---
name: Sidecar NC migration v2
overview: "Final migration plan: port wg-dashboard into nc_wireguard (PHP + NC DB), reuse existing NC-GCS host metrics if found, 15-min maintenance cutover, expanded todos with per-phase pass/fail gates, working app at every milestone."
todos:
  - id: p0-audit-fixtures
    content: "P0: Check in plan; capture 7+ JSON fixtures; expand API_PARITY.md; audit nc-gcs for host metrics reuse"
    status: completed
  - id: p0-gate
    content: "P0 GATE: make gate-local PASS on v1.1.0 baseline"
    status: completed
  - id: p1-db
    content: "P1: NC migrations (6 tables) + mappers + occ schema-check"
    status: completed
  - id: p1-admin
    content: "P1: Admin settings — wg-easy creds encrypted, poll/retention/geoip, native flag, watchdog UI"
    status: completed
  - id: p1-gate
    content: "P1 GATE: schema-check + PHPUnit + admin test + proxy mode gate-local PASS"
    status: completed
  - id: p2-services
    content: "P2: WgEasyClient, ConnectionFSM, GeoIp, SystemMetricsAdapter, MetricsPollService, prune"
    status: completed
  - id: p2-ops
    content: "P2: occ poll-metrics/prune + flock + heartbeat + MetricsHealthJob + systemd docs"
    status: completed
  - id: p2-gate
    content: "P2 GATE: polls populate DB + events + geo + sidecar UI still works"
    status: completed
  - id: p3-api
    content: "P3: Native controllers (same URLs) + status API + direct config + parity PHPUnit + mock wg-easy"
    status: completed
  - id: p3-vue
    content: "P3: Minimal Vue backend chip + system-unavailable warning"
    status: completed
  - id: p3-gate
    content: "P3 GATE: staging native ON — all tabs + gate-local + parity tests"
    status: in_progress
  - id: p4-import
    content: "P4: import-sidecar-db + verify-import + poll_state"
    status: completed
  - id: p4-gate
    content: "P4 GATE: row counts match + historical charts on staging"
    status: completed
  - id: p5-cutover
    content: "P5: cutover-native.sh + proc mount if needed + update gate-local + backup script"
    status: pending
  - id: p5-gate
    content: "P5 GATE: G1–G14 maintenance sign-off"
    status: pending
  - id: p6-cleanup
    content: "P6: Remove proxy code, docs rewrite, archive dashboard/, v2.0.0 tag"
    status: pending
  - id: p6-gate
    content: "P6 GATE: gate-local no sidecar + 24h poll health + browser matrix"
    status: pending
isProject: false
---

# Sidecar → NC WireGuard v2.0 — final plan (reviewed)

**Goal:** Remove `wg-dashboard` container. Backend moves into [`/media/4TB/nc-wireguard`](/media/4TB/nc-wireguard). Dashboard **must work** after cutover and at every milestone until P5.

**Your choices:**
- **Cutover:** ~15 min maintenance window (not long dual-run)
- **Host metrics:** Reuse existing NC-GCS service/route if audit finds one; else read-only `/host/proc` bind-mount in `cloud_app`

---

## Working-app guarantee

| Phase | Admin experience |
|-------|------------------|
| P0–P1 | v1.1.0 unchanged (proxy → sidecar) |
| P2 | Poller fills NC DB silently; UI still uses sidecar |
| P3 | Staging: native flag ON; production still proxy until P5 |
| P5 | Maintenance flip → stop sidecar → smoke (≤20 min) |
| P6 | Native only |

**Rollback (until P6):** `use_native_backend=0` + restart `wg-dashboard`.

---

## Feature gaps to port (complete list)

**Core (from sidecar `app.py`):** wg-easy session, 30s poll, bandwidth_log, connection_log + 180s FSM, GeoIP (7-day cache), system_metrics, 30-day prune, 6 read APIs, peer config.

**Easy to miss:** `serverBootTime`, IPv6 `endpoint_ip`, poll flock, heartbeat table, import `poll_state`, query param limits (hours 1–720, days 1–365), cold-start chart hints, encrypted creds, watchdog interval UI, non-admin 403, `dashboard_enabled` gate.

**New (in scope):** `schema-check`, `verify-import`, systemd unit templates, mock wg-easy CI tests, `cutover-native.sh`, NC DB backup script, 24h post-cutover poll monitor, native-backend admin/banner chip.

**Out of scope:** wg-easy write CRUD, Chart.js/Leaflet replacement, merging wg-easy container.

---

## P0 — Baseline + audit + fixtures

- Check in plan to `nc-wireguard/.cursor/plans/sidecar_to_nc_migration.md`
- Capture JSON fixtures → `tests/fixtures/sidecar/` (summary, bandwidth, connections, geoip, system, status, config)
- Expand [`docs/API_PARITY.md`](/media/4TB/nc-wireguard/docs/API_PARITY.md) (fields, params, errors)
- **Host metrics audit:** search [`/media/4TB/nc-gcs/services/`](/media/4TB/nc-gcs/services/), gcs-services compose, base-station telemetry — document reuse vs `/proc` fallback

**Gate P0:** `make gate-local` PASS on v1.1.0; ≥7 fixture files; API_PARITY cross-checked with Vue; host-metrics decision written.

---

## P1 — NC DB + admin (v1.2.0-alpha)

**Tables:** `nc_wg_bandwidth_log`, `nc_wg_connection_log`, `nc_wg_geoip_cache`, `nc_wg_system_metrics`, `nc_wg_poll_state`, `nc_wg_metrics_heartbeat`

**Settings:** wg-easy URL/creds (encrypted), poll interval, retention, geoip toggle, `use_native_backend`, watchdog interval UI

**Gate P1:** `occ nc_wireguard:schema-check` 0; PHPUnit mappers; admin wg-easy test OK; **proxy mode still works** (`gate-local` PASS).

---

## P2 — Poller + PHP services (v1.2.0-beta)

**Services:** `WgEasyClient`, `ConnectionStateMachine`, `GeoIpService`, `SystemMetricsCollector` (adapter), `MetricsPollService`, `MetricsPruneService`

**Commands:** `poll-metrics` (flock), `prune-metrics`; systemd timer in `docs/ops/`; `MetricsHealthJob` replaces `SidecarWatchdogJob`

**Gate P2:** 3 polls populate all tables; connect/disconnect event; geo on connect; flock works; heartbeat fresh; **UI still on sidecar** (`gate-local` PASS).

---

## P3 — Native API + staging (v1.3.0-rc)

Same NC URLs; flag `use_native_backend=1`. Minimal Vue: backend chip, system-unavailable warning, admin label updates.

**Gate P3:** PHPUnit parity vs fixtures; mock wg-easy CI; all 5 tabs + modal on **staging native ON**; `gate-local` PASS; sidecar still available as fallback on staging.

---

## P4 — Import historical SQLite

`import-sidecar-db` + `verify-import` (row counts, poll_state)

**Gate P4:** Counts match; charts show pre-migration data; idempotent; staging native works with imported data.

---

## P5 — Maintenance cutover (v2.0.0)

`scripts/cutover-native.sh` — backup → import → native ON → smoke → stop sidecar → VPN test

Optional: `/proc` mount in cloud compose if audit chose HostProcCollector

**Gate P5 (all required before done):**

| ID | Check |
|----|-------|
| G1 | No `wg-dashboard` container |
| G2 | Last poll < 60s (`/api/status`) |
| G3 | wg-easy OK |
| G4–G8 | All 5 tabs HTTP 200 / browser |
| G9 | `#bandwidth` client filter |
| G10 | Config modal + copy |
| G11 | `make gate-local` |
| G12 | VPN peer handshake |
| G13 | Maintenance ≤ 20 min |
| G14 | Rollback rehearsed on staging |

---

## P6 — Cleanup

Remove proxy code, sidecar settings, update docs, archive `wireguard/dashboard/`, tag v2.0.0

**Gate P6:** No `wg-dashboard:8185` in code; gate-local without sidecar; browser 375/768/1440; 24h poll health; backup script updated.

---

## Architecture (final)

```mermaid
flowchart TB
  subgraph nc [cloud_app]
    Vue[Vue SPA]
    API[Native API]
    Poll[occ poll-metrics]
    DB[(NC metrics DB)]
    Wg[WgEasyClient]
    Host[SystemMetricsAdapter]
  end
  Timer[systemd 30s] --> Poll
  Vue --> API --> DB
  Poll --> Wg --> WgEasy[wg-easy]
  Poll --> Host
  Host --> Reuse[nc-gcs host API if found]
  Host --> Proc["/host/proc fallback"]
```

**Effort:** ~13–17 dev days across P0–P6.

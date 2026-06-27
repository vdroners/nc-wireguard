# Host metrics audit — NC-GCS reuse vs `/host/proc` fallback

**Date:** 2026-06-27  
**Scope:** P0 migration decision for `SystemMetricsCollector` in nc-wireguard v2.0  
**Requirement (sidecar):** Every poll inserts `{cpu_percent, mem_percent, disk_percent, net_rx_bytes, net_tx_bytes}` into `system_metrics` using `psutil` on the **same host** that runs the poller.

---

## Candidates reviewed in `/media/4TB/nc-gcs/services/`

| Location | What it exposes | Reusable for nc-wireguard? |
|----------|-----------------|---------------------------|
| `mavlink-gateway/app/main.py` → `GET /api/system/status` | `host_cpu_pct`, `memory_pct`, `disk_pct`, gateway process CPU, vehicle counts, uptime | **No** — see below |
| `mavlink-gateway/app/routers/base_station.py` | Remote base-station `cpu_percent` / `mem_percent` via TSDB | **No** — measures **edge base stations**, not the NC host |
| `mavlink-gateway/app/tsdb_writer.py` | Persists base-station telemetry samples | **No** — different device class |
| `sim/backend/web_app.py` | psutil health inside **sim container** | **No** — wrong cgroup/namespace |
| `sim/simcam/server.js` | Stub `cpu_percent: 0` | **No** |
| `vpn-manager/app/main.py` | Reads `/host/proc/1/root/...` for systemd visibility only | **Pattern reference only** — not a metrics API |

Frontend references (`apps/nc_gcs/.../BaseStationHistory.vue`, `SimDashboard.vue`) consume gateway or sim endpoints above; none provide a drop-in host time-series API for WireGuard.

---

## Why mavlink-gateway `/api/system/status` is not reused

1. **Different runtime.** After cutover the poller runs as `occ nc_wireguard:poll-metrics` inside **`cloud_app`**, not inside `mavlink_gateway`. Gateway psutil reads the gateway container filesystem/CPU accounting, not the Nextcloud host view the sidecar currently records.

2. **Auth coupling.** Gateway status requires a validated NC/GCS bearer token (`Depends(get_current_user)`). The WireGuard poller is an unattended cron job; wiring it through gateway auth adds failure modes and couples two apps unnecessarily.

3. **Schema mismatch.** Sidecar/`/api/system` returns a **time series** with **`net_rx_bytes` / `net_tx_bytes`**. Gateway status returns a **single snapshot** without cumulative network counters — the System tab charts would lose the net I/O series.

4. **Semantic mismatch.** Gateway payload includes `connected_vehicles`, `websocket_clients`, and process-scoped CPU intended for GCS health — not VPN dashboard host monitoring.

---

## Decision

**Do not reuse NC-GCS host metrics routes.** Implement **`HostProcCollector`** in nc-wireguard:

- Bind-mount host `/proc` read-only into `cloud_app` at `/host/proc` (same pattern as `vpn-manager` systemd checks).
- PHP collector reads `/host/proc/stat`, `/host/proc/meminfo`, `/host/proc/diskstats` or mountinfo + `statvfs`, and `/host/proc/net/dev` for aggregate rx/tx bytes.
- Keep instantaneous gauges on `/api/summary` (`cpu`, `mem`, `disk`) aligned with the latest poll row.

**Fallback if `/host/proc` mount is unavailable:** run the poll timer as a **host systemd unit** (outside Docker) invoking `occ nc_wireguard:poll-metrics` with a Unix socket or localhost-only trigger — still no dependency on mavlink-gateway.

---

## Follow-up (P2 / P5)

- [ ] Add optional compose snippet in `docs/ops/` for `/host/proc:/host/proc:ro` on `cloud_app`
- [ ] Unit tests for `HostProcCollector` with fixture files under `tests/fixtures/proc/`
- [ ] Document mount requirement in operator runbook before maintenance cutover

See also [`ARCHITECTURE.md`](ARCHITECTURE.md) (final native topology).

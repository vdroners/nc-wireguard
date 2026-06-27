# API parity (native NC WireGuard dashboard)

**v2.0.0:** All routes below are served natively from NC MySQL + `WgEasyClient`. Golden JSON fixtures in [`tests/fixtures/sidecar/`](../tests/fixtures/sidecar/) preserve the response shapes originally captured from the archived wg-dashboard sidecar (2026-06-27).

## Route map

| NC app route | Vue helper | Auth |
|--------------|------------|------|
| `GET /apps/nc_wireguard/api/dashboard/summary` | `fetchSummary()` | NC admin |
| `GET …/api/dashboard/bandwidth` | `fetchBandwidth(params)` | NC admin |
| `GET …/api/dashboard/connections` | `fetchConnections(params)` | NC admin |
| `GET …/api/dashboard/geoip` | `fetchGeoip()` | NC admin |
| `GET …/api/dashboard/system` | `fetchSystem(params)` | NC admin |
| `GET …/api/dashboard/health` | — | NC admin |
| `GET /apps/nc_wireguard/api/status` | `fetchStatus()` | any logged-in user* |
| `GET …/api/wg-easy/{id}/configuration` | `fetchPeerConfig(id)` | NC admin |

\* `/api/status` returns app metadata to all users; health fields are populated only when the caller is an NC admin and the dashboard is enabled.

Whitelist (`DashboardProxyController`): `summary`, `bandwidth`, `connections`, `geoip`, `system`, `health`.

---

## `GET /api/summary`

Live snapshot of wg-easy clients plus instantaneous host gauges.

### Response fields

| Field | Type | Notes |
|-------|------|-------|
| `clients` | `object[]` | One row per peer |
| `clients[].id` | `integer` | wg-easy client id |
| `clients[].name` | `string` | Display name |
| `clients[].ipv4Address` | `string` | Assigned tunnel IPv4 |
| `clients[].connected` | `boolean` | `latestHandshakeAt` within 180 s |
| `clients[].endpoint` | `string\|null` | Remote `host:port` or `[ipv6]:port` |
| `clients[].latestHandshakeAt` | `string\|null` | ISO-8601 UTC |
| `clients[].transferRx` | `integer` | Cumulative bytes received (camelCase) |
| `clients[].transferTx` | `integer` | Cumulative bytes sent |
| `clients[].enabled` | `boolean` | Peer enabled in wg-easy |
| `clients[].expiresAt` | `string\|null` | Optional expiry |
| `connectedCount` | `integer` | Peers passing handshake FSM |
| `totalClients` | `integer` | `len(clients)` |
| `totalRx` | `integer` | Sum of `transferRx` |
| `totalTx` | `integer` | Sum of `transferTx` |
| `serverBootTime` | `string` | Host boot time ISO-8601 UTC |
| `cpu` | `number` | Instant `psutil.cpu_percent` |
| `mem` | `number` | Instant memory % |
| `disk` | `number` | Instant disk usage % on `/` |

### NC API errors

| HTTP | Body `reason` | Cause |
|------|---------------|-------|
| 403 | `no_permission` | Non-admin |
| 503 | `disabled` | Dashboard disabled in app settings |
| 400 | `bad_path` | Path not on whitelist / traversal |
| 502 | `error` key | wg-easy unreachable or summary build failure |

Fixture: [`tests/fixtures/sidecar/summary.json`](../tests/fixtures/sidecar/summary.json).

---

## `GET /api/bandwidth`

Historical cumulative transfer samples from SQLite `bandwidth_log` (one row per client per poll).

### Query parameters

| Param | Type | Default | Range | Notes |
|-------|------|---------|-------|-------|
| `hours` | `integer` | `24` | 1–720 | Rolling window |
| `client_id` | `integer` | — | ≥1 | Optional filter |

### Response

JSON array of objects:

| Field | Type | Notes |
|-------|------|-------|
| `ts` | `string` | ISO-8601 UTC poll timestamp |
| `client_id` | `integer` | wg-easy client id |
| `name` | `string` | Peer name at sample time |
| `transfer_rx` | `integer` | Cumulative rx bytes (**snake_case** in DB API) |
| `transfer_tx` | `integer` | Cumulative tx bytes |

Vue derives rates in `utils/bandwidth-rates.js` by differencing consecutive samples per `client_id`.

Fixture: [`tests/fixtures/sidecar/bandwidth.json`](../tests/fixtures/sidecar/bandwidth.json) (trimmed to last 20 rows; live responses are larger).

---

## `GET /api/connections`

Connection/disconnection events from SQLite `connection_log`.

### Query parameters

| Param | Type | Default | Range | Notes |
|-------|------|---------|-------|-------|
| `days` | `integer` | `7` | 1–365 | Rolling window |
| `client_id` | `integer` | — | ≥1 | Optional filter |

### Response

JSON array (newest first via `ORDER BY ts DESC`):

| Field | Type | Notes |
|-------|------|-------|
| `ts` | `string` | Event time ISO-8601 UTC |
| `client_id` | `integer` | |
| `name` | `string` | |
| `event` | `string` | `"connected"` \| `"disconnected"` |
| `endpoint` | `string\|null` | Remote endpoint at event |
| `geo` | `object\|undefined` | Present when GeoIP cached for endpoint IP |
| `geo.country` | `string` | |
| `geo.country_code` | `string` | |
| `geo.city` | `string` | |
| `geo.lat` | `number` | |
| `geo.lon` | `number` | |
| `geo.isp` | `string` | |

Fixture: [`tests/fixtures/sidecar/connections.json`](../tests/fixtures/sidecar/connections.json).

---

## `GET /api/geoip`

Full GeoIP cache table (`geoip_cache`).

### Response

JSON array:

| Field | Type |
|-------|------|
| `ip` | `string` |
| `country` | `string\|null` |
| `country_code` | `string\|null` |
| `city` | `string\|null` |
| `region` | `string\|null` |
| `lat` | `number\|null` |
| `lon` | `number\|null` |
| `isp` | `string\|null` |
| `queried_at` | `string` |

Fixture: [`tests/fixtures/sidecar/geoip.json`](../tests/fixtures/sidecar/geoip.json).

---

## `GET /api/system`

Host metrics time series from SQLite `system_metrics` (polled every `POLL_INTERVAL`, default 30 s).

### Query parameters

| Param | Type | Default | Range |
|-------|------|---------|-------|
| `hours` | `integer` | `24` | 1–720 |

### Response

JSON array:

| Field | Type | Notes |
|-------|------|-------|
| `ts` | `string` | ISO-8601 UTC |
| `cpu_percent` | `number` | |
| `mem_percent` | `number` | |
| `disk_percent` | `number` | Root mount `/` |
| `net_rx_bytes` | `integer` | `psutil.net_io_counters().bytes_recv` |
| `net_tx_bytes` | `integer` | `psutil.net_io_counters().bytes_sent` |

Fixture: [`tests/fixtures/sidecar/system.json`](../tests/fixtures/sidecar/system.json) (trimmed to last 20 rows).

---

## `GET /api/dashboard/health` and `GET /api/status`

Vue calls `/api/status` for banner chips. Admins also receive aggregated native health from `NativeHealthService`.

### Native `/api/dashboard/health` fields

| Field | Type | Notes |
|-------|------|-------|
| `status` | `string` | `"ok"` when poller + wg-easy healthy |
| `version` | `string` | NC app version |
| `wg_easy` | `boolean` | Client list fetch succeeded |
| `poller` | `boolean` | Heartbeat fresh within threshold |
| `host_metrics` | `boolean` | `/host/proc` or `/proc` readable |

### NC `GET /api/status` fields

| Field | Type | Notes |
|-------|------|-------|
| `app_id` | `string` | `nc_wireguard` |
| `version` | `string` | NC app version from config |
| `enabled` | `boolean` | Dashboard feature flag |
| `native_ok` | `boolean` | Admin + enabled + health `status==ok` |
| `wg_easy_ok` | `boolean` | From native health |
| `poller_ok` | `boolean` | Heartbeat fresh |
| `host_metrics_ok` | `boolean` | Host proc collector readable |
| `wg_easy_admin_url` | `string` | External wg-easy UI link |
| `is_admin` | `boolean` | |
| `health` | `object\|null` | Native health or `null` |

Fixture (historical sidecar health shape): [`tests/fixtures/sidecar/status.json`](../tests/fixtures/sidecar/status.json).

---

## Peer configuration

NC: `GET /apps/nc_wireguard/api/wg-easy/{clientId}/configuration` (via `WgEasyClient`, wg-easy v14+ `/api/client/{id}/configuration`).

### Success response

WireGuard `.conf` text wrapped when wg-easy returns plain text:

```json
{ "configuration": "[Interface]\nPrivateKey = …\n…" }
```

If wg-easy returns JSON, the sidecar passes it through unchanged. Vue accepts `configuration`, `config`, raw string, or pretty-printed JSON (`PeerConfigModal.vue`).

### Errors

| HTTP | Body |
|------|------|
| 403 | `{"message":"Forbidden","reason":"no_permission"}` |
| 503 | `{"message":"Disabled","reason":"disabled"}` |
| 400 | Invalid `clientId` |
| 502 | `{"error":"wg-easy configuration fetch failed"}` |

Fixture (redacted success shape): [`tests/fixtures/sidecar/config.json`](../tests/fixtures/sidecar/config.json).

---

## Naming conventions (native backend must preserve)

| Context | Convention | Example |
|---------|------------|---------|
| Live summary clients | camelCase | `transferRx`, `ipv4Address` |
| Historical bandwidth rows | snake_case | `transfer_rx`, `client_id` |
| System metrics rows | snake_case | `cpu_percent`, `net_rx_bytes` |
| NC JSON error envelope | camelCase keys optional; always include human `message` and machine `reason` where applicable |

---

## Vue cross-check

| Tab / component | API calls | Key fields consumed |
|-----------------|-----------|---------------------|
| Overview | `summary`, `status` | `clients`, `connected`, gauges, banner chips |
| Bandwidth | `bandwidth`, `summary` | `transfer_rx/tx`, `client_id`, filters |
| Connections | `connections` | `event`, `endpoint`, `geo` |
| Map | `geoip`, `connections` | `lat`, `lon`, `country_code` |
| System | `system`, `summary` | `cpu_percent`, `mem_percent`, `disk_percent`, net counters |
| Peer config modal | `wg-easy/{id}/configuration` | `configuration` text |

Native PHP controllers (P3) must return byte-identical JSON shapes vs these fixtures for parity PHPUnit tests.

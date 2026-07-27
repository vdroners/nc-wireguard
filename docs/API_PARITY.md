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
| `GET …/api/peers/{id}/configuration` | `fetchPeerConfig(id)` | NC admin |
| `GET …/api/wg-easy/{id}/configuration` | `fetchPeerConfig(id)` fallback | NC admin |
| `POST …/api/peers` | `createPeer(body)` | NC admin + CSRF |
| `POST …/api/peers/{id}` | `updatePeer(id, body)` | NC admin + CSRF |
| `DELETE …/api/peers/{id}` | `deletePeer(id)` | NC admin + CSRF |
| `POST …/api/peers/{id}/enable` | `enablePeer(id)` | NC admin + CSRF |
| `POST …/api/peers/{id}/disable` | `disablePeer(id)` | NC admin + CSRF |
| `POST …/api/peers/{id}/one-time-link` | `generatePeerOtl(id)` | NC admin + CSRF |
| `GET …/api/peers/otl/{token}` | OTL redeem (`.conf` download) | NC admin |

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

## Peer write API (v2.1)

Admin-only **and** CSRF-protected — no mutating route carries `NoCSRFRequired`,
so callers must send the Nextcloud request token (`@nextcloud/axios` does this
automatically). Every request is audit-logged with actor UID, action, client id
and upstream HTTP code.

### Request body (create and update)

| Field | Type | Rules |
|-------|------|-------|
| `name` | `string` | Required on create; 1–128 chars, no control characters |
| `expiresAt` | `string\|null` | `YYYY-MM-DD` or ISO-8601 date-time; `null` clears expiry |
| `allowedIps` | `string\|string[]\|null` | CSV or array of CIDR entries; `null` = server default |
| `dns` | `string\|string[]\|null` | CSV or array of IP addresses (hostnames rejected) |
| `mtu` | `integer` | 1024–9000 |
| `persistentKeepalive` | `integer` | 0–65535 seconds |

Updates may send any subset; omitted fields keep their current value.

### Errors

| HTTP | Body | Cause |
|------|------|-------|
| 400 | `{"error":"Validation failed","reason":"validation_failed","fields":{…}}` | Per-field validation messages |
| 401 | `{"message":"Current user is not logged in"}` | No Nextcloud session (verified) |
| 403 | `{"message":"Forbidden","reason":"no_permission"}` | Non-admin |
| 404 | `{"error":…,"reason":"not_found"}` | Unknown client id |
| 412 | — | Missing/stale CSRF token (raised by Nextcloud before the controller) |
| 422 | `{"error":…,"reason":"rejected"}` | wg-easy refused (e.g. enabling an expired peer) |
| 502 | `{"error":…,"reason":"auth_failed"\|"totp_required"}` | wg-easy session unusable |
| 503 | `{"message":"Disabled","reason":"disabled"}` | Dashboard disabled, or wg-easy URL unset |

### One-time link

`POST …/api/peers/{id}/one-time-link` →
`{success, oneTimeLink, redeemPath, redeemUrl, expiresAt}`.

| Field | Meaning |
|-------|---------|
| `oneTimeLink` | Raw wg-easy token, read back from the client list (see below) |
| `redeemPath` | `/cnf/{token}` — wg-easy's own route, **unauthenticated** |
| `redeemUrl` | Absolute NC route `GET …/api/peers/otl/{token}`, **admin-gated** |
| `expiresAt` | Token expiry; wg-easy mints these with a ~5 minute TTL |

Both redeem paths are single-use: wg-easy erases the token as soon as it serves
the config.

Because `redeemUrl` runs through `PeerWriteController::redeemOtl()` it inherits
the same admin gate as every other write route, so it is **not** a link you can
hand to a non-admin field user. It exists so an admin can pull the config
without wg-easy being published. To hand a config to someone else, use the
`.conf` download or QR from the peer config modal.

---

## wg-easy v15 upstream contract (verified)

Read out of the running container's route table and zod schemas
(`/app/server/chunks/…`) rather than assumed — the paths below are what this app
targets. Re-verify on any wg-easy image bump.

| Action | Method / path | Notes |
|--------|---------------|-------|
| Login | `POST /api/session` | `{username, password, remember}` |
| List | `GET /api/client` | Includes `oneTimeLink`; omits `privateKey`/`preSharedKey` |
| Get one | `GET /api/client/{id}` | **Excludes** `oneTimeLink`; includes secrets |
| Create | `POST /api/client` | Accepts **only** `{name, expiresAt}` → `{success, clientId}` |
| Update | `POST /api/client/{id}` | Full object; **not** partial |
| Delete | `DELETE /api/client/{id}` | |
| Enable / disable | `POST /api/client/{id}/enable\|disable` | Enabling an expired peer → 422 |
| One-time link | `POST /api/client/{id}/generateOneTimeLink` | Returns `{success:true}` only |
| Config | `GET /api/client/{id}/configuration` | `.conf` text |
| OTL redeem | `GET /cnf/{token}` | wg-easy route, unauthenticated |

Three consequences shape `WgEasyClient`:

1. **Create cannot carry tunnel fields.** `allowedIps`, `dns`, `mtu` and
   `persistentKeepalive` are applied by a follow-up update.
2. **Update is read-modify-write.** `ClientUpdateSchema` has no `.partial()`, so
   omitting a key is a 400. Updates fetch the peer, merge, then send every key.
3. **The OTL token is not in the mint response.** It has to be read back from
   the client list, where it appears as `oneTimeLink.oneTimeLink`.

Field names are camelCase upstream: `allowedIps`, `persistentKeepalive`, `dns`,
`mtu`, `ipv4Address`. `expiresAt` is nullable but **not optional** on create, so
the key must always be present.

Login has one further trap: a service account with 2FA gets **HTTP 200** with
`{"status":"TOTP_REQUIRED"}`, not a 4xx. Status code alone cannot detect it.

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

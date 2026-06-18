# API parity (wg-dashboard sidecar → NC proxy)

NC app calls `/apps/nc_wireguard/api/dashboard/{path}` which proxies to sidecar `/api/{path}`.

| Sidecar | NC proxy path | Notes |
|---------|---------------|-------|
| `GET /api/summary` | `summary` | snake_case preserved |
| `GET /api/bandwidth` | `bandwidth` | `hours`, `client_id` query |
| `GET /api/connections` | `connections` | `days`, `client_id` query |
| `GET /api/geoip` | `geoip` | |
| `GET /api/system` | `system` | `hours` query |
| `GET /api/health` | via `/api/status` | |
| `GET /api/wg/client/{id}/configuration` | `/api/wg-easy/{id}/configuration` | read-only |

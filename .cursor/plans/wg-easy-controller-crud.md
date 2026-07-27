# nc_wireguard 2.1 — wg-easy peer controller

**Status:** implementing  
**Bump:** 2.0.2 → 2.1.0 (minor — monitor → controller)

## Scope

Promote NC WireGuard from read-only monitor to sole operator UI for peer management over wg-easy v15.

### In 2.1

- `WgEasyClient` write: create, update, delete, enable, disable, generateOneTimeLink
- Admin-only write controller with **CSRF required** + audit log
- Routes under `/api/peers/...` (aliases for `/api/wg-easy/...` config)
- PeerFormModal: name, expiry, AllowedIPs, DNS, MTU, keepalive + Field/Admin presets
- `.conf` download + QR + OTL generate (NC-proxied redeem preferred)
- Hide “Edit in wg-easy”; unpublish public `:51821` / Caddy UI
- TOTP policy: service account must keep 2FA **off**

### Out of scope

- Full server admin (CIDR/hooks/restart) in NC
- Replacing wg-easy engine
- Flask sidecar resurrection
- Tailscale

## wg-easy v15 write contract (lab)

| Action | Method / path | Body notes |
|---|---|---|
| Create | `POST /api/client` | `{ name, expiresAt? }` → `{ clientId }` (verify live) |
| Update | `POST /api/client/:id` | name, expiresAt, allowedIPs, dns, mtu, persistentKeepalive, … |
| Delete | `DELETE /api/client/:id` | |
| Enable / disable | `POST …/enable`, `…/disable` | |
| Config | `GET …/configuration` | already in NC |
| OTL | `POST …/generateOneTimeLink` | returns one-shot token/URL |
| Session | `POST /api/session` | username/password; fails if TOTP required |

Pin image `ghcr.io/wg-easy/wg-easy:15`; re-smoke writes on upgrade.

## Security

- `AdminRequired` on all write routes
- No `NoCSRFRequired` on mutating peer APIs
- Never log private keys / full `.conf` bodies
- Audit: actor UID, action, clientId, http_code

## Gates

```bash
make bump-minor   # 2.1.0
make build
make test
make deploy-docker
make health
# optional: SMOKE_CRUD=1 against disposable peer
```

## Cutover

1. Ship CRUD + browser smoke  
2. Same day: unpublish host `:51821` and Caddy admin UI  
3. Keep `cloud_app` → `http://wg-easy:51821` on `wireguard_default`  
4. Optional break-glass: `127.0.0.1:51821`

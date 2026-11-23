# ncwg-endpoint-host progress

**Job** `ncwg-endpoint-host` · **Branch** `fix/ncwg-admin-settings` · **Worktree** `/media/4TB/wt/ncwg-admin-settings`
**Follow-up to** `ncwg-admin-settings` @ `319106e`

## Status

Complete.

## Follow-up: endpoint host

### Fail-first (red)

vitest (4 failed / 8 passed):

```
FAIL  AdminSettings.vue > Health card shows connected vs not-connected; never unknown; no idle warning
AssertionError: expected '…' to match /2 of 4 peers connected recently/
FAIL  AdminSettings.vue > shows VPN endpoint host from API; port read-only
AssertionError: expected '…' to contain 'VPN endpoint'
FAIL  AdminSettings.vue > invalid hostnames block Save with a message and send no POST
Error: find did not return [data-testid="endpoint-host"] input
FAIL  AdminSettings.vue > valid host POSTs only {host}; success note only after 2xx; failure keeps old value
TypeError: Cannot read properties of undefined (reading 'value')
```

PHPUnit filter EndpointHost|EndpointTest|HandshakeStats (6 errors / 1 failure):

```
Error: Call to undefined method SettingsController::isValidEndpointHost()
MethodCannotBeConfiguredException: updateEndpointHost does not exist on WgEasyClient
Error: Call to undefined method PollStateMapper::aggregateHandshakeFlags()
Error: Call to undefined method RecordingWgEasyClient::updateEndpointHost()
```

### What changed

- `lib/Service/WgEasyClient.php:153+` — `getUserConfig()` + `updateEndpointHost()` (GET full userconfig, change only `host`, POST full object); `getServerDefaults()` uses userconfig host/port as Endpoint; `adminUiHost` keeps admin-URL host; `authedJson` protected for test double.
- `lib/Controller/SettingsController.php` — `getEndpoint` / `updateEndpoint` (admin, CSRF on write, `PasswordConfirmationRequired`); hostname validation; audit log old→new host; stale heartbeat → status unknown + `wg_easy_ok` null; handshake JSON keys `peers_connected` / `peers_not_connected` / `peers_never_handshake=null`.
- `appinfo/routes.php` — `GET`/`POST /api/settings/endpoint`.
- `lib/Db/PollStateMapper.php:52+` — disconnected ≠ never; `aggregateHandshakeFlags()`; `never` always null.
- `src/views/AdminSettings.vue` — VPN endpoint card (host editable, port read-only, save behind password confirm, note about re-import); health copy + no idle warning; legacy unused vs wg-sync notes.
- `src/components/tabs/SystemTab.vue:15+` — "Endpoint (in client configs)" + "Engine admin URL host".
- Tests: `tests/Unit/Service/WgEasyClientEndpointHostTest.php`, `SettingsControllerEndpointTest.php`, `PollStateMapperHandshakeStatsTest.php`, `RecordingWgEasyClient.php`; health + vitest updates.
- Version **2.3.7** (`appinfo/info.xml`, `package.json`, `CHANGELOG.md`).

### Tests

| Suite | Baseline (post admin-settings) | After |
|-------|--------------------------------|-------|
| PHPUnit | 186 OK / 4 deprecations | 194 OK / 4 deprecations |
| vitest | 9 passed (2 files) | 12 passed (2 files) |
| npm run build | OK | OK (webpack 2 size warnings; admin bundle guard clean) |

### Partial items

_(none)_

### Found, not fixed

- `js/nc_wireguard-admin.js` still ~3.17 MiB with `@nextcloud/vue` (build warning only; not committed).

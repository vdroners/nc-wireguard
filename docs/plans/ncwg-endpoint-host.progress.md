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

---

## Fix round 1 (ncwg-endpoint-fix1)

**Job** `ncwg-endpoint-fix1` · **Builder** cursorjob (auto) · **Base** `f24f572`

### Fail-first (red)

vitest on f24f572 after test-only edits (6 failed / 12 passed):

```
FAIL  AdminSettings.vue > Health card … warning when not-connected > 0
AssertionError: expected false to be true  (warning note absent)
FAIL  AdminSettings.vue > stale health still shows poll, error, summary and warning
AssertionError: expected '…Status: unknown…' to match /wg_easy_ok:\s*unknown/
FAIL  AdminSettings.vue > endpoint GET failure shows unknown host with reason
AssertionError: expected '…' to match /Current endpoint host:\s*unknown/
FAIL  AdminSettings.vue > endpoint host null shows unknown with reason…
AssertionError: expected '…' to match /Current endpoint host:\s*unknown/
FAIL  AdminSettings.vue > invalid hostnames block Save with error note…
AssertionError: expected undefined to be truthy  (1.2.3 accepted; weak label match removed)
FAIL  SystemTab.vue > shows unknown with endpointError when host is null
TypeError: Unknown file extension ".css" for …/NcActionButton-….css
```

PHPUnit filter (2 failures):

```
SettingsControllerEndpointTest::testIsValidEndpointHostRejectsSchemePortSpaces
Failed asserting that true is false.  (999.1.1.1 accepted)
WgEasyClientEndpointHostTest::testGetServerDefaultsUserconfigFailureSetsEndpointErrorWithoutSecrets
Failed asserting that an array has the key 'endpointError'.
```

### What changed

- `src/views/AdminSettings.vue:39-44` — warning on `peers_not_connected > 0`; heading "Peers without a recent handshake"; body "N of M peers have not handshaken recently".
- `src/views/AdminSettings.vue:18-45` — stale (`known !== false`) still renders last poll / wg_easy_ok / error / summary / warning under Status unknown.
- `src/views/AdminSettings.vue:73-80,341-380` — unreadable endpoint shows "Current endpoint host: unknown — \<reason\>"; `endpointHostSaved` stays null (blank is not the saved host); all-numeric dotted host validator aligned with server.
- `lib/Service/WgEasyClient.php:183-230` — `endpointError: 'userconfig could not be read'` when userconfig fails (no secrets).
- `src/components/tabs/SystemTab.vue:17-21,108-124` — endpoint/port show `unknown — <endpointError>` instead of bare `—`.
- `lib/Controller/SettingsController.php:266-283` — all-numeric dotted strings must be valid IPv4.
- `CHANGELOG.md` 2.3.7 — warning / stale / endpoint unknown / IPv4 validator copy.
- Tests: `tests/js/AdminSettings.test.js`, `tests/js/SystemTab.test.js`, PHPUnit endpoint/validator/userconfig cases.

### Tests

| Suite | Baseline (f24f572) | After |
|-------|--------------------|-------|
| PHPUnit | 194 OK / 4 deprecations | 195 OK / 4 deprecations |
| vitest | 12 passed | 18 passed (3 files) |
| npm run build | OK | OK (webpack 2 size warnings; admin bundle guard clean) |

### Partial items

_(none)_

### Found, not fixed

- `js/nc_wireguard-admin.js` still ~3.17 MiB with `@nextcloud/vue` (build warning only; not committed).

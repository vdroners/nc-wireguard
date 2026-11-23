# ncwg-admin-settings progress

**Job** `ncwg-admin-settings` · **Branch** `fix/ncwg-admin-settings` · **Worktree** `/media/4TB/wt/ncwg-admin-settings`

## Status

Complete.

## Fail-first (red)

Runtime-only blank demo (`tests/js/adminPanel.runtimeBlank.test.js`) confirms the
old `template:` string panel renders no fields under `vue.runtime.esm.js`
(passes as a bug demonstration). Behavioral suite against missing SFC:

```
 FAIL  tests/js/AdminSettings.test.js [ tests/js/AdminSettings.test.js ]
Error: Failed to resolve import "../../src/views/AdminSettings.vue" from "tests/js/AdminSettings.test.js". Does the file exist?
  Plugin: vite:import-analysis
  File: /media/4TB/wt/ncwg-admin-settings/tests/js/AdminSettings.test.js:70:50
  70 |
  71 |  async function mountAdmin() {
  72 |  	const { default: AdminSettings } = await import('../../src/views/AdminSettings.vue')
     |                                                   ^
 Test Files  1 failed (1)
      Tests  no tests
```

## What changed

- `src/admin.js:1-11` — thin mount only (`render: h => h(AdminSettings)`); no string `template:`.
- `src/views/AdminSettings.vue:1+` — SFC admin panel with all prior fields/behaviour + NC vue 8 components + version from `__NC_WIREGUARD_VERSION__` + Metrics health card + legacy key names.
- `lib/Controller/SettingsController.php:getHealth` — admin-only GET; counts/timestamps/legacy names only.
- `appinfo/routes.php` — `GET /api/settings/health`.
- `lib/Db/PollStateMapper.php:countHandshakeStats` — total / with_handshake / never counts.
- `lib/Service/AppSettings.php` — `LEGACY_APPCONFIG_KEYS` + `listPresentLegacyKeys()`; documents unused `dashboard_internal_url`.
- `INSTALL.md` — Legacy keys section.
- `appinfo/info.xml` / `package.json` — version **2.3.6**; `CHANGELOG.md` entry; `"test:js": "vitest run"`.
- `vitest.config.mjs` + `tests/js/**` — mount tests, runtime blank fixture, build guard.
- `tests/Unit/Controller/SettingsControllerHealthTest.php` + AppSettings legacy-keys assertion.

## Tests

| Suite | Baseline | After |
|-------|----------|-------|
| PHPUnit | 183 OK / 4 deprecations | 186 OK / 4 deprecations |
| vitest | no JS tests | 9 passed (2 files) |
| npm run build | — | OK (admin bundle no longer contains `nc-wireguard-admin-panel" v-if`) |

## Partial items

_(none)_

## Found, not fixed

- `js/nc_wireguard-admin.js` admin entry ~3.16 MiB after pulling `@nextcloud/vue` into the admin bundle (build warning only; not committed).

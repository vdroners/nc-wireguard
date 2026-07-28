# NC WireGuard — App Store publication readiness

Plan for publishing `nc_wireguard` to the [Nextcloud App Store](https://apps.nextcloud.com) and as signed GitHub release tarballs.

## Store contract (locked)

**Requires a reachable WireGuard engine** (URL + credentials). This is **not** a one-click VPN appliance and does **not** ship an AppAPI ExApp dataplane.

| Today | Later (same app) |
|---|---|
| **wg-easy** (external container) | **wg-sync** thin sidecar + NC peer store |

Operators must configure engine API URL / username / password in Admin settings before the dashboard can manage peers or poll metrics.

## Status (2026-07)

| Guardrail | Status |
|---|---|
| Standalone npm (`npm ci` without NC-GCS) | Done (no Vue `link:` to sibling repo) |
| Local `NcAppShell` (no ThemeAssetLoader / theme mirror) | Done |
| GeoIP default OFF + disclosure | Done (v2.x) |
| `website` / `bugs` / `repository` in info.xml | Done |
| Release tarball includes built `js/` / `css/` / `vendor/` | `make appstore` |
| Tag ↔ tarball name | CI uses `info.xml` version for `nc_wireguard-<ver>.tar.gz` |
| Trademark disclaimer | info.xml + admin + dashboard footer |
| Screenshots | `docs/screenshots/` (replace tiny placeholders before store submit) |
| EN l10n for primary strings | In progress (`l10n/en.json`) |
| Multi-DB migration CI | Nice-to-have, not a cutover blocker |

## Deferred product (document only — not store blockers)

- Server write UI before NativeEngine
- Amnezia UI / hooks editor in NC
- CSV export; Chart.js rewrite
- Non-admin peer CRUD
- Portainer WG UI

## Verification before submit

1. Clean clone: `npm ci && make test && make build` (no `/media/4TB/nc-gcs`)
2. `GATE_PEER_WRITES=1 make gate-local` with engine reachable
3. Fresh vanilla NC: admin dashboard; non-admin blocked
4. Uninstall drops `nc_wg_*` tables
5. Signed tarball: `occ integrity:check-app nc_wireguard`
6. Release tag = bare semver matching `info.xml` (prefer `2.3.0`, not `v2.3.0`)

## References

- [Release Automation](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/release_automation.html)
- [App store rules](https://docs.nextcloud.com/server/27/developer_manual/app_publishing_maintenance/publishing.html)
- Engine migration program: `.cursor/plans/leave-wg-easy-engine.md`

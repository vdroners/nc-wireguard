# NC WireGuard — App Store publication readiness

Plan for publishing `nc_wireguard` to the [Nextcloud App Store](https://apps.nextcloud.com) and as signed GitHub release tarballs. Target: vanilla Nextcloud (no NC-GCS build dependency).

## Store guardrails

- AGPL-3.0-or-later (root `LICENSE` required)
- Public `OCP\` API only
- Trademark disclaimer: WireGuard® (Jason A. Donenfeld), wg-easy third-party
- Admin-only sensitive data; GeoIP opt-in with disclosure
- DB portable (sqlite/mysql/pgsql); uninstall drops `nc_wg_*` tables
- EN + DE `l10n/` and store screenshots

## P0 — Blocks publication

| ID | Finding | Location | Remediation |
|----|---------|----------|-------------|
| P0-1 | Frontend build hard-depends on NC-GCS mirror | `Makefile:7-11`, `webpack.config.js`, `sync-theme-from-nc-gcs.sh` | Vendor minimal shell into `src/` |
| P0-2 | Release tarball missing built `js/`/`css/`/`vendor/` | `.gitignore` | Ship in appstore tarball via CI |
| P0-3 | GeoIP sends peer IPs to ip-api.com HTTP, default ON | `GeoIpService.php:71`, `AppSettings.php:108-111` | Default OFF; configurable provider |
| P0-4 | Org defaults `vpn-vdroners.ddns.net`, `wg-easy:51821` | `PageController.php:35-38`, `AppSettings.php:43-45`, `main.js:15` | Empty defaults |

## P1 — Store compliance

| ID | Finding | Location | Remediation |
|----|---------|----------|-------------|
| P1-1 | Missing `website`/`bugs`/`repository` in info.xml | `appinfo/info.xml` | Add metadata + root LICENSE |
| P1-2 | No `l10n/` or screenshots | — | Add EN/DE + PNGs |
| P1-3 | Page/API admin gating non-idiomatic; nav visible to all | Controllers, `WireGuardDashboard.vue:119-120` | `AdminRequired`; stop poll on 403 |
| P1-4 | `wg_easy_admin_url` exposed to non-admins | `ApiController.php:61-76` | Admin-only status fields |
| P1-5 | `package-lock.json` gitignored | `.gitignore:10` | Track for reproducible CI |
| P1-6 | README version 2.0.0 vs info.xml 2.0.1 | `README.md:3` | Sync |
| P1-7 | Trademark / non-affiliation disclaimer missing | info.xml, README | Add disclaimer text |
| P1-8 | No uninstall migration | — | Drop `nc_wg_*` tables + config |

## P2 — Polish

- PR CI without `cloud_app` / nc_gcs
- Secret-history scan before public repo
- Map tile + GeoIP attribution docs
- Design/a11y pass

## Verification

1. `make build && composer test` exit 0 on clean checkout (no nc_gcs)
2. Migrations + poll + prune on sqlite, mysql, pgsql
3. Fresh vanilla NC: admin dashboard loads; non-admin blocked cleanly
4. Uninstall removes all `nc_wg_*` tables
5. `occ integrity:check-app nc_wireguard` on signed tarball

## References

- [Release Automation](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/release_automation.html)
- [App store rules](https://docs.nextcloud.com/server/27/developer_manual/app_publishing_maintenance/publishing.html)

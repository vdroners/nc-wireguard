# Nextcloud App Store onboarding (operator manual steps)

These steps require account access and cannot be fully automated in CI. Complete them **before** the first public App Store submission.

## 1. Public GitHub repository

- Confirm `https://github.com/vdroners/nc-wireguard` is **public**.
- Run `./scripts/secret-history-scan.sh` and scrub any leaked secrets **before** going public.

## 2. Register app ID on the App Store developer portal

1. Sign in at [https://apps.nextcloud.com/developer](https://apps.nextcloud.com/developer) (GitHub OAuth).
2. Register app ID **`nc_wireguard`** if not already claimed.
3. Create an **API token** (`APPSTORE_TOKEN`) for release automation.

## 3. Code signing certificate

Per the [Release Automation guide](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/release_automation.html):

1. Generate a private key and CSR for the app.
2. Submit the CSR via the developer portal; download the signed certificate.
3. Store as GitHub **environment** secrets on a protected `release` environment:
   - `APP_PRIVATE_KEY` — PEM private key (full text)
   - `APP_PUBLIC_CRT` — signed certificate (full text)
   - `APPSTORE_TOKEN` — App Store API token

## 4. GitHub release workflow

1. Bump version in `appinfo/info.xml`, `package.json`, and `CHANGELOG.md`.
2. Tag and publish a GitHub Release (tag should match version, e.g. `2.0.2`).
3. The `.github/workflows/release.yml` workflow builds, signs, uploads `*.tar.gz`, and pushes to the App Store.

Local dry-run:

```bash
make appstore
export NC_OCC=/path/to/occ
export APP_PRIVATE_KEY=/path/to/app.key
export APP_PUBLIC_CRT=/path/to/app.crt
make appstore-sign
```

## 5. Store listing copy (EN + DE)

Prepare long descriptions for both languages covering:

- wg-easy integration (not an official WireGuard product).
- **Trademark disclaimer:** WireGuard is a registered trademark of Jason A. Donenfeld; not affiliated with wg-easy.
- GeoIP is **off by default**; when enabled, peer public IPs are sent to the configured provider (document ip-api.com terms or your HTTPS endpoint).
- Map tiles: CARTO/OSM attribution (see `docs/THIRD_PARTY.md`).

## 6. Post-release verification

```bash
occ integrity:check-app nc_wireguard
occ app:disable nc_wireguard && occ app:enable nc_wireguard
# After uninstall: confirm nc_wg_* tables and appconfig are removed
```

Test migrations on sqlite, mysql, and pgsql before submission.

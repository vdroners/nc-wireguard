# Install NC WireGuard

This Nextcloud app controls an **external** WireGuard engine. It is **not** a
VPN appliance and does not embed `wg` or a WireGuard kernel module. You must
run a reachable engine (wg-easy today), then point the app at its API.

## 1. Run a WireGuard engine (wg-easy)

Use the upstream image [`ghcr.io/wg-easy/wg-easy:15`](https://github.com/wg-easy/wg-easy).
Example compose (adjust host ports, passwords, and volumes for your site):

```yaml
services:
  wg-easy:
    image: ghcr.io/wg-easy/wg-easy:15
    container_name: wg-easy
    restart: unless-stopped
    environment:
      # See upstream docs for current env var names / password hashing.
      - WG_HOST=vpn.example.com
      - PASSWORD_HASH=...   # bcrypt hash of the admin password
    volumes:
      - ./config:/etc/wireguard
    ports:
      - "51820:51820/udp"   # WireGuard
      - "127.0.0.1:51821:51821/tcp"  # Admin/API — keep loopback or firewall tightly
    cap_add:
      - NET_ADMIN
      - SYS_MODULE
    sysctls:
      - net.ipv4.ip_forward=1
      - net.ipv4.conf.all.src_valid_mark=1
```

Confirm the engine UI/API answers on the URL you will configure in Nextcloud
(for example `http://wg-easy:51821` on a shared Docker network, or an
internal reverse-proxy URL). Keep the API service account **without TOTP/2FA**
— sessions cannot complete interactive MFA.

## 2. Install the Nextcloud app

1. Install from the App Store, or unpack a signed release tarball under
   `custom_apps/nc_wireguard` and run `occ app:enable nc_wireguard`.
2. Open **Settings → Administration → NC WireGuard**.
3. Set:
   - **Engine API URL** — reachable from the Nextcloud PHP container/host
   - **Engine username / password** — non-TOTP service account
   - Optional break-glass **Engine admin URL** (leave “Hide engine admin link”
     on for day-to-day use)
4. Click **Save** (password confirmation required), then **Test engine**.
5. Schedule the metrics poller, for example:

```bash
occ nc_wireguard:poll-metrics
# or a systemd timer / cron that runs the same command periodically
```

## 3. Optional: wg-sync (lab / migration only)

The `services/wg-sync` tree in the source repository is an **optional** thin
sidecar for leaving wg-easy later. It is **not** required for App Store
installs, is excluded from release tarballs, and is documented only for lab
operators. Production strangers should stay on upstream wg-easy until a
published cutover guide says otherwise.

## 4. Sidecar metrics import (optional)

`occ nc_wireguard:import-sidecar-db` / `verify-import` require an **explicit**
absolute path to a `dashboard.db`. There is no built-in host path default.

## Privacy notes

- GeoIP lookups are **off by default**. Enabling them may send peer public
  endpoint IPs to the configured provider.
- Engine credentials live in Nextcloud appconfig; saving settings requires
  recent password confirmation.

## Further reading

- [README.md](README.md) — product overview
- [docs/OPERATOR_RUNBOOK.md](docs/OPERATOR_RUNBOOK.md) — day-to-day ops
- [docs/APPSTORE_ONBOARDING.md](docs/APPSTORE_ONBOARDING.md) — publishing / signing
- Lab-only cutover notes live under `docs/ops/` in the git repository (not in
  the App Store tarball).

# wg-sync

A thin HTTP front end for kernel WireGuard. Nextcloud (`nc_wireguard`) owns
peers, key material, and addressing; wg-sync owns exactly one thing: turning a
desired peer set into `wg` state on one interface.

**Lab only right now.** Production keeps running wg-easy on `wg0` / `51820/udp`
until the P6 cutover is executed by an operator. The lab stack uses interface
`wg-lab0` on UDP `51830`, and `app.py` refuses to touch `wg0` or bind `51820`
unless `WG_SYNC_ALLOW_PROD=1` is set explicitly.

The lab tunnel's subnet comes from `nc_wg_server.cidr` and so will match
production's `10.8.0.0/24`. That is fine — the interface and its routes exist
only in the lab container's network namespace.

## Why a sidecar at all

Nextcloud runs in an unprivileged PHP container. Kernel WireGuard needs
`NET_ADMIN`, `/dev/net/tun`, and the host's module tree. Rather than making the
Nextcloud container privileged, the dataplane lives here behind a bearer-token
API on the Docker network.

It is also not "just run `wg`". wg-easy's entrypoint sets three sysctls and a
MASQUERADE rule; without them a peer completes a handshake and then reaches
nothing. `entrypoint.sh` reproduces that parity — see
[NAT and sysctl parity](#nat-and-sysctl-parity).

## Quick start (lab)

```bash
cd /media/4TB/nc-wireguard/services/wg-sync
cp .env.lab.example .env.lab
openssl rand -hex 32   # paste into WG_SYNC_TOKEN in .env.lab

docker compose -f docker-compose.lab.yml up -d --build
docker compose -f docker-compose.lab.yml logs -f wg-sync-lab
```

Point Nextcloud at it (still `engine=wgeasy`, so nothing changes yet):

```bash
docker exec cloud_app php occ config:app:set nc_wireguard wg_sync_url \
    --value 'http://wg_sync_lab:51821'
docker exec cloud_app php occ config:app:set nc_wireguard wg_sync_token \
    --value '<the same token>'
docker exec cloud_app php /var/www/html/custom_apps/nc_wireguard/scripts/smoke-native-engine.php
```

The smoke script skips itself with exit 0 when wg-sync is unreachable, so it is
safe to leave in a gate.

Tear down:

```bash
docker compose -f docker-compose.lab.yml down          # keeps the config volume
docker compose -f docker-compose.lab.yml down -v       # forgets the lab tunnel
```

## API

Every route needs `Authorization: Bearer $WG_SYNC_TOKEN`. `/health` can be
opened up with `WG_SYNC_HEALTH_PUBLIC=1` for container healthchecks; the three
mutating/reading dataplane routes never can.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/health` | interface presence, listen port, peer count, `allow_prod` |
| `POST` | `/apply` | replace the peer set and interface keys, then `wg syncconf` |
| `GET` | `/dump` | `wg show <iface> dump`, parsed to JSON |
| `POST` | `/reload` | re-apply the on-disk config unchanged |

### `POST /apply`

```json
{
  "interface": "wg-lab0",
  "private_key": "<server private key, base64>",
  "listen_port": 51830,
  "address": ["10.9.0.1/24"],
  "mtu": 1420,
  "ipv4_only": true,
  "peers": [
    {
      "name": "field-1",
      "public_key": "<peer public key>",
      "allowed_ips": ["10.9.0.5/32"],
      "preshared_key": null,
      "persistent_keepalive": 25,
      "enabled": true
    }
  ]
}
```

Rejections are deliberate, not best-effort:

- an invalid or non-32-byte key → `400`;
- a duplicate `public_key` → `400`;
- a peer with no IPv4 `allowed_ips` left → `400` (it would receive no traffic);
- `has_amnezia: true` → `422`. Kernel WireGuard has no equivalent for Amnezia's
  `jc`/`jmin`/`jmax`/`i1..i5` knobs, and silently dropping them would produce a
  peer that looks configured and cannot connect. NC refuses these too; the
  second check means a direct `curl` cannot smuggle one in.

`address` and `allowed_ips` accept either a list or a comma-separated string.
IPv6 entries are dropped while `ipv4_only` is true, matching the NC-side policy.

`/apply` writes `/etc/wireguard/<iface>.conf` atomically and then runs
`wg syncconf`, so existing peers keep their handshakes — the interface is never
torn down to change one peer.

## NAT and sysctl parity

`entrypoint.sh` mirrors what `/media/4TB/wireguard/docker-compose.yml` gives
wg-easy:

| Setting | Value | Why |
|---|---|---|
| `net.ipv4.ip_forward` | `1` | routes peer traffic off the tunnel |
| `net.ipv6.conf.all.forwarding` | `1` | parity with the production compose |
| `net.ipv4.conf.all.src_valid_mark` | `1` | rp_filter drops marked WG replies without it |
| `iptables -t nat -A POSTROUTING -s $WG_TUNNEL_CIDR -o $WG_NAT_OUT_INTERFACE -j MASQUERADE` | | peers get an address the LAN will answer |
| `iptables -A FORWARD -i/-o $WG_INTERFACE -j ACCEPT` | | Docker's default FORWARD policy is DROP |

The sysctls are also declared in the compose file (that is where they actually
take effect); the entrypoint sets them again so a bare `docker run` is not
silently broken. Rules are added with `-C` first so restarts do not stack
duplicates.

Container requirements: `cap_add: [NET_ADMIN, SYS_MODULE]`,
`devices: /dev/net/tun`, and `/lib/modules:ro` for hosts whose kernel needs the
module loaded.

## Environment

| Variable | Default | Notes |
|---|---|---|
| `WG_SYNC_TOKEN` | *(none)* | Required. Startup fails below 24 characters. |
| `WG_INTERFACE` | `wg-lab0` | Refused if `wg0` without `WG_SYNC_ALLOW_PROD=1`. |
| `WG_LISTEN_PORT` | *(unset)* | Pins the tunnel's UDP port, overriding `/apply`. The lab sets `51830`; unset in production so the NC server row decides. |
| `WG_TUNNEL_CIDR` | `10.8.0.0/24` | Source range for the MASQUERADE rule (entrypoint only). |
| `WG_NAT_OUT_INTERFACE` | `eth0` | Container-side uplink. |
| `WG_SYNC_BIND` | `0.0.0.0` | Inside the container; compose publishes loopback only. |
| `WG_SYNC_PORT` | `51821` | API port (TCP). |
| `WG_SYNC_ENABLE_NAT` | `1` | Set `0` when an external firewall owns NAT. |
| `WG_SYNC_ALLOW_PROD` | `0` | The `wg0` / `51820` guard. Cutover only. |
| `WG_SYNC_HEALTH_PUBLIC` | `0` | Unauthenticated `/health`. |
| `WG_SYNC_LOG_LEVEL` | `INFO` | `DEBUG` logs each `wg`/`ip` invocation. |
| `WG_SYNC_CONFIG_DIR` | `/etc/wireguard` | Config location. |

## Security notes

- The token is the only authentication. Keep the API on the Docker network or
  loopback; do not publish `51821` to the LAN.
- Key material travels in `POST /apply` bodies. `GET /dump` never returns the
  interface private key, and neither the app nor the entrypoint logs a key.
- Configs are written `0600` in a volume owned by root inside the container.
- `app.py` is standard library only — no pip install runs in a privileged
  container.

## Not in scope

- Peer storage, IPAM, or config rendering (those are Nextcloud's, see
  `lib/Service/PeerConfBuilder.php` and `lib/Service/PeerIpam.php`).
- Amnezia obfuscation.
- Any UI. The only consumer is `lib/Service/NativeEngine.php`.

# NC-built peer configs — defaults and precedence (P4)

Once Nextcloud builds the `.conf` itself (`PeerConfBuilder`), the numbers that
used to live only in the browser have a server-side home. This page is the
source of truth for what a Field or Admin peer looks like; the JS constants in
`src/services/dashboard-api.js` mirror it.

Production is unaffected: `otl_source` defaults to `wgeasy`, so wg-easy still
mints and serves configs until an operator flips the setting.

## Precedence

Every field resolves in the same order:

1. the peer row (`nc_wg_peers`) — what an operator set on this specific peer;
2. the server row (`nc_wg_server`) — the deployment default;
3. the built-in preset (`PeerPresets`) — the last-resort fallback.

That order is deliberate. A peer imported from wg-easy usually arrives with
`persistent_keepalive = 0` and no DNS, so the server row is what upgrades the
whole fleet to a working keepalive without touching each peer. But a peer whose
AllowedIPs an operator deliberately narrowed must never be widened again by a
later change to the deployment default.

## Presets

| Field | Field / site GCS | Admin full tunnel |
|---|---|---|
| `AllowedIPs` | `10.0.0.0/24, 10.8.0.0/24` | `0.0.0.0/0` |
| `DNS` | *(unset — site DNS keeps working)* | `1.1.1.1` |
| `MTU` | `1420` | `1420` |
| `PersistentKeepalive` | `25` | `25` |

`10.0.0.0/24` is the lab/site LAN reachable through the tunnel and
`10.8.0.0/24` is the tunnel pool itself, so a Field peer reaches the GCS and
other peers without pulling its whole internet through the VPN.

Keepalive is 25 s on both presets. wg-easy's default of 0 means "off", which
works from a laptop on a normal NAT and fails on the CGNAT/LTE links the field
peers actually use — the pinhole closes and inbound traffic dies until the peer
transmits again.

## Server row (`nc_wg_server`, singleton `id = 1`)

| Column | Meaning | Default |
|---|---|---|
| `host` | Public endpoint hostname/IP | *(none — must be set)* |
| `port` | UDP listen port | `51820` |
| `cidr` | Tunnel pool owned by IPAM | `10.8.0.0/24` |
| `mtu` | Interface MTU | `1420` |
| `default_dns` | Fallback `DNS =` | *(unset)* |
| `default_allowed_ips` | Fallback `AllowedIPs =` | *(unset → Field preset)* |
| `default_keepalive` | Fallback `PersistentKeepalive =` | `25` |
| `server_public_key` | `[Peer] PublicKey` handed to every peer | *(none — must be set)* |
| `ipv4_only` | IPv6 policy flag | `1` (on) |

`server_public_key` is preserved verbatim across cutover, which is the whole
reason field peers do not have to re-issue their configs.

## Refusals

The builder throws rather than emitting a config that looks fine and never
handshakes:

- no private key stored for the peer;
- `server_public_key` empty;
- no endpoint (neither `nc_wg_server.host` nor the peer's `serverEndpoint`);
- no tunnel address on the peer;
- `AllowedIPs` empty after the IPv4-only filter.

## IPv4-only policy

While `ipv4_only` is set, IPv6 entries are dropped from `AllowedIPs` and `DNS`,
and no IPv6 address is ever written to `[Interface] Address`. In particular
`::/0` is never emitted: the native tunnel assigns no IPv6, so a peer that
accepted an IPv6 default route would black-hole its v6 traffic into a tunnel
that cannot carry it. The stored address is always narrowed to `/32` so a peer
never becomes a router for the whole pool.

## One-time links

`otl_source` selects who mints links, independently of `engine`:

| Value | Mint | Redeem |
|---|---|---|
| `wgeasy` *(default)* | wg-easy `generateOneTimeLink` | proxied to wg-easy `/cnf/{token}` |
| `nc` | `NcOtlService`, token in appconfig | `PeerConfBuilder` renders on redeem |

NC tokens are single-use with a 300 s TTL, matching wg-easy's window. They live
in appconfig rather than a table because they are admin-minted one at a time and
expire in minutes — a migration would buy nothing. The config is rendered *at
redeem time*, so an edit made between mint and download is reflected in the file
the field user actually installs.

When `otl_source=nc` and a token is not one of NC's, the public redeem route
falls through to the engine. That keeps links minted just before the switch
working instead of 404-ing a field user who is holding a perfectly good one.

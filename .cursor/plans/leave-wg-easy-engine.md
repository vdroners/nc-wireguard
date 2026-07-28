# Leave the wg-easy engine

**Goal:** finish `nc_wireguard` as a genuinely standalone Nextcloud app **and**
grow it into the full WireGuard controller, so wg-easy can be retired without an
outage.

**Production status:** wg-easy keeps `51820/udp` and stays the source of truth
until P6 is verified. `engine=wgeasy` is the default and nothing in the engine
track flips it.

## Decisions (locked)

| Choice | Verdict |
|---|---|
| Production tunnel | Keep **wg-easy** on `51820/udp` until P6 is verified |
| Product direction | `nc_wireguard` becomes the controller; a thin **wg-sync** sidecar owns kernel WireGuard |
| Standalone work | Runs **in parallel** with the engine track, same repo, same releases |
| App Store story | "Requires a reachable WireGuard engine (URL + credentials)" — no AppAPI ExApp shipping a dataplane |
| IPv6 on the native engine | **IPv4-only** tunnel and peers by default (matches the Field preset); no `::/0` globals |
| Server key at cutover | **Preserved**, so field peers do not all have to re-issue |
| Metrics identity | Stable key is the peer **`public_key`**; `wg_easy_id` is kept only to remap history |

Two tracks, one release train. Neither track deletes wg-easy early.

---

## Track S — standalone finish-up

### S1 — Clean clone / CI without NC-GCS  *(done)*

Break the `package-lock.json` Vue `"link": true` into the sibling `nc-gcs`
checkout, retire `scripts/dedupe-vue.mjs`, neutralise the hard-coded PATH in
`scripts/deploy-docker.sh`. Gate: `npm ci && npm run build` on a tree with no
NC-GCS present.

### S2 — Drop the NC-GCS theme pipeline  *(done)*

Remove the soft `OCA\NcGcs\Util\ThemeAssetLoader` hook, delete
`scripts/sync-theme-from-nc-gcs.sh` and the `_nc_gcs_src_mirror` ignores, stop
documenting a `make sync-theme` target that does not exist. Keep the local
`NcAppShell.vue`; `nc-gcs-*` → `nc-wg-*` CSS markers are cosmetic.

### S3 — Gate, release, App Store hygiene  *(done)*

Fold `smoke-peer-writes.php` plus the public-OTL curl into
`scripts/gate-local.sh`; make the release workflow's tarball name match the tag
and `info.xml`; refresh `docs/plans/appstore-publication.md` and the placeholder
screenshots. Multi-DB migration CI stays nice-to-have, not a cutover blocker.

### S4 — Copy and i18n  *(done)*

Admin copy goes engine-agnostic ("Test engine", not "Test wg-easy"), stale
"Native backend v2.1" strings are bumped, primary UI strings route through
`l10n`, trademark disclaimer stays.

**Deferred product (backlog, documented only):** server-write UI before the
native engine, Amnezia, CSV export, chart rewrite, non-admin peer CRUD,
Portainer.

---

## Track E — replace the engine

### What wg-easy still owns

Peers, key material, interface CIDR/MTU, live stats, the `/cnf` one-time links,
**and the NAT PostUp/PostDown plus sysctls**. Nextcloud's MySQL only holds
metrics. Fields that must survive an import (see
`WgEasyClient::mergeUpdatePayload`): `allowedIps`, `dns`, `mtu`,
`persistentKeepalive`, `serverEndpoint`, `ipv4`/`ipv6`, hooks,
`serverAllowedIps`/`firewallIps`, and the Amnezia `j*`/`i*` knobs — which the
native engine must **refuse**, never silently drop.

### P1 — Secure inventory / backup  *(done)*

`scripts/export-peers.sh` reads per-peer **get-one** (the list endpoint strips
private keys) plus the `.conf` download, into `0700`/`0600` archives under
`/media/4TB/wireguard/exports/` (never git). Captures PostUp/PostDown and
sysctls (`ip_forward`, IPv6 forwarding, `src_valid_mark`) verbatim and notes
`wg-easy.db` mode hygiene. Runbook: `docs/ops/PEER_EXPORT.md`.

### P2 — `WireGuardEngineInterface`  *(done, zero behaviour change)*

`listPeers`, `getPeer`, `create`, `update`, `delete`, `enable`/`disable`,
`getConfiguration`, `getRuntimeStats`, `getServerInfo`, plus the OTL pair.
`WgEasyEngine` wraps today's client and is the registered default alias;
`getRuntimeStats()` is keyed by **public key** so a `wg show dump` backend can
replace integer client ids later. `FakeWireGuardEngine` covers the contract in
unit tests; live smoke still hits wg-easy.

### P3 — NC peer store + crypto + IPAM  *(this delivery)*

Nextcloud gets its own peer identity, key material, and addressing while wg-easy
still runs the tunnel. Everything here is a **shadow store**: no writes go back
to the engine.

- **Schema** (`Version000002Date20260727000000`):
  - `nc_wg_peers` — `uuid` + `public_key` unique, nullable indexed `wg_easy_id`,
    name/enabled/`ipv4`, the tunnel fields (`allowed_ips`, `dns`, `mtu`,
    `persistent_keepalive`, `server_endpoint`, `server_allowed_ips`,
    `firewall_ips`), `has_amnezia`, timestamps.
  - `nc_wg_peer_secrets` — `peer_id` PK, encrypted private key and PSK, split off
    so peer reads never pull ciphertext.
  - `nc_wg_server` — `id = 1` singleton: host, port, CIDR (default
    `10.8.0.0/24`), MTU, default DNS/AllowedIPs/keepalive, the preserved
    `server_public_key`, and the `ipv4_only` policy flag.
  - Remap headroom for P6: nullable `peer_uuid` on `nc_wg_bandwidth_log` and
    `nc_wg_connection_log`, plus `peer_uuid` + `public_key` on
    `nc_wg_poll_state`. Entities gained the matching properties — QBMapper
    hydration throws on a column with no property.
- **Hard-fail crypto** — `PeerSecretCrypto` (`enc:peer:v1:`) throws on every
  failure path. `SecretCrypto`'s "return the stored blob on decrypt failure" is
  correct for a wg-easy password and wrong for a key: the ciphertext would reach
  a `.conf` builder and produce a config that looks fine and never handshakes.
- **IPAM** — `PeerIpam` owns `10.8.0.0/24`, reserves the server `.1`, hands out
  the first free `/32`, and checks collisions against stored peers. IPv4 only;
  no IPv6 is assigned.
- **Store** — `PeerStoreService` upserts a normalized engine row, matching on
  public key first and `wg_easy_id` second. It never generates a keypair (a
  fresh key would orphan the live peer) and never overwrites stored key material
  without an explicit `--allow-key-rewrite`.
- **Import** — `occ nc_wireguard:import-peers [--from-export=DIR] [--dry-run]`.
  Live path uses `listPeers()` + per-peer `getPeer()` because the list endpoint
  omits secrets; offline path reads the `peers/*.json` + `conf/*.conf` tree from
  `export-peers.sh`. Notes flag `keepalive=0` against the Field preset's 25 s,
  Amnezia peers, and missing key material. The peer named `Server` is flagged
  break-glass and is never re-addressed.
- **Settings** — `engine` (default `wgeasy`) and `otl_source` (default `wgeasy`,
  for P4) with unknown values falling back to `wgeasy`.

### P4 — Native conf / QR / OTL  *(done)*

`PeerConfBuilder` renders the `.conf` from the peer store: `[Interface]` private
key, Address, DNS, MTU; `[Peer]` with `nc_wg_server.server_public_key`, the
endpoint (`serverEndpoint` override, else `host:port`), AllowedIPs and
keepalive. Precedence is peer → server row → preset, documented in
`docs/ops/NATIVE_CONF_DEFAULTS.md`. It **refuses** rather than emitting a
config that cannot connect: no private key, no address, no server public key,
no endpoint. `::/0` is never emitted while `ipv4_only`.

Presets are now server-side constants in `PeerPresets` (Field:
`10.0.0.0/24, 10.8.0.0/24`, keepalive 25; Admin: `0.0.0.0/0`, keepalive 0) so
the builder and the JS agree. `NcOtlService` mints NC tokens when
`otl_source=nc`, stored in appconfig with a UUID and a TTL — single-use,
expiry-aware (410, not 404). `redeemOtl` falls through to the engine on an
unknown token so links minted before the switch keep working. Default stays
`otl_source=wgeasy`.

### P5 — wg-sync sidecar + `NativeEngine`  *(done, lab only)*

`services/wg-sync/` — stdlib-only Python HTTP server; `GET /health`,
`POST /apply`, `GET /dump`, `POST /reload`, bearer token, no database and no
opinion about peer shape. `entrypoint.sh` reproduces wg-easy's NAT/sysctl parity
(`ip_forward`, IPv6 forwarding, `src_valid_mark`, MASQUERADE, FORWARD ACCEPT),
not "peers only". `docker-compose.lab.yml` runs `wg-lab0` on **51830** with the
API on loopback `51831`; `app.py` refuses `wg0` or `51820` unless
`WG_SYNC_ALLOW_PROD=1`, so the lab can run beside production indefinitely.

`NativeEngine` implements the full interface against the store plus the sidecar,
refusing Amnezia `j*`/`i*` and any IPv6 address instead of dropping them.
`ServerKeyStore` seals the interface private key (hard-fail crypto, public half
mirrored onto `nc_wg_server`), fed by `occ nc_wireguard:set-server-key` reading
stdin. `EngineResolver` gates activation on `engine=native` **and**
`import_complete` **and** a non-empty peer store, and `Application.php` resolves
the interface through it on every request — so a rollback is one config set.
`scripts/smoke-native-engine.php` exercises the lab and exits 0 (SKIP) when the
sidecar is unreachable.

### P6 — Cutover and retire  *(runbook staged; production untouched)*

`docs/ops/ENGINE_CUTOVER.md` is the operator runbook: freeze → export/archive →
re-import + remap → same-51820 swap with the **preserved** interface key →
verify with a real field peer → unfreeze, with the rollback path (restore the
archive, `wg-easy:15` pin, `engine=wgeasy`) written before the window opens.

Shipped alongside it: `peer_writes_frozen` actually blocks peer CRUD and OTL
mint (503 `writes_frozen`) while leaving downloads, redeems, and the poller
alone; `occ nc_wireguard:remap-metrics [--apply]` backfills `peer_uuid` /
`public_key` on the three metrics tables, idempotently, leaving `client_id` as
the audit trail; `AppSettings::SETTING_ALIASES` reads the new `engine_*` names
and falls back to `wg_easy_*` for one minor.

There is deliberately no `docker-compose.prod.yml` in the repo — a committed
file binding `51820/udp` is one stray `docker compose up` from fighting the live
tunnel. The runbook lists the six-line diff from the lab compose instead.

**Nothing here switches engines.** Production stays on wg-easy until an operator
executes the runbook.

---

## Security / networking checklist

- Export and peer-store permissions; no key material in logs, audit rows, or
  command output (imports print a truncated public key and `key: yes/no`)
- wg-sync bearer token; no open admin surface
- Preserve the server public key; IPv4-only native policy
- Host route, fixed IPAM, `src_valid_mark`, MASQUERADE
- Refuse Amnezia/hooks peers on the native engine until supported
- `serverAllowedIps` / `firewallIps` preserved on import, UI later

## Non-goals

- An AppAPI ExApp that ships the VPN dataplane
- A Portainer WireGuard UI, an Amnezia UI, or a hooks editor in Nextcloud (NAT
  scripts live in the sidecar's compose file)
- Folding the inbound server into `gcs_vpn_manager`
- Deleting wg-easy before P6 is verified

## Verification

```bash
make test          # PHPUnit: crypto hard-fail, IPAM, import, peer entities
make gate-local    # full gate (no sidecar)
# after an app version bump + occ upgrade so the migration runs:
docker exec cloud_app php occ nc_wireguard:schema-check
docker exec cloud_app php occ nc_wireguard:import-peers --dry-run
```

`import-peers --dry-run` is read-only, and even a real run only writes to the NC
peer store — the engine is never mutated while `engine=wgeasy`.

## Success criteria

- `npm ci && make test && make build` with no NC-GCS checkout present
- Gate covers peer writes and the public OTL when the engine is reachable
- Field peers eventually connect with **zero** wg-easy containers running
- Rollback to wg-easy proven once in lab before production P6


## Implementation status (2.3.0)

Standalone S1–S4 and engine P1–P6 scaffolding shipped in **2.3.0**. Production
remains on **wg-easy** (`engine=wgeasy`). Lab wg-sync uses `wg-lab0` / UDP 51830.
Operator cutover is manual per `docs/ops/ENGINE_CUTOVER.md` — not executed by
the release itself.

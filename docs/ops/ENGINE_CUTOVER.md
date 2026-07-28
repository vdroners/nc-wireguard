# Engine cutover: wg-easy → native (P6)

> **Production is still on wg-easy.** Nothing in this app switches engines on its
> own. `EngineResolver` requires `engine=native` **and** `import_complete=1`
> **and** a non-empty peer store before `NativeEngine` is used at all, and none
> of those are set on the production host. The steps below are a runbook for an
> operator to execute deliberately, during a window, with the rollback path
> already staged. Do not run them as part of a deploy.

## What actually changes

`wg-easy` today owns three things: the kernel WireGuard interface, the peer
records, and the `.conf`/QR generation. The cutover moves the second and third
into Nextcloud (done — that is P1–P4) and replaces the first with the `wg-sync`
sidecar (P5). The tunnel itself does not change: same UDP port, same interface
addresses, and critically the **same interface private key**, which is what lets
every field peer keep the config it already has.

If the interface key changed, every peer in the fleet would need to be re-issued
before it could connect again. Preserving it is the single most important
detail in this document.

## Preconditions

- [ ] A secret-aware export exists and is verified — `docs/ops/PEER_EXPORT.md`.
- [ ] `occ nc_wireguard:import-peers` has been run and the peer table matches
      the engine (count, names, public keys, IPv4 addresses).
- [ ] Every peer's key material imported. A peer with no stored private key
      cannot be handed a `.conf` by Nextcloud; `PeerConfBuilder` refuses rather
      than emitting a config that cannot connect.
- [ ] No peer carries Amnezia obfuscation (`has_amnezia`). Kernel WireGuard has
      no equivalent, so `NativeEngine` refuses those peers instead of silently
      dropping the fields. Clear them on wg-easy first, and re-issue those peers.
- [ ] The `wg-sync` lab stack has been exercised — `services/wg-sync/README.md`
      and `scripts/smoke-native-engine.php`.
- [ ] A maintenance window. Peers drop for the length of step 4.

## 1. Freeze writes

Peer CRUD during the copy would leave the two stores disagreeing about the
fleet.

```bash
docker exec cloud_app php occ config:app:set nc_wireguard peer_writes_frozen --value=1
```

Peer create/update/delete/enable/disable and new one-time links return `503
writes_frozen` while this is set. Config downloads, OTL redeems, and the poller
keep working — a field user redeeming a link they were already sent does not put
the two stores out of step, and the metrics gap is what tells you afterwards
exactly how long the tunnel was actually down.

Announce the freeze anyway. The flag stops the app, not an admin with the
break-glass wg-easy UI open on `127.0.0.1:51821`.

## 2. Export and archive

```bash
export WG_EASY_PASS='…'
bash /media/4TB/nc-wireguard/scripts/export-peers.sh
bash /media/4TB/nc-wireguard/scripts/backup-wireguard-metrics.sh

# The whole engine state, including the interface key — this is the rollback.
sudo tar czf /media/4TB/wireguard/archive/wg-easy-preswap-$(date +%Y%m%d-%H%M).tgz \
  -C /media/4TB/wireguard config docker-compose.yml .env
sudo chmod 600 /media/4TB/wireguard/archive/*.tgz
```

Verify the archive lists `config/wg0.conf` before continuing. An archive you
have not listed is not a rollback.

## 3. Re-import and remap

```bash
docker exec cloud_app php occ nc_wireguard:import-peers --dry-run   # review
docker exec cloud_app php occ nc_wireguard:import-peers

# Backfill stable identities on historical metrics BEFORE ids stop meaning
# anything. Dry run first; --apply only fills columns that are blank.
docker exec cloud_app php occ nc_wireguard:remap-metrics
docker exec cloud_app php occ nc_wireguard:remap-metrics --apply
```

`remap-metrics` fills `peer_uuid` on `nc_wg_bandwidth_log` /
`nc_wg_connection_log` and `peer_uuid` + `public_key` on `nc_wg_poll_state`. It
leaves `client_id` alone on purpose: it is the audit trail of what wg-easy
called each peer, and keeping it is what makes the operation re-runnable.

Client ids it could not match are reported. Those are peers deleted before the
import; their history stays queryable by `client_id` but will not follow a peer
forward.

## 4. Swap on the same port

The interface key moves across; the port does not change.

```bash
# The preserved interface key, straight from the wg-easy config. Stdin, so it
# never lands in shell history or a process list.
sudo grep -m1 PrivateKey /media/4TB/wireguard/config/wg0.conf \
  | cut -d= -f2- | tr -d ' ' \
  | docker exec -i cloud_app php occ nc_wireguard:set-server-key

# Confirm the public half matches what peers already have pinned.
docker exec cloud_app php occ nc_wireguard:set-server-key --show-public
sudo wg show wg0 public-key
```

**Those two must print the same string.** If they differ, stop — the fleet's
configs point at a key you are about to remove.

Then, and only then:

```bash
cd /media/4TB/wireguard && docker compose down          # releases 51820/udp
cd /media/4TB/nc-wireguard/services/wg-sync
docker compose -f docker-compose.prod.yml up -d

docker exec cloud_app php occ config:app:set nc_wireguard wg_sync_url --value='http://wg-sync:51831'
docker exec cloud_app php occ config:app:set nc_wireguard wg_sync_token --value="$WG_SYNC_TOKEN"
docker exec cloud_app php occ config:app:set nc_wireguard import_complete --value=1
docker exec cloud_app php occ config:app:set nc_wireguard engine --value=native
```

`import_complete` before `engine` matters: with the order reversed there is a
window where the resolver sees `engine=native` and a store it has not been told
is trustworthy.

### There is no `docker-compose.prod.yml` in the repo, on purpose

A committed compose file that binds `51820/udp` and names `wg0` is one stray
`docker compose up` away from fighting production wg-easy for the port. Write it
in the window, from `docker-compose.lab.yml`, with exactly these changes:

| Lab | Production |
|---|---|
| `WG_INTERFACE: wg-lab0` | `WG_INTERFACE: wg0` |
| `WG_LISTEN_PORT: "51830"` | remove — the NC server row (51820) decides |
| `WG_SYNC_ALLOW_PROD: "0"` | `WG_SYNC_ALLOW_PROD: "1"` |
| `"51830:51830/udp"` | `"51820:51820/udp"` |
| `container_name: wg_sync_lab` | `container_name: wg_sync` |
| `wg_sync_lab_config` volume | bind `/media/4TB/wireguard/config` |

Everything else — capabilities, `/dev/net/tun`, the three sysctls, the NAT
variables — is already at production parity, which is what the lab exists to
prove. Keep the file outside git next to the wg-easy compose it replaces.


## 5. Verify before unfreezing

```bash
docker exec cloud_app php /var/www/html/custom_apps/nc_wireguard/scripts/smoke-native-engine.php
sudo wg show wg0
docker exec cloud_app php occ nc_wireguard:poll-metrics
```

Then the check no script can do: **have a real field peer connect.** Watch for a
handshake in `wg show wg0` and traffic in both directions. A peer that
handshakes but passes no traffic is a NAT/forwarding problem, not a WireGuard
one — see the parity section of `services/wg-sync/README.md`.

Unfreeze once a real peer is through:

```bash
docker exec cloud_app php occ config:app:delete nc_wireguard peer_writes_frozen
```

## 6. Settings renames

The `wg_easy_*` appconfig keys keep working. `AppSettings::SETTING_ALIASES` maps
each new name to the legacy one, reads prefer the new name and fall back, and
writes stay on the legacy key for one minor so a rollback to the previous app
version still finds the value.

| New name | Legacy name | Notes |
|---|---|---|
| `engine_api_url` | `wg_easy_api_url` | Unused once `engine=native` |
| `engine_username` | `wg_easy_username` | Unused once `engine=native` |
| `engine_password` | `wg_easy_password` | Encrypted under both names |
| `engine_admin_url` | `wg_easy_admin_url` | Break-glass deep link |
| `hide_engine_admin_link` | `hide_wg_easy_admin_link` | |

New in P5, no legacy name: `wg_sync_url`, `wg_sync_token`, `import_complete`.

Do not delete the `wg_easy_*` values during the window. They are what a rollback
needs, and `engine_api_url` is the only way back to a running wg-easy.

## 7. Archive the old engine

Only after a few days of clean operation, and never in the same window:

```bash
sudo mv /media/4TB/wireguard/config /media/4TB/wireguard/config.archived-$(date +%Y%m%d)
sudo chmod 700 /media/4TB/wireguard/config.archived-*
```

Keep `docker-compose.yml` pinned at `ghcr.io/wg-easy/wg-easy:15` in place. A
pinned compose file that can be brought back up in thirty seconds is worth more
than the disk it occupies. Do not let the pin drift to `:latest`: a wg-easy
major upgrade during a rollback is two problems at once.

## Rollback

Any failure in step 4 or 5 — no handshake, key mismatch, peers connecting but
passing no traffic — rolls back. Do not debug the new engine with the fleet
down.

```bash
docker exec cloud_app php occ config:app:set nc_wireguard engine --value=wgeasy
cd /media/4TB/nc-wireguard/services/wg-sync && docker compose -f docker-compose.prod.yml down

sudo tar xzf /media/4TB/wireguard/archive/wg-easy-preswap-<stamp>.tgz -C /media/4TB/wireguard
cd /media/4TB/wireguard
grep 'image:' docker-compose.yml    # MUST be wg-easy:15
docker compose up -d

sudo wg show wg0
docker exec cloud_app php occ config:app:delete nc_wireguard peer_writes_frozen
```

Setting `engine=wgeasy` alone is enough to make Nextcloud stop talking to
`wg-sync`, because `EngineResolver` re-checks on every request — there is no
cached engine choice to clear.

Nothing done in steps 1–3 needs undoing. The peer store is a shadow copy while
`engine=wgeasy`, and the metrics remap only filled blank columns.

## Notes

- The lab stack (`docker-compose.lab.yml`) binds `wg-lab0` on **51830** and its
  entrypoint refuses to configure `wg0` unless `WG_SYNC_ALLOW_PROD=1`. It can
  run alongside production wg-easy indefinitely; that is the point of it.
- IPv4 only. `nc_wg_server.ipv4_only` is set, `PeerConfBuilder` never emits
  `::/0`, and `NativeEngine` refuses an IPv6 address rather than dropping it.
- The `Server` full-tunnel break-glass peer must survive the import. Check for
  it by name before step 4.

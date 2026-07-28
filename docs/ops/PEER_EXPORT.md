# Peer export / engine backup

**Before any engine cutover work**, run a secret-aware export.

## Location

Exports live under `/media/4TB/wireguard/exports/` (not git). Directory mode `0700`, files `0600`.

## Command

From a host that can reach the engine UI (loopback `127.0.0.1:51821` or Docker DNS):

```bash
export WG_EASY_PASS='…'   # do not put on argv
bash /media/4TB/nc-wireguard/scripts/export-peers.sh
```

Optional: `WG_EASY_URL`, `WG_EASY_USER`, `EXPORT_ROOT`.

## What it captures

- Per-peer **get-one** JSON (includes private keys — list endpoints omit them)
- Per-peer `.conf` download
- Manifest JSONL (ids, names, public keys, keepalive flags — no key material)
- PostUp/PostDown + sysctl inventory (keys redacted)

## Hygiene

- `chmod 600` on `/media/4TB/wireguard/config/wg-easy.db` before cutover
- Never log private keys or paste them into chat/commits
- Keep the `Server` full-tunnel break-glass peer intact on import

## Related

- Inventory snapshot may already exist under `exports/engine-inventory-*.md`
- Cutover / rollback: `docs/ops/ENGINE_CUTOVER.md`

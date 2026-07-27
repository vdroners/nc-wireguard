# nc_wireguard 2.2 gap-fill (checked-in)

Faithful summary of the Cursor plan `nc_wg_2.2_gap_fill_daf0c6ad` — executed after
the 2.1 peer-controller review. **No full server admin / Portainer / engine replace.**

## Shipped in 2.2.0

1. **Public OTL redeem** — `#[PublicPage]` + `#[NoCSRFRequired]` on GET redeem;
   mint stays admin+CSRF; `OtlRedeemRateLimiter` (appconfig per-IP window);
   modal prefers shareable NC URL; docs + unit tests.
2. **Peer form advanced** — optional `ipv4Address` + `serverEndpoint`; IPv6
   read-only in Overview expand row.
3. **Bulk Field policy** — Overview multi-select + Apply Field preset
   (`10.0.0.0/24,10.8.0.0/24`, keepalive 25); skip peer named `Server`.
4. **Read-only Server panel** — System tab card from `GET /api/admin/general` +
   `/api/admin/interface` via `WgEasyClient::getServerDefaults()`; soft-fail;
   **no write**.
5. **Hygiene** — `DashboardProxyController` → `DashboardController` (HTTP path
   `/api/dashboard/{path}` unchanged); this plan checked in.

## Explicitly deferred

- Server defaults write, CIDR renumber, hooks, interface restart, Amnezia UI
- Portainer WG surface (none on this host)
- CSV export, Chart rewrite, non-admin peer CRUD

## Verify

- `make test`
- `make deploy-docker` + `make health`
- `docker exec … smoke-peer-writes.php`
- Public redeem curl without NC cookie → `.conf` once; second → fail

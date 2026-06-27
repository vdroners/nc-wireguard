# Host `/proc` bind-mount for native poller

The native `occ nc_wireguard:poll-metrics` job reads host CPU, memory, disk, and network counters via `HostProcCollector`. Inside `cloud_app`, mount the host procfs read-only:

```yaml
# cloud compose snippet (cloud_app service)
volumes:
  - /proc:/host/proc:ro
```

Without this mount, the collector falls back to container `/proc` (metrics reflect the Nextcloud container, not the VPN host). See [HOST_METRICS_AUDIT.md](../HOST_METRICS_AUDIT.md).

Verify after mount:

```bash
docker exec cloud_app test -r /host/proc/stat && echo OK
docker exec cloud_app php occ nc_wireguard:poll-metrics --no-lock
```

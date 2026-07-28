#!/bin/sh
# NAT / sysctl parity with the wg-easy compose file.
#
# wg-easy does this work in its own entrypoint, which is why "just run wg" is
# not a drop-in replacement: without the MASQUERADE rule and the forwarding
# sysctls a peer completes a handshake and then reaches nothing. The rules are
# applied here, in the sidecar, rather than in per-peer PostUp hooks — hooks
# belong to the deployment, not to a peer row in Nextcloud.
set -eu

WG_INTERFACE="${WG_INTERFACE:-wg-lab0}"
# Must match nc_wg_server.cidr — this is the source range NAT rewrites, so a
# mismatch is a tunnel where peers handshake and then reach nothing off-subnet.
WG_TUNNEL_CIDR="${WG_TUNNEL_CIDR:-10.8.0.0/24}"
WG_NAT_OUT_INTERFACE="${WG_NAT_OUT_INTERFACE:-eth0}"
WG_SYNC_ENABLE_NAT="${WG_SYNC_ENABLE_NAT:-1}"

if [ "$WG_INTERFACE" = "wg0" ] && [ "${WG_SYNC_ALLOW_PROD:-0}" != "1" ]; then
	echo "wg-sync: refusing to start on the production interface wg0" >&2
	echo "wg-sync: set WG_SYNC_ALLOW_PROD=1 only during a planned cutover" >&2
	exit 2
fi

# Best effort: docker-compose already sets these via `sysctls:`, but a plain
# `docker run` would not, and a missing ip_forward is a silent failure mode.
for knob in \
	"net.ipv4.ip_forward=1" \
	"net.ipv6.conf.all.forwarding=1" \
	"net.ipv4.conf.all.src_valid_mark=1"
do
	sysctl -w "$knob" >/dev/null 2>&1 || echo "wg-sync: could not set $knob (already set by compose?)" >&2
done

if [ "$WG_SYNC_ENABLE_NAT" = "1" ]; then
	# -C first so a container restart does not stack duplicate rules.
	iptables -t nat -C POSTROUTING -s "$WG_TUNNEL_CIDR" -o "$WG_NAT_OUT_INTERFACE" -j MASQUERADE 2>/dev/null \
		|| iptables -t nat -A POSTROUTING -s "$WG_TUNNEL_CIDR" -o "$WG_NAT_OUT_INTERFACE" -j MASQUERADE
	iptables -C FORWARD -i "$WG_INTERFACE" -j ACCEPT 2>/dev/null \
		|| iptables -A FORWARD -i "$WG_INTERFACE" -j ACCEPT
	iptables -C FORWARD -o "$WG_INTERFACE" -j ACCEPT 2>/dev/null \
		|| iptables -A FORWARD -o "$WG_INTERFACE" -j ACCEPT
	echo "wg-sync: NAT ready ($WG_TUNNEL_CIDR -> $WG_NAT_OUT_INTERFACE)"
fi

# Bring an existing config up so a restart restores the peer set without
# waiting for Nextcloud to re-apply.
if [ -f "/etc/wireguard/${WG_INTERFACE}.conf" ]; then
	wg-quick up "$WG_INTERFACE" || echo "wg-sync: wg-quick up failed; /apply will retry" >&2
fi

exec python3 /opt/wg-sync/app.py

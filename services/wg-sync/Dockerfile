# wg-sync — kernel WireGuard behind a small HTTP API.
#
# Alpine because wireguard-tools + iptables + python3 is the entire dependency
# list, and a privileged container should carry as little else as possible.
FROM alpine:3.20

# Prefer HTTP health: `wg show` fails before the first /apply brings the iface up.
RUN apk add --no-cache \
	wireguard-tools \
	iptables \
	ip6tables \
	iproute2 \
	python3 \
	curl \
	&& rm -rf /var/cache/apk/*

WORKDIR /opt/wg-sync
COPY app.py /opt/wg-sync/app.py
COPY entrypoint.sh /opt/wg-sync/entrypoint.sh
RUN chmod +x /opt/wg-sync/entrypoint.sh

# API port. The tunnel's UDP port is published separately by compose.
EXPOSE 51821/tcp

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
	CMD curl -sf http://127.0.0.1:51821/health >/dev/null || exit 1

ENTRYPOINT ["/opt/wg-sync/entrypoint.sh"]

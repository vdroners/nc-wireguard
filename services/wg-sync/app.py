#!/usr/bin/env python3
"""wg-sync — a thin HTTP front end for kernel WireGuard.

Nextcloud (nc_wireguard) owns peers, key material, and addressing; this service
owns exactly one thing: turning a desired peer set into `wg` state on one
interface. It deliberately has no database, no UI, and no opinion about what a
peer should look like.

Standard library only. The whole point of the sidecar is that it runs
privileged (NET_ADMIN, /dev/net/tun), so the dependency surface is kept as
close to zero as a Python HTTP server can be.

Endpoints (all require `Authorization: Bearer $WG_SYNC_TOKEN`, except /health
when WG_SYNC_HEALTH_PUBLIC=1):

    GET  /health   interface presence, listen port, peer count
    POST /apply    replace the peer set (and optionally the interface keys)
    GET  /dump     `wg show <iface> dump`, parsed into JSON
    POST /reload   re-apply the on-disk config without changing it

Production safety: the interface name and listen port are checked against the
production tunnel (`wg0` / 51820) and refused unless WG_SYNC_ALLOW_PROD=1. The
lab compose file sets neither, so a misconfigured lab container cannot take the
field peers down.
"""

from __future__ import annotations

import base64
import ipaddress
import json
import logging
import os
import re
import subprocess
import sys
import tempfile
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any

VERSION = "0.1.0"

PROD_INTERFACE = "wg0"
PROD_PORT = 51820

CONFIG_DIR = os.environ.get("WG_SYNC_CONFIG_DIR", "/etc/wireguard")
INTERFACE = os.environ.get("WG_INTERFACE", "wg-lab0")
# Pins the tunnel's UDP port regardless of what /apply asks for. The lab sets
# this so it listens on the port its compose file actually publishes, instead of
# the 51820 that the NC server row still carries from production.
FORCE_PORT = int(os.environ.get("WG_LISTEN_PORT", "0") or 0)
LISTEN_ADDR = os.environ.get("WG_SYNC_BIND", "0.0.0.0")
LISTEN_PORT = int(os.environ.get("WG_SYNC_PORT", "51821"))
TOKEN = os.environ.get("WG_SYNC_TOKEN", "")
ALLOW_PROD = os.environ.get("WG_SYNC_ALLOW_PROD", "0") == "1"
HEALTH_PUBLIC = os.environ.get("WG_SYNC_HEALTH_PUBLIC", "0") == "1"

# 32 raw bytes, base64 — the only shape wg accepts for a key.
KEY_RE = re.compile(r"^[A-Za-z0-9+/]{42}[AEIMQUYcgkosw]=$")
IFACE_RE = re.compile(r"^[A-Za-z0-9_.-]{1,15}$")

log = logging.getLogger("wg-sync")


class ApiError(Exception):
    def __init__(self, status: int, message: str, code: str = "") -> None:
        super().__init__(message)
        self.status = status
        self.message = message
        self.code = code


def config_path(interface: str) -> str:
    return os.path.join(CONFIG_DIR, f"{interface}.conf")


def guard_interface(interface: str) -> str:
    if not IFACE_RE.match(interface):
        raise ApiError(HTTPStatus.BAD_REQUEST, f"invalid interface name: {interface!r}")
    if interface == PROD_INTERFACE and not ALLOW_PROD:
        raise ApiError(
            HTTPStatus.FORBIDDEN,
            f"refusing to touch the production interface {PROD_INTERFACE} "
            "(set WG_SYNC_ALLOW_PROD=1 only during a planned cutover)",
            "prod_guard",
        )
    return interface


def guard_port(port: int) -> int:
    if not 1 <= port <= 65535:
        raise ApiError(HTTPStatus.BAD_REQUEST, f"invalid listen port: {port}")
    if port == PROD_PORT and not ALLOW_PROD:
        raise ApiError(
            HTTPStatus.FORBIDDEN,
            f"refusing to bind the production port {PROD_PORT} "
            "(set WG_SYNC_ALLOW_PROD=1 only during a planned cutover)",
            "prod_guard",
        )
    return port


def run(argv: list[str], check: bool = True) -> subprocess.CompletedProcess[str]:
    log.debug("exec %s", " ".join(argv))
    proc = subprocess.run(argv, capture_output=True, text=True, timeout=30)
    if check and proc.returncode != 0:
        raise ApiError(
            HTTPStatus.INTERNAL_SERVER_ERROR,
            f"{argv[0]} failed: {(proc.stderr or proc.stdout).strip()}",
            "exec_failed",
        )
    return proc


def interface_exists(interface: str) -> bool:
    return run(["ip", "link", "show", interface], check=False).returncode == 0


def valid_key(value: str) -> bool:
    if not KEY_RE.match(value):
        return False
    try:
        return len(base64.b64decode(value, validate=True)) == 32
    except Exception:
        return False


def normalise_cidrs(values: Any, field: str, ipv4_only: bool) -> list[str]:
    if isinstance(values, str):
        values = [part for part in re.split(r"[\s,]+", values) if part]
    if not isinstance(values, list):
        raise ApiError(HTTPStatus.BAD_REQUEST, f"{field} must be a list or comma-separated string")
    out: list[str] = []
    for raw in values:
        if not isinstance(raw, str) or not raw.strip():
            continue
        try:
            net = ipaddress.ip_network(raw.strip(), strict=False)
        except ValueError as exc:
            raise ApiError(HTTPStatus.BAD_REQUEST, f"{field}: {exc}") from exc
        if ipv4_only and net.version != 4:
            # Matches the NC-side policy: an IPv6 route into a v4-only tunnel
            # black-holes the peer's v6 traffic.
            continue
        out.append(str(net))
    return out


def build_config(payload: dict[str, Any], interface: str) -> tuple[str, list[str]]:
    """Render a wg-quick style config plus the notes worth showing an operator."""
    notes: list[str] = []
    ipv4_only = bool(payload.get("ipv4_only", True))

    private_key = str(payload.get("private_key", "")).strip()
    if not valid_key(private_key):
        raise ApiError(HTTPStatus.BAD_REQUEST, "private_key is not a valid WireGuard key")

    requested_port = int(payload.get("listen_port", 0) or 0)
    listen_port = guard_port(FORCE_PORT or requested_port)
    if FORCE_PORT and requested_port and requested_port != FORCE_PORT:
        notes.append(f"listen port pinned to {FORCE_PORT} by WG_LISTEN_PORT (requested {requested_port})")
    address = normalise_cidrs(payload.get("address", []), "address", ipv4_only)
    if not address:
        raise ApiError(HTTPStatus.BAD_REQUEST, "address must carry at least one IPv4 CIDR")

    lines = [
        "# Managed by wg-sync — edits are overwritten on the next /apply.",
        f"# interface={interface}",
        "[Interface]",
        f"PrivateKey = {private_key}",
        f"Address = {', '.join(address)}",
        f"ListenPort = {listen_port}",
    ]
    mtu = payload.get("mtu")
    if isinstance(mtu, int) and 1280 <= mtu <= 1500:
        lines.append(f"MTU = {mtu}")

    peers = payload.get("peers")
    if not isinstance(peers, list):
        raise ApiError(HTTPStatus.BAD_REQUEST, "peers must be a list")

    seen: set[str] = set()
    for entry in peers:
        if not isinstance(entry, dict):
            raise ApiError(HTTPStatus.BAD_REQUEST, "each peer must be an object")
        name = str(entry.get("name", "")) or "(unnamed)"

        if entry.get("has_amnezia"):
            # NC is supposed to refuse these before they get here; refusing
            # again means a direct curl cannot smuggle one in either.
            raise ApiError(
                HTTPStatus.UNPROCESSABLE_ENTITY,
                f"peer {name} carries Amnezia obfuscation, which kernel WireGuard cannot honour",
                "amnezia_unsupported",
            )
        if not bool(entry.get("enabled", True)):
            notes.append(f"{name}: disabled, not installed on the interface")
            continue

        public_key = str(entry.get("public_key", "")).strip()
        if not valid_key(public_key):
            raise ApiError(HTTPStatus.BAD_REQUEST, f"peer {name} has an invalid public_key")
        if public_key in seen:
            raise ApiError(HTTPStatus.BAD_REQUEST, f"peer {name} duplicates an earlier public_key")
        seen.add(public_key)

        allowed = normalise_cidrs(entry.get("allowed_ips", []), f"peer {name} allowed_ips", ipv4_only)
        if not allowed:
            raise ApiError(
                HTTPStatus.BAD_REQUEST,
                f"peer {name} has no IPv4 allowed_ips — it would receive no traffic",
            )

        lines.extend(["", f"# {name}", "[Peer]", f"PublicKey = {public_key}"])
        psk = str(entry.get("preshared_key", "") or "").strip()
        if psk:
            if not valid_key(psk):
                raise ApiError(HTTPStatus.BAD_REQUEST, f"peer {name} has an invalid preshared_key")
            lines.append(f"PresharedKey = {psk}")
        lines.append(f"AllowedIPs = {', '.join(allowed)}")
        keepalive = entry.get("persistent_keepalive")
        if isinstance(keepalive, int) and keepalive > 0:
            lines.append(f"PersistentKeepalive = {keepalive}")

    return "\n".join(lines) + "\n", notes


def write_config(interface: str, body: str) -> str:
    path = config_path(interface)
    os.makedirs(CONFIG_DIR, exist_ok=True)
    # Atomic replace: a half-written config that `wg setconf` reads mid-flight
    # would drop every peer on the interface.
    fd, tmp = tempfile.mkstemp(dir=CONFIG_DIR, prefix=f".{interface}.", suffix=".tmp")
    try:
        with os.fdopen(fd, "w") as handle:
            handle.write(body)
        os.chmod(tmp, 0o600)
        os.replace(tmp, path)
    except Exception:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise
    return path


def sync_interface(interface: str) -> None:
    """Bring the interface to match its on-disk config without dropping it."""
    path = config_path(interface)
    if not os.path.exists(path):
        raise ApiError(HTTPStatus.NOT_FOUND, f"no config on disk for {interface}", "no_config")
    if not interface_exists(interface):
        run(["wg-quick", "up", interface])
        return
    # strip leaves only what `wg syncconf` understands (no Address/PostUp).
    stripped = run(["wg-quick", "strip", interface]).stdout
    with tempfile.NamedTemporaryFile("w", suffix=".conf", delete=False) as handle:
        handle.write(stripped)
        tmp = handle.name
    try:
        run(["wg", "syncconf", interface, tmp])
    finally:
        os.unlink(tmp)


def parse_dump(interface: str) -> dict[str, Any]:
    if not interface_exists(interface):
        return {"interface": interface, "up": False, "peers": []}
    raw = run(["wg", "show", interface, "dump"]).stdout.strip()
    lines = [line for line in raw.splitlines() if line.strip()]
    if not lines:
        return {"interface": interface, "up": True, "peers": []}

    head = lines[0].split("\t")
    result: dict[str, Any] = {
        "interface": interface,
        "up": True,
        # The interface private key is deliberately not returned.
        "public_key": head[1] if len(head) > 1 else None,
        "listen_port": int(head[2]) if len(head) > 2 and head[2].isdigit() else None,
        "fwmark": head[3] if len(head) > 3 and head[3] != "off" else None,
        "peers": [],
    }
    for line in lines[1:]:
        cols = line.split("\t")
        if len(cols) < 8:
            continue
        result["peers"].append(
            {
                "public_key": cols[0],
                "has_preshared_key": cols[1] not in ("(none)", ""),
                "endpoint": None if cols[2] in ("(none)", "") else cols[2],
                "allowed_ips": [] if cols[3] in ("(none)", "") else cols[3].split(","),
                "latest_handshake": int(cols[4]) if cols[4].isdigit() else 0,
                "transfer_rx": int(cols[5]) if cols[5].isdigit() else 0,
                "transfer_tx": int(cols[6]) if cols[6].isdigit() else 0,
                "persistent_keepalive": int(cols[7]) if cols[7].isdigit() else 0,
            }
        )
    return result


class Handler(BaseHTTPRequestHandler):
    server_version = f"wg-sync/{VERSION}"

    def log_message(self, fmt: str, *args: Any) -> None:
        log.info("%s %s", self.address_string(), fmt % args)

    # --- plumbing ---------------------------------------------------------

    def _send(self, status: int, payload: dict[str, Any]) -> None:
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _authorised(self) -> bool:
        if not TOKEN:
            return False
        header = self.headers.get("Authorization", "")
        if not header.startswith("Bearer "):
            return False
        import hmac

        return hmac.compare_digest(header[7:].strip(), TOKEN)

    def _body(self) -> dict[str, Any]:
        length = int(self.headers.get("Content-Length", "0") or 0)
        if length <= 0:
            return {}
        if length > 1_000_000:
            raise ApiError(HTTPStatus.REQUEST_ENTITY_TOO_LARGE, "request body too large")
        try:
            data = json.loads(self.rfile.read(length).decode())
        except json.JSONDecodeError as exc:
            raise ApiError(HTTPStatus.BAD_REQUEST, f"invalid JSON: {exc}") from exc
        if not isinstance(data, dict):
            raise ApiError(HTTPStatus.BAD_REQUEST, "body must be a JSON object")
        return data

    def _dispatch(self, method: str) -> None:
        path = self.path.split("?", 1)[0].rstrip("/") or "/"
        public = path == "/health" and HEALTH_PUBLIC
        try:
            if not public and not self._authorised():
                raise ApiError(HTTPStatus.UNAUTHORIZED, "missing or invalid bearer token", "unauthorised")
            handler = ROUTES.get((method, path))
            if handler is None:
                raise ApiError(HTTPStatus.NOT_FOUND, f"no route for {method} {path}")
            status, payload = handler(self)
            self._send(status, payload)
        except ApiError as exc:
            self._send(exc.status, {"ok": False, "error": exc.message, "code": exc.code})
        except Exception as exc:  # pragma: no cover - last-resort guard
            log.exception("unhandled error")
            self._send(HTTPStatus.INTERNAL_SERVER_ERROR, {"ok": False, "error": str(exc)})

    def do_GET(self) -> None:  # noqa: N802 - BaseHTTPRequestHandler API
        self._dispatch("GET")

    def do_POST(self) -> None:  # noqa: N802 - BaseHTTPRequestHandler API
        self._dispatch("POST")

    # --- routes -----------------------------------------------------------

    def health(self) -> tuple[int, dict[str, Any]]:
        interface = guard_interface(INTERFACE)
        up = interface_exists(interface)
        state = parse_dump(interface) if up else {"peers": []}
        return HTTPStatus.OK, {
            "ok": True,
            "version": VERSION,
            "interface": interface,
            "up": up,
            "listen_port": state.get("listen_port"),
            "peer_count": len(state.get("peers", [])),
            "config_present": os.path.exists(config_path(interface)),
            "allow_prod": ALLOW_PROD,
        }

    def apply(self) -> tuple[int, dict[str, Any]]:
        payload = self._body()
        interface = guard_interface(str(payload.get("interface", INTERFACE)))
        body, notes = build_config(payload, interface)
        path = write_config(interface, body)
        sync_interface(interface)
        state = parse_dump(interface)
        return HTTPStatus.OK, {
            "ok": True,
            "interface": interface,
            "config_path": path,
            "peer_count": len(state.get("peers", [])),
            "notes": notes,
        }

    def dump(self) -> tuple[int, dict[str, Any]]:
        state = parse_dump(guard_interface(INTERFACE))
        state["ok"] = True
        return HTTPStatus.OK, state

    def reload(self) -> tuple[int, dict[str, Any]]:
        interface = guard_interface(INTERFACE)
        sync_interface(interface)
        return HTTPStatus.OK, {"ok": True, "interface": interface}


ROUTES = {
    ("GET", "/health"): Handler.health,
    ("POST", "/apply"): Handler.apply,
    ("GET", "/dump"): Handler.dump,
    ("POST", "/reload"): Handler.reload,
}


def main() -> int:
    logging.basicConfig(
        level=os.environ.get("WG_SYNC_LOG_LEVEL", "INFO").upper(),
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    if not TOKEN:
        log.error("WG_SYNC_TOKEN is not set — refusing to start an unauthenticated dataplane API")
        return 2
    if len(TOKEN) < 24:
        log.error("WG_SYNC_TOKEN is shorter than 24 characters — refusing to start")
        return 2
    try:
        guard_interface(INTERFACE)
    except ApiError as exc:
        log.error("%s", exc.message)
        return 2

    log.info("wg-sync %s listening on %s:%d for interface %s", VERSION, LISTEN_ADDR, LISTEN_PORT, INTERFACE)
    ThreadingHTTPServer((LISTEN_ADDR, LISTEN_PORT), Handler).serve_forever()
    return 0


if __name__ == "__main__":
    sys.exit(main())

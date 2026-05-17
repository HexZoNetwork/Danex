#!/usr/bin/env python3
from __future__ import annotations

import http.client
import json
import os
import pathlib
import socket
import subprocess
import tempfile
import time


ROOT = pathlib.Path(__file__).resolve().parents[1]
BINARY = ROOT / "challenge_guard"


def free_port() -> int:
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    try:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])
    finally:
        sock.close()


def request(port: int, path: str, headers: dict[str, str] | None = None) -> int:
    conn = http.client.HTTPConnection("127.0.0.1", port, timeout=3)
    try:
        conn.request("GET", path, headers=headers or {})
        return int(conn.getresponse().status)
    finally:
        conn.close()


def wait_ready(port: int) -> None:
    deadline = time.time() + 5
    while time.time() < deadline:
        try:
            request(port, "/health")
            return
        except Exception:
            time.sleep(0.05)
    raise RuntimeError("challenge_guard did not start")


def assert_equal(actual: int, expected: int, message: str) -> None:
    if actual != expected:
        raise AssertionError(f"{message}: expected {expected}, got {actual}")


def main() -> int:
    if not BINARY.exists():
        print("challenge_guard provider tests skipped: binary not built")
        return 0

    with tempfile.TemporaryDirectory() as tmp:
        root = pathlib.Path(tmp)
        cache = root / "provider_ranges.txt"
        cache.write_text("198.51.100.0/24\n", encoding="utf-8")
        port = free_port()
        config = root / "config.json"
        config.write_text(json.dumps({
            "database": {"host": "127.0.0.1", "user": "", "password": "", "name": ""},
            "network": {
                "waf_challenge_enabled": True,
                "waf_challenge_bind": "127.0.0.1",
                "waf_challenge_port": port,
                "waf_challenge_secret": "test-secret",
                "provider_token_gate_enabled": True,
                "provider_token_ipv4_cidrs": "127.0.0.1/32",
                "provider_token_ipv6_cidrs": "",
                "provider_token_cache_file": str(cache),
            },
        }), encoding="utf-8")
        env = os.environ.copy()
        env["PTEROPROTECT_CONFIG_PATH"] = str(config)
        proc = subprocess.Popen([str(BINARY)], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        try:
            wait_ready(port)
            assert_equal(request(port, "/check-provider-api"), 401, "provider localhost without token must be rejected")
            assert_equal(
                request(port, "/check-provider-api", {"Authorization": "Bearer ptlc_invalid"}),
                401,
                "provider localhost with invalid bearer must be rejected",
            )
            assert_equal(
                request(port, "/check-provider-api", {"Cookie": "pp_clearance=fake; pterodactyl_session=fake"}),
                401,
                "provider localhost with clearance/session cookies but no API token must be rejected",
            )
            assert_equal(
                request(port, "/check-provider-api", {"X-Real-IP": "203.0.113.10"}),
                204,
                "non-provider forwarded client should pass provider API gate",
            )
            assert_equal(
                request(port, "/check-provider-api", {"X-Real-IP": "198.51.100.10"}),
                401,
                "provider cache CIDR should be enforced by challenge_guard",
            )
        finally:
            proc.terminate()
            try:
                proc.wait(timeout=3)
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.wait(timeout=3)

    print("challenge_guard provider tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

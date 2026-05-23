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


def start_guard(root: pathlib.Path, enabled: bool = True):
    port = free_port()
    config = root / f"config-{port}.json"
    attack_rules = root / "attack_rules.tsv"
    config.write_text(json.dumps({
        "database": {"host": "127.0.0.1", "user": "", "password": "", "name": ""},
        "network": {
            "waf_challenge_enabled": enabled,
            "waf_challenge_bind": "127.0.0.1",
            "waf_challenge_port": port,
            "waf_challenge_secret": "test-secret",
            "waf_challenge_type": 1,
            "provider_token_gate_enabled": False,
            "runtime_attack_rules_enabled": True,
            "runtime_attack_rules_file": str(attack_rules),
            "runtime_attack_rule_min_confidence": 72,
        },
    }), encoding="utf-8")
    env = os.environ.copy()
    env["PTEROPROTECT_CONFIG_PATH"] = str(config)
    proc = subprocess.Popen([str(BINARY)], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    wait_ready(port)
    return proc, port, attack_rules


def stop_guard(proc) -> str:
    proc.terminate()
    try:
        proc.wait(timeout=3)
    except subprocess.TimeoutExpired:
        proc.kill()
        proc.wait(timeout=3)
    if proc.stderr is None:
        return ""
    return proc.stderr.read().decode("utf-8", errors="replace")


def assert_equal(actual: int, expected: int, message: str) -> None:
    if actual != expected:
        raise AssertionError(f"{message}: expected {expected}, got {actual}")


def main() -> int:
    if not BINARY.exists():
        print("challenge_guard session tests skipped: binary not built")
        return 0
    with tempfile.TemporaryDirectory() as tmp:
        root = pathlib.Path(tmp)
        proc, port, attack_rules = start_guard(root, True)
        try:
            browser = {"User-Agent": "Mozilla/5.0"}
            now = int(time.time())
            attack_rules.write_text(
                f"GET|/protected|5xx|browser\t90\t30\tunit-test\t{now + 60}\t{now}\n",
                encoding="utf-8",
            )
            assert_equal(request(port, "/check", browser), 401, "missing clearance cookie must fail")
            assert_equal(
                request(port, "/check", {"User-Agent": "Mozilla/5.0", "Cookie": "pterodactyl_session=fake"}),
                401,
                "bare pterodactyl_session cookie must not bypass challenge",
            )
            assert_equal(
                request(port, "/check", {"User-Agent": "Mozilla/5.0", "Cookie": "pp_clearance=fake; pterodactyl_session=fake"}),
                401,
                "malformed clearance cookie must fail",
            )
            assert_equal(
                request(port, "/check-web", {
                    "User-Agent": "Mozilla/5.0",
                    "X-PteroProtect-Original-URI": "/protected?cache=1",
                    "X-PteroProtect-Original-Method": "GET",
                }),
                401,
                "active runtime attack rule must require web challenge",
            )
            assert_equal(request(port, "/new", {"User-Agent": "curl/8.0"}), 401, "non-browser challenge issuance must fail")
        finally:
            stderr = stop_guard(proc)
        if "runtime_attack_rule_challenge" not in stderr:
            raise AssertionError(f"runtime attack rule challenge was not logged; stderr={stderr[-2000:]}")

        proc, port, _attack_rules = start_guard(root, False)
        try:
            assert_equal(request(port, "/check"), 204, "disabled challenge check should pass")
            assert_equal(request(port, "/page?rd=/"), 204, "disabled challenge page should not redirect-loop")
        finally:
            stop_guard(proc)

    print("challenge_guard session tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

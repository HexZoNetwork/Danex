#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import random
import threading
import time
import urllib.error
import urllib.request
from typing import Dict, List


def req(url: str, method: str = "GET", body: bytes | None = None, timeout: float = 3.0) -> int:
    headers = {
        "User-Agent": random.choice([
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36",
            "curl/8.1.2",
            "python-requests/2.31",
            "Mozilla/5.0 HeadlessChrome/124.0.0.0",
        ])
    }
    r = urllib.request.Request(url, data=body, method=method, headers=headers)
    try:
        with urllib.request.urlopen(r, timeout=timeout) as resp:
            _ = resp.read(64)
            return int(resp.status)
    except urllib.error.HTTPError as exc:
        return int(exc.code)
    except Exception:
        return 0


def worker(base: str, paths: List[str], duration: int, per_sec: int, out: Dict[str, int]) -> None:
    end = time.time() + duration
    while time.time() < end:
        start = time.time()
        for _ in range(per_sec):
            p = random.choice(paths)
            method = "GET"
            body = None
            if p.startswith("/auth/login") and random.random() < 0.8:
                method = "POST"
                body = b"email=a%40a.a&password=x"
            st = req(base.rstrip("/") + p, method=method, body=body)
            out[str(st)] = out.get(str(st), 0) + 1
        elapsed = time.time() - start
        sleep_left = max(0.0, 1.0 - elapsed)
        time.sleep(sleep_left)


def poll_state(cp_url: str) -> Dict:
    try:
        r = urllib.request.Request(cp_url.rstrip("/") + "/resilience/state", method="GET")
        with urllib.request.urlopen(r, timeout=2.0) as resp:
            return json.loads(resp.read().decode("utf-8", errors="ignore"))
    except Exception:
        return {}


def main() -> int:
    ap = argparse.ArgumentParser(description="PteroProtect resilience testing harness")
    ap.add_argument("--base-url", default="http://127.0.0.1", help="Public base URL")
    ap.add_argument("--control-plane", default="http://127.0.0.1:18446", help="Control-plane URL")
    ap.add_argument("--duration", type=int, default=60, help="Scenario duration seconds")
    ap.add_argument("--concurrency", type=int, default=8, help="Worker threads")
    ap.add_argument("--rps-per-worker", type=int, default=25, help="Requests per second per worker")
    ap.add_argument("--scenario", choices=["auth-flood", "api-fingerprint", "ws-churn", "mixed"], default="mixed")
    args = ap.parse_args()

    scenario_paths = {
        "auth-flood": [
            "/auth/login",
            "/auth/register",
            "/auth/password",
        ],
        "api-fingerprint": [
            "/api/client",
            "/api/client/servers/aaaaaaaa/resources",
            "/api/client/servers/aaaaaaaa/files/list",
            "/api/client/servers/aaaaaaaa/files/upload",
            "/api/client/chat/messages",
        ],
        "ws-churn": [
            "/api/client/servers/aaaaaaaa/websocket",
            "/api/client/servers/aaaaaaaa/resources",
            "/api/client/servers/aaaaaaaa/activity",
        ],
        "mixed": [
            "/auth/login",
            "/auth/password",
            "/api/client",
            "/api/client/chat/messages",
            "/api/client/create-panel/create",
            "/api/client/servers/aaaaaaaa/files/upload",
            "/api/client/servers/aaaaaaaa/resources",
            "/api/client/servers/aaaaaaaa/websocket",
        ],
    }

    stats: Dict[str, int] = {}
    threads = []

    start_state = poll_state(args.control_plane)
    print(json.dumps({"phase": "baseline", "state": start_state}, indent=2))

    for _ in range(max(1, args.concurrency)):
        t = threading.Thread(
            target=worker,
            args=(args.base_url, scenario_paths[args.scenario], max(5, args.duration), max(1, args.rps_per_worker), stats),
            daemon=True,
        )
        t.start()
        threads.append(t)

    t0 = time.time()
    while time.time() - t0 < args.duration:
        time.sleep(5)
        s = poll_state(args.control_plane)
        print(json.dumps({"phase": "attack", "elapsed": int(time.time() - t0), "state": s.get("state", {})}, indent=2))

    for t in threads:
        t.join(timeout=1.0)

    # Observe recovery window.
    for i in range(1, 7):
        time.sleep(10)
        s = poll_state(args.control_plane)
        print(json.dumps({"phase": "recovery", "step": i, "state": s.get("state", {})}, indent=2))

    print(json.dumps({"phase": "summary", "responses": stats}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

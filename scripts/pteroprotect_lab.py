#!/usr/bin/env python3
"""Local-only PteroProtect regression lab.

The harness starts a synthetic panel under /tmp/pteroprotect-lab by default
and runs bounded probes for session, WAF payload, and self-DOS behavior. It is
intentionally localhost-only unless --allow-nonlocal-target is supplied.
"""

from __future__ import annotations

import argparse
import concurrent.futures
import contextlib
import http.server
import json
import os
import pathlib
import random
import socket
import socketserver
import string
import threading
import time
import urllib.error
import urllib.parse
import urllib.request


LAB_ROOT = pathlib.Path("/tmp/pteroprotect-lab")
SQLI_PAYLOADS = ["' OR '1'='1", "1 UNION SELECT password FROM users", "admin'--"]
RCE_PAYLOADS = [";id", "$(id)", "`whoami`", "| curl http://127.0.0.1/"]


class LabHandler(http.server.BaseHTTPRequestHandler):
    server_version = "PteroProtectLab/1.0"

    def log_message(self, fmt: str, *args: object) -> None:
        with open(LAB_ROOT / "access.log", "a", encoding="utf-8") as fh:
            fh.write("%s %s\n" % (self.address_string(), fmt % args))

    def _json(self, code: int, payload: dict[str, object]) -> None:
        data = json.dumps(payload, sort_keys=True).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self) -> None:
        path = urllib.parse.urlparse(self.path).path
        if path == "/health":
            return self._json(200, {"ok": True})
        if path == "/api/client":
            if "pp_clearance=ok" not in self.headers.get("Cookie", ""):
                return self._json(403, {"error": "session_binding_mismatch", "reason": "missing_cookie"})
            return self._json(200, {"object": "list", "data": []})
        if path.startswith("/vuln/"):
            return self._json(200, {"ok": True, "echo": urllib.parse.urlparse(self.path).query})
        return self._json(404, {"ok": False, "error": "not_found"})

    def do_POST(self) -> None:
        path = urllib.parse.urlparse(self.path).path
        size = min(int(self.headers.get("Content-Length", "0") or "0"), 64 * 1024)
        body = self.rfile.read(size).decode("utf-8", "replace")
        if path == "/__pteroprotect/challenge/solve":
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Set-Cookie", "pp_clearance=ok; Path=/; HttpOnly; SameSite=Lax")
            self.end_headers()
            self.wfile.write(b'{"ok":true,"redirect":"/"}')
            return
        if path.startswith("/vuln/sql") and any(p.lower() in body.lower() for p in SQLI_PAYLOADS):
            return self._json(403, {"blocked": True, "type": "sqli"})
        if path.startswith("/vuln/rce") and any(p.lower() in body.lower() for p in RCE_PAYLOADS):
            return self._json(403, {"blocked": True, "type": "rce"})
        return self._json(200, {"ok": True})


class ThreadingServer(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True


def free_port() -> int:
    with contextlib.closing(socket.socket(socket.AF_INET, socket.SOCK_STREAM)) as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def request(url: str, method: str = "GET", data: bytes | None = None, headers: dict[str, str] | None = None) -> tuple[int, str, dict[str, str]]:
    req = urllib.request.Request(url, data=data, method=method, headers=headers or {})
    try:
        with urllib.request.urlopen(req, timeout=3) as resp:
            return resp.status, resp.read().decode("utf-8", "replace"), dict(resp.headers)
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read().decode("utf-8", "replace"), dict(exc.headers)


def assert_local_target(target: str, allow_nonlocal: bool) -> None:
    host = urllib.parse.urlparse(target).hostname or ""
    if allow_nonlocal:
        return
    if host not in {"127.0.0.1", "localhost", "::1"}:
        raise SystemExit("Refusing non-local target without --allow-nonlocal-target")


def run_session_case(target: str) -> dict[str, object]:
    code1, _, headers = request(target + "/api/client?page=1")
    code2, _, solve_headers = request(
        target + "/__pteroprotect/challenge/solve",
        method="POST",
        data=b'{"nonce":"lab","pow_counter":1,"pow_hash":"0000"}',
        headers={"Content-Type": "application/json"},
    )
    cookie = solve_headers.get("Set-Cookie", "").split(";", 1)[0]
    code3, body3, _ = request(target + "/api/client?page=1", headers={"Cookie": cookie})
    return {"missing_clearance": code1, "solve": code2, "after_solve": code3, "body": body3[:120]}


def run_payload_cases(target: str) -> list[dict[str, object]]:
    out: list[dict[str, object]] = []
    for kind, payloads in (("sqli", SQLI_PAYLOADS), ("rce", RCE_PAYLOADS)):
        for payload in payloads:
            code, body, _ = request(
                target + f"/vuln/{kind}",
                method="POST",
                data=json.dumps({"value": payload}).encode(),
                headers={"Content-Type": "application/json"},
            )
            out.append({"kind": kind, "payload": payload, "code": code, "blocked": code in {403, 406}, "body": body[:120]})
    return out


def run_bounded_worker(target: str, total: int, concurrency: int) -> dict[str, object]:
    paths = ["/api/client?page=1", "/__pteroprotect/challenge/solve", "/vuln/sql", "/vuln/rce"]
    started = time.time()

    def one(_: int) -> int:
        path = random.choice(paths)
        method = "POST" if path != "/api/client?page=1" else "GET"
        body = None
        headers = {"User-Agent": "PteroProtectLab/1.0"}
        if method == "POST":
            body = json.dumps({"value": random.choice(SQLI_PAYLOADS + RCE_PAYLOADS + ["normal"]) }).encode()
            headers["Content-Type"] = "application/json"
        code, _, _ = request(target + path, method=method, data=body, headers=headers)
        return code

    counts: dict[int, int] = {}
    with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as pool:
        for code in pool.map(one, range(total)):
            counts[code] = counts.get(code, 0) + 1
    return {"total": total, "concurrency": concurrency, "seconds": round(time.time() - started, 3), "codes": counts}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--target", default="")
    parser.add_argument("--allow-nonlocal-target", action="store_true")
    parser.add_argument("--requests", type=int, default=80)
    parser.add_argument("--concurrency", type=int, default=8)
    args = parser.parse_args()

    LAB_ROOT.mkdir(parents=True, exist_ok=True)
    server = None
    thread = None
    if args.target:
        target = args.target.rstrip("/")
        assert_local_target(target, args.allow_nonlocal_target)
    else:
        port = free_port()
        server = ThreadingServer(("127.0.0.1", port), LabHandler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        target = f"http://127.0.0.1:{port}"

    result = {
        "target": target,
        "lab_root": str(LAB_ROOT),
        "session": run_session_case(target),
        "payloads": run_payload_cases(target),
        "bounded_worker": run_bounded_worker(target, max(1, min(args.requests, 1000)), max(1, min(args.concurrency, 64))),
    }
    (LAB_ROOT / "last-result.json").write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(result, indent=2, sort_keys=True))

    if server is not None:
        server.shutdown()
    if thread is not None:
        thread.join(timeout=1)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

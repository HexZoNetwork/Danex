#!/usr/bin/env python3
"""
Defensive hardmode load harness (bounded, non-destructive):
- Persistent HTTPS keep-alive workers (HTTP/1.1)
- Mixed endpoint traffic profile
- Parallel TLS handshake churn

Use only on infrastructure you own/control.
"""

from __future__ import annotations

import argparse
import concurrent.futures
import http.client
import random
import socket
import ssl
import string
import threading
import time
from collections import Counter
from dataclasses import dataclass
from urllib.parse import urlparse


def is_valid_ip(ip):
    try:
        socket.inet_pton(socket.AF_INET, ip)
        return True
    except socket.error:
        try:
            socket.inet_pton(socket.AF_INET6, ip)
            return True
        except socket.error:
            return False


SAFE_MAX_WORKERS = 400
SAFE_MAX_REQUESTS = 120_000
SAFE_MAX_HANDSHAKES = 20_000


@dataclass
class Stats:
    status_counts: Counter
    error_counts: Counter
    latencies_ms: list[float]

    def __init__(self) -> None:
        self.status_counts = Counter()
        self.error_counts = Counter()
        self.latencies_ms = []

    def merge(self, other: "Stats") -> None:
        self.status_counts.update(other.status_counts)
        self.error_counts.update(other.error_counts)
        self.latencies_ms.extend(other.latencies_ms)


def rand_token(n: int) -> str:
    alphabet = string.ascii_letters + string.digits + "-_"
    return "".join(random.choice(alphabet) for _ in range(n))


def pick_request_path(i: int) -> tuple[str, str, str | None]:
    # (method, path, payload)
    slot = i % 10
    if slot == 0:
        return "GET", "/api/client", None
    if slot == 1:
        return "GET", "/api/client/chat/messages", None
    if slot == 2:
        return "GET", "/api/client/servers/1/websocket", None
    if slot == 3:
        return "GET", "/__pteroprotect/challenge/new?hc=8&dm=8&m=0", None
    if slot == 4:
        return "GET", "/?id=1+UNION+SELECT+1", None
    if slot == 5:
        return "GET", f"/{rand_token(18)}?x={rand_token(120)}", None
    if slot == 6:
        return "POST", "/auth/login", '{"user":"chaos","password":"x"}'
    if slot == 7:
        return "GET", "/api/client/servers/1/resources", None
    if slot == 8:
        return "GET", "/api/client/chat/messages?limit=100&since_id=1", None
    return "GET", "/", None


def one_worker(
    host: str,
    port: int,
    use_ssl: bool,
    requests_per_worker: int,
    timeout_s: float,
    worker_id: int,
) -> Stats:
    stats = Stats()

    conn: http.client.HTTPConnection | http.client.HTTPSConnection
    if use_ssl:
        ctx = ssl.create_default_context()
        conn = http.client.HTTPSConnection(host, port, timeout=timeout_s, context=ctx)
    else:
        conn = http.client.HTTPConnection(host, port, timeout=timeout_s)

    try:
        conn.connect()
    except Exception as e:
        stats.error_counts[f"connect:{type(e).__name__}"] += requests_per_worker
        return stats

    for i in range(requests_per_worker):
        method, path, payload = pick_request_path(worker_id * requests_per_worker + i)
        headers = {
            "User-Agent": f"hardmode-worker/{worker_id}",
            "Accept": "*/*",
            "Connection": "keep-alive",
            "Accept-Language": "en-US,en;q=0.8",
        }
        body = None
        if payload is not None:
            body = payload.encode("utf-8")
            headers["Content-Type"] = "application/json"
            headers["Content-Length"] = str(len(body))

        t0 = time.perf_counter()
        try:
            conn.request(method, path, body=body, headers=headers)
            resp = conn.getresponse()
            _ = resp.read()
            status = int(resp.status)
            stats.status_counts[str(status)] += 1
            stats.latencies_ms.append((time.perf_counter() - t0) * 1000.0)
        except Exception as e:
            stats.error_counts[type(e).__name__] += 1
            # attempt reconnect once and continue
            try:
                conn.close()
                if use_ssl:
                    conn = http.client.HTTPSConnection(host, port, timeout=timeout_s, context=ssl.create_default_context())
                else:
                    conn = http.client.HTTPConnection(host, port, timeout=timeout_s)
                conn.connect()
            except Exception as re:
                stats.error_counts[f"reconnect:{type(re).__name__}"] += 1

    try:
        conn.close()
    except Exception:
        pass
    return stats


def tls_handshake_worker(host: str, port: int, handshakes: int, timeout_s: float) -> Counter:
    counts = Counter()
    for _ in range(handshakes):
        sock = None
        wrapped = None
        try:
            raw_ctx = ssl.create_default_context()
            sock = socket_create_connection(host, port, timeout_s)
            wrapped = raw_ctx.wrap_socket(sock, server_hostname=host)
            _ = wrapped.version()
            counts["OK"] += 1
        except Exception:
            counts["FAIL"] += 1
        finally:
            try:
                if wrapped is not None:
                    wrapped.close()
            except Exception:
                pass
            try:
                if sock is not None:
                    sock.close()
            except Exception:
                pass
    return counts


def socket_create_connection(host: str, port: int, timeout_s: float):
    import socket

    return socket.create_connection((host, port), timeout=timeout_s)


def p95(values: list[float]) -> float:
    if not values:
        return 0.0
    arr = sorted(values)
    idx = int(0.95 * (len(arr) - 1))
    return arr[idx]


def main() -> int:
    parser = argparse.ArgumentParser(description="Defensive hardmode load harness")
    parser.add_argument("base_url", help="https://example.com")
    parser.add_argument("--workers", type=int, default=180, help=f"max {SAFE_MAX_WORKERS}")
    parser.add_argument("--requests-per-worker", type=int, default=14)
    parser.add_argument("--handshake-workers", type=int, default=80, help=f"bounded by {SAFE_MAX_WORKERS}")
    parser.add_argument("--handshakes-per-worker", type=int, default=18)
    parser.add_argument("--timeout", type=float, default=6.0)
    args = parser.parse_args()

    parsed = urlparse(args.base_url)
    if parsed.scheme not in {"http", "https"} or not parsed.hostname:
        raise SystemExit("base_url must be absolute http(s) URL")

    workers = max(1, min(args.workers, SAFE_MAX_WORKERS))
    req_per_worker = max(1, args.requests_per_worker)
    total_requests = workers * req_per_worker
    if total_requests > SAFE_MAX_REQUESTS:
        raise SystemExit(f"total requests too high: {total_requests} > {SAFE_MAX_REQUESTS}")

    hs_workers = max(1, min(args.handshake_workers, SAFE_MAX_WORKERS))
    hs_per_worker = max(1, args.handshakes_per_worker)
    total_handshakes = hs_workers * hs_per_worker
    if total_handshakes > SAFE_MAX_HANDSHAKES:
        raise SystemExit(f"total handshakes too high: {total_handshakes} > {SAFE_MAX_HANDSHAKES}")

    host = parsed.hostname
    use_ssl = parsed.scheme == "https"
    port = parsed.port or (443 if use_ssl else 80)

    print("=== hardmode_l7_profile ===")
    print(f"target={parsed.scheme}://{host}:{port}")
    print(f"workers={workers} requests_per_worker={req_per_worker} total_requests={total_requests}")
    print(f"handshake_workers={hs_workers} handshakes_per_worker={hs_per_worker} total_handshakes={total_handshakes}")

    all_stats = Stats()
    t0 = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as ex:
        futs = [
            ex.submit(one_worker, host, port, use_ssl, req_per_worker, args.timeout, wid)
            for wid in range(workers)
        ]
        for fut in concurrent.futures.as_completed(futs):
            all_stats.merge(fut.result())
    t1 = time.perf_counter()

    print("--- http_status_distribution ---")
    for k, v in sorted(all_stats.status_counts.items(), key=lambda kv: int(kv[0])):
        print(f"  {k} {v}")
    if all_stats.error_counts:
        print("--- http_errors ---")
        for k, v in all_stats.error_counts.most_common():
            print(f"  {k} {v}")
    if all_stats.latencies_ms:
        avg = sum(all_stats.latencies_ms) / len(all_stats.latencies_ms)
        print("--- latency_ms ---")
        print(f"  avg={avg:.2f} p95={p95(all_stats.latencies_ms):.2f} min={min(all_stats.latencies_ms):.2f} max={max(all_stats.latencies_ms):.2f}")
    print(f"duration_http_s={(t1 - t0):.2f}")

    hs_counts = Counter()
    t2 = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=hs_workers) as ex:
        lock = threading.Lock()

        def runner() -> None:
            c = tls_handshake_worker(host, port, hs_per_worker, args.timeout)
            with lock:
                hs_counts.update(c)

        futs = [ex.submit(runner) for _ in range(hs_workers)]
        for fut in concurrent.futures.as_completed(futs):
            _ = fut.result()
    t3 = time.perf_counter()

    print("--- tls_handshake_distribution ---")
    print(f"  OK {hs_counts.get('OK', 0)}")
    print(f"  FAIL {hs_counts.get('FAIL', 0)}")
    print(f"duration_tls_s={(t3 - t2):.2f}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

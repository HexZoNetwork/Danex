#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import re


ROOT = pathlib.Path(__file__).resolve().parents[1]
FILTER_DIR = ROOT / "host_overrides/fail2ban/filter.d"


CASES = {
    "pteroprotect-wings-api-abuse.conf": {
        "malicious": [
            '8.8.8.8 - - [17/May/2026:12:00:00 +0000] "GET /api/servers/abc/files/list HTTP/1.1" 401 12 "-" "curl"',
            '8.8.4.4 - - [17/May/2026:12:00:01 +0000] "POST /api/remote/servers/abc/install HTTP/1.1" 403 12 "-" "curl"',
        ],
        "benign": [
            '1.1.1.1 - - [17/May/2026:12:00:02 +0000] "GET /api/servers/abc/resources HTTP/1.1" 429 12 "-" "Wings"',
            '1.0.0.1 - - [17/May/2026:12:00:03 +0000] "GET /api/servers/abc/ws HTTP/1.1" 502 12 "-" "Wings"',
        ],
    },
    "pteroprotect-auth-abuse.conf": {
        "malicious": [
            '203.0.113.10 - - [17/May/2026:12:01:00 +0000] "POST /auth/login HTTP/1.1" 422 12 "-" "curl"',
            '203.0.113.11 - - [17/May/2026:12:01:01 +0000] "GET /auth/password/reset HTTP/1.1" 419 12 "-" "curl"',
        ],
        "benign": [
            '203.0.113.12 - - [17/May/2026:12:01:02 +0000] "GET /auth/login HTTP/1.1" 200 1200 "-" "Mozilla"',
            '203.0.113.13 - - [17/May/2026:12:01:03 +0000] "GET / HTTP/1.1" 404 12 "-" "curl"',
        ],
    },
    "pteroprotect-nginx-sqli.conf": {
        "malicious": [
            '192.0.2.10 - - [17/May/2026:12:02:00 +0000] "GET /?q=UNION%20SELECT%20password%20FROM%20users HTTP/1.1" 403 12 "-" "sqlmap"',
            '192.0.2.11 - - [17/May/2026:12:02:01 +0000] "GET /?id=%27 OR 1=1 HTTP/1.1" 403 12 "-" "sqlmap"',
        ],
        "benign": [
            '192.0.2.12 - - [17/May/2026:12:02:02 +0000] "GET /server/select-plan HTTP/1.1" 200 12 "-" "Mozilla"',
            '192.0.2.13 - - [17/May/2026:12:02:03 +0000] "GET /docs/from-backup HTTP/1.1" 200 12 "-" "Mozilla"',
        ],
    },
    "pteroprotect-waf-deny.conf": {
        "malicious": [
            '[2026-05-17T12:03:00Z] action=deny reason=signature ip=198.51.100.20 path=/bad',
            '[2026-05-17T12:03:01Z] action=deny reason=headless-stealth ip=198.51.100.21 path=/admin',
        ],
        "benign": [
            '[2026-05-17T12:03:02Z] action=deny reason=rate-limit ip=198.51.100.22 path=/api/client',
            '[2026-05-17T12:03:03Z] action=allow reason=signature ip=198.51.100.23 path=/ok',
        ],
    },
}


def run_case(name: str, lines: list[str]) -> int:
    text = (FILTER_DIR / name).read_text(encoding="utf-8")
    raw = ""
    for line in text.splitlines():
        if line.startswith("failregex = "):
            raw = line.split("=", 1)[1].strip()
            break
    if not raw:
        raise AssertionError(f"{name}: failregex not found")
    pattern = raw.replace("%%", "%").replace("<HOST>", r"(?P<host>\d{1,3}(?:\.\d{1,3}){3}|[0-9A-Fa-f:.]+)")
    regex = re.compile(pattern)
    return sum(1 for line in lines if regex.search(line))


def main() -> int:
    for name, case in CASES.items():
        malicious_matches = run_case(name, case["malicious"])
        benign_matches = run_case(name, case["benign"])
        if malicious_matches != len(case["malicious"]):
            raise AssertionError(f"{name}: expected {len(case['malicious'])} malicious matches, got {malicious_matches}")
        if benign_matches != 0:
            raise AssertionError(f"{name}: expected 0 benign matches, got {benign_matches}")
    print("fail2ban filter tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
from __future__ import annotations

import pathlib


ROOT = pathlib.Path(__file__).resolve().parents[1]


def assert_contains(text: str, needle: str, message: str) -> None:
    if needle not in text:
        raise AssertionError(message)


def main() -> int:
    waf = (ROOT / "panel_overrides/app/Http/Middleware/Security/PteroProtectWaf.php").read_text(encoding="utf-8")
    cfg = (ROOT / "panel_overrides/config/pteroprotect.php").read_text(encoding="utf-8")
    nginx = (ROOT / "host_overrides/nginx/snippets/pteroprotect_server.conf").read_text(encoding="utf-8")

    assert_contains(waf, "isAllowedMethod($request, $config)", "WAF must reject disallowed HTTP methods")
    assert_contains(waf, "hasExcessiveHeaders($request, $config)", "WAF must bound header count and size")
    assert_contains(waf, "hasExcessiveCookies($request, $config)", "WAF must bound cookie count and size")
    assert_contains(waf, "isRateLimitedBySubject($request, $category", "WAF must include subject/session rate buckets")
    assert_contains(waf, "PteroProtectClearanceToken::isValid", "subject buckets must only trust valid clearance tokens")

    for key in (
        "allowed_methods",
        "max_header_count",
        "max_cookie_bytes",
        "subject_limit_enabled",
        "websocket_subject_limit",
    ):
        assert_contains(cfg, key, f"config must expose {key}")

    assert_contains(nginx, "if ($request_method = TRACE) { return 444; }", "nginx must drop TRACE")
    assert_contains(nginx, "client_header_timeout 8s;", "nginx must bound slow header reads")

    print("waf hardening static tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

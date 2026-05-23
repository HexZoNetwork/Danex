#!/usr/bin/env python3
from __future__ import annotations

import pathlib


ROOT = pathlib.Path(__file__).resolve().parents[1]


def assert_contains(text: str, needle: str, message: str) -> None:
    if needle not in text:
        raise AssertionError(message)


def main() -> int:
    headers = (ROOT / "panel_overrides/app/Http/Middleware/SetSecurityHeaders.php").read_text(encoding="utf-8")

    assert_contains(
        headers,
        "https://static.cloudflareinsights.com",
        "CSP script-src must allow the Cloudflare Insights beacon script",
    )
    assert_contains(
        headers,
        "https://cloudflareinsights.com",
        "CSP connect-src must allow the Cloudflare Insights collection endpoint",
    )
    assert_contains(headers, "object-src 'none'", "CSP must continue blocking plugin/object execution")
    assert_contains(headers, "frame-ancestors 'none'", "CSP must continue blocking framing")

    print("security headers static tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

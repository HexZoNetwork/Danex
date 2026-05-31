#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import pathlib


ROOT = pathlib.Path(__file__).resolve().parents[1]
API_PATH = ROOT / "scripts" / "pteroprotect_challenge_api.py"


class FakeHandler:
    def __init__(self, peer: str, headers: dict[str, str] | None = None) -> None:
        self.client_address = (peer, 12345)
        self.headers = headers or {}


def load_api():
    spec = importlib.util.spec_from_file_location("pteroprotect_challenge_api", API_PATH)
    if spec is None or spec.loader is None:
        raise RuntimeError("failed to load challenge api")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def assert_equal(actual, expected, message: str) -> None:
    if actual != expected:
        raise AssertionError(f"{message}: expected {expected!r}, got {actual!r}")


def assert_true(value, message: str) -> None:
    if not value:
        raise AssertionError(message)


def assert_false(value, message: str) -> None:
    if value:
        raise AssertionError(message)


def main() -> int:
    api = load_api()

    assert_equal(
        api.client_ip(FakeHandler("198.51.100.20", {"X-Real-IP": "203.0.113.10"})),
        "198.51.100.20",
        "non-loopback peers must not be able to spoof forwarded IP headers",
    )
    assert_equal(
        api.client_ip(FakeHandler("127.0.0.1", {"X-Real-IP": "203.0.113.10"})),
        "203.0.113.10",
        "loopback nginx subrequests should pass canonical remote_addr",
    )
    assert_equal(
        api.client_ip(FakeHandler("127.0.0.1", {"X-Real-IP": "garbage", "X-Forwarded-For": "2001:db8::10, 10.0.0.1"})),
        "2001:db8::10",
        "invalid X-Real-IP should fall back to first valid XFF value",
    )

    original_net_setting = api.net_setting
    try:
        api.net_setting = lambda name, default: "" if name == "waf_challenge_secret" else ("legacy-token" if name == "unblock_portal_token" else default)
        assert_true(api.challenge_secret() != "legacy-token", "challenge secret must not reuse unblock token by default")
        api.net_setting = lambda name, default: True if name == "waf_challenge_legacy_secret_fallback" else ("" if name == "waf_challenge_secret" else ("legacy-token" if name == "unblock_portal_token" else default))
        assert_equal(api.challenge_secret(), "legacy-token", "legacy fallback should require explicit opt-in")
    finally:
        api.net_setting = original_net_setting

    assert_true(api.provider_api_allowed(FakeHandler("127.0.0.1")), "non-provider requests should not require API token")
    assert_false(
        api.provider_api_allowed(FakeHandler("127.0.0.1", {"X-PteroProtect-Provider-Token-Block": "1"})),
        "provider-range requests without token should be rejected",
    )
    assert_true(
        api.provider_api_allowed(FakeHandler("127.0.0.1", {"X-PteroProtect-Provider-Token-Block": "1", "X-PteroProtect-Has-Token": "1"})),
        "nginx-computed token presence should satisfy provider gate",
    )
    assert_false(
        api.provider_api_allowed(FakeHandler("127.0.0.1", {"X-PteroProtect-Provider-Token-Block": "1", "Authorization": "Bearer ptlc_testtoken"})),
        "fallback must not trust unvalidated bearer headers without nginx token signal",
    )

    assert_equal(api.normalize_redirect_path("https://evil.example/path?q=1"), "/path?q=1", "redirect sanitizer should strip external origin")
    assert_equal(api.normalize_redirect_path("//evil.example/path"), "/path", "redirect sanitizer should keep only a local path")
    assert_equal(api.normalize_redirect_path("/ok/path?x=1#frag"), "/ok/path?x=1#frag", "safe local redirect should survive")
    assert_equal(api.normalize_redirect_path("/bad\nheader"), "/", "redirect sanitizer should reject header injection")

    original_time = api.time.time
    try:
        api._rate_windows.clear()
        api.time.time = lambda: 1000
        assert_true(api.rate_allow("203.0.113.44", "unit", 2, 60), "first request should pass")
        assert_true(api.rate_allow("203.0.113.44", "unit", 2, 60), "second request should pass")
        assert_false(api.rate_allow("203.0.113.44", "unit", 2, 60), "third request in same window should be limited")
        api.time.time = lambda: 1061
        assert_true(api.rate_allow("203.0.113.44", "unit", 2, 60), "new window should reset rate limit")
    finally:
        api.time.time = original_time
        api._rate_windows.clear()

    print("challenge_api tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

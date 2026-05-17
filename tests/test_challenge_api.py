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

    print("challenge_api tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import pathlib
import tempfile


ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "render_provider_gate.py"


def load_renderer():
    spec = importlib.util.spec_from_file_location("render_provider_gate", SCRIPT)
    if spec is None or spec.loader is None:
        raise RuntimeError("failed to load renderer")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def assert_contains(text: str, needle: str, message: str) -> None:
    if needle not in text:
        raise AssertionError(message)


def assert_not_contains(text: str, needle: str, message: str) -> None:
    if needle in text:
        raise AssertionError(message)


def main() -> int:
    renderer = load_renderer()
    with tempfile.TemporaryDirectory() as tmp:
        root = pathlib.Path(tmp)
        cache = root / "provider_ranges.txt"
        cache.write_text("198.51.100.0/24\n# comment\n2001:db8:42::/48\ninvalid\n", encoding="utf-8")
        out = root / "provider_gate.conf"
        renderer.render(out, True, "203.0.113.0/24,198.51.100.5", "2001:db8::/32", str(cache))
        text = out.read_text(encoding="utf-8")

        assert_contains(text, "203.0.113.0/24 1;", "inline IPv4 CIDR should be rendered")
        assert_contains(text, "198.51.100.5/32 1;", "inline IPv4 host should be normalized")
        assert_contains(text, "198.51.100.0/24 1;", "cache CIDR should be rendered")
        assert_contains(text, "2001:db8::/32 1;", "inline IPv6 CIDR should be rendered")
        assert_contains(text, "2001:db8:42::/48 1;", "cache IPv6 CIDR should be rendered")
        assert_contains(text, "~*^Bearer\\s+(ptla_|ptlc_)", "provider token map should only accept panel bearer prefixes")
        assert_contains(text, "geo $remote_addr $pteroprotect_cdn_proxy_remote_range", "CDN bypass should check direct remote address")
        assert_contains(text, "geo $realip_remote_addr $pteroprotect_cdn_proxy_realip_range", "CDN bypass should survive real_ip rewrites")
        assert_contains(text, '"1:0:0" 1;', "provider block must only apply when client is not CDN bypass")
        assert_contains(text, "173.245.48.0/20 1;", "Cloudflare bypass CIDRs should be built in")
        assert_not_contains(text, "$http_x_api_key", "provider token map should not trust X-API-Key")
        assert_not_contains(text, "$arg_token", "provider token map should not trust query token")

    print("provider_gate_render tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

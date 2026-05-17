#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import re


ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/ddos_host_logger.sh"


HIGH_CONFIDENCE = ["bad-token:*", "sqli-probe:*", "probe-scan:*", "proxy-swarm:*", "nginx-limiter:*"]
OVERLOAD = ["*overload-fast:*", "*overload-hard:*", "http-access-hard:*", "established-hard:*", "syn-recv-hard:*", "clear-threshold:*"]


def function_body(text: str, name: str) -> str:
    match = re.search(rf"^{re.escape(name)}\(\) \{{\n(?P<body>.*?)\n\}}", text, re.MULTILINE | re.DOTALL)
    if not match:
        raise AssertionError(f"function not found: {name}")
    return match.group("body")


def assert_contains(text: str, needle: str, message: str) -> None:
    if needle not in text:
        raise AssertionError(message)


def main() -> int:
    text = SCRIPT.read_text(encoding="utf-8")
    high = function_body(text, "is_high_confidence_block_reason")
    overload = function_body(text, "is_overload_block_reason")
    add_block = function_body(text, "add_ipset_block")

    for reason in HIGH_CONFIDENCE:
        assert_contains(high, reason, f"high-confidence reason missing: {reason}")
    for reason in OVERLOAD:
        assert_contains(overload, reason, f"overload reason missing: {reason}")

    assert_contains(add_block, 'is_private_ip "${ip}" || is_host_ip "${ip}"', "local/private/host IPs must always be protected")
    assert_contains(add_block, 'is_recently_authenticated_ip "${ip}" && ! is_high_confidence_block_reason "${reason}"', "recent auth must not bypass high-confidence reasons")
    assert_contains(add_block, 'is_ip_trust_protected_ip "${ip}" && ! is_high_confidence_block_reason "${reason}"', "IP trust must not bypass high-confidence reasons")
    assert_contains(add_block, 'is_overload_block_reason "${reason}"', "whitelist overload override must be explicit")
    assert_contains(add_block, 'is_high_confidence_block_reason "${reason}"', "whitelist high-confidence override must be explicit")
    assert_contains(add_block, 'skip-block ip=%s reason=whitelisted', "non-high-confidence whitelist skip must remain")

    print("ddos reason policy tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

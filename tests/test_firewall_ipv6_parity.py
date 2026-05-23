#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import re


ROOT = pathlib.Path(__file__).resolve().parents[1]
MANAGER = ROOT / "scripts/pteroprotect_firewall_manager.sh"
INSTALLER = ROOT / "scripts/install_host_protection.sh"


def assert_contains(text: str, needle: str, message: str) -> None:
    if needle not in text:
        raise AssertionError(message)


def assert_regex(text: str, pattern: str, message: str) -> None:
    if not re.search(pattern, text, re.MULTILINE | re.DOTALL):
        raise AssertionError(message)


def main() -> int:
    manager = MANAGER.read_text(encoding="utf-8")
    installer = INSTALLER.read_text(encoding="utf-8")

    for name in ["ALLOW_SET", "SET_TMP", "SET_PERM", "DYN_BLOCK4"]:
        assert_contains(manager, name, f"manager missing IPv4 symbol {name}")
    for name in ["ALLOW_SET6", "SET_TMP6", "SET_PERM6", "DYN_BLOCK6"]:
        assert_contains(manager, name, f"manager missing IPv6 symbol {name}")

    assert_contains(manager, 'ipset create "${DYN_BLOCK4}" hash:ip family inet timeout 0 counters', "dynamic IPv4 ipset must use counters")
    assert_contains(manager, 'ipset create "${DYN_BLOCK6}" hash:ip family inet6 timeout 0 counters', "dynamic IPv6 ipset must use counters")
    assert_contains(manager, 'ip6tables -C INPUT -j "${CHAIN6}"', "IPv6 chain must hook INPUT")
    assert_contains(manager, '--name "${RECENT_NAME6}" --update --seconds "${DDOS_WINDOW}" --hitcount "${DDOS_HITCOUNT}" -j DROP', "IPv6 recent drop rule must mirror IPv4")
    assert_contains(manager, 'is_ipv6_value "${value}"', "manager must route IPv6 values to IPv6 sets")
    assert_contains(manager, 'ipset del "${DYN_BLOCK6}"', "unban must clear dynamic IPv6 set")

    assert_regex(installer, r'IPSET4="pteroprotect_block_v4".*IPSET6="pteroprotect_block_v6"', "installer must define both dynamic block sets")
    assert_contains(installer, 'BLOCK_CHAIN6="PTEROPROTECT-HOST-V6-BLOCK"', "installer must define IPv6 host block chain")
    assert_contains(installer, 'ipset create "${IPSET6}" hash:ip family inet6 timeout "${BLACKHOLE_TTL}" counters', "installer IPv6 dynamic set must use counters")
    assert_contains(installer, 'ip6tables -A "${BLOCK_CHAIN6}" -m set --match-set "${IPSET6}" src -j DROP', "installer IPv6 chain must drop dynamic block set")
    assert_contains(installer, '--hashlimit-name pteroprotect_unblock_v4', "unblock portal must be rate-limited before ACCEPT")
    if "SET --add-set" in installer:
        raise AssertionError("installer must not promote kernel rate-limit hits into long dynamic blackhole sets")

    print("firewall IPv6 parity tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
from __future__ import annotations

import pathlib


ROOT = pathlib.Path(__file__).resolve().parents[1]
DDOS_LOGGER = ROOT / "scripts/ddos_host_logger.sh"
SECURITY_LOG_WATCH = ROOT / "scripts/security_log_watch.py"
UNBLOCK_PORTAL = ROOT / "scripts/unblock_portal.py"


def assert_contains(text: str, needle: str, message: str) -> None:
    if needle not in text:
        raise AssertionError(message)


def main() -> int:
    ddos = DDOS_LOGGER.read_text(encoding="utf-8")
    watch = SECURITY_LOG_WATCH.read_text(encoding="utf-8")
    portal = UNBLOCK_PORTAL.read_text(encoding="utf-8")

    assert_contains(ddos, "firewall_manager_cmd()", "ddos logger must discover firewall manager")
    assert_contains(ddos, "dynamic_firewall_ban()", "ddos logger must provide manager-aware ban helper")
    assert_contains(ddos, 'dynamic_firewall_ban "${ip}" "${applied_timeout}"', "ddos logger dynamic blocks must use firewall manager helper")
    assert_contains(ddos, 'dynamic_firewall_unban "${ip}"', "ddos logger unblock path must clear manager-backed bans")
    assert_contains(ddos, '"${manager}" ban "${ip}" "${timeout}"', "ddos logger helper must call firewall manager ban")

    assert_contains(watch, "FIREWALL_MANAGER", "security log watcher must know firewall manager path")
    assert_contains(watch, '[FIREWALL_MANAGER, "ban", ip, ttl]', "security log watcher bans must call firewall manager first")
    assert_contains(watch, "fallback_ipset_ban", "security log watcher must keep legacy ipset fallback")
    assert_contains(watch, 'FALLBACK_IPSET6 if ":" in ip else FALLBACK_IPSET4', "security log watcher fallback must preserve IPv6 parity")

    assert_contains(portal, "FIREWALL_MANAGER", "unblock portal must know firewall manager path")
    assert_contains(portal, 'firewall_action("ban", ip, timeout)', "unblock portal token bans must call firewall manager first")
    assert_contains(portal, 'firewall_action("unban", ip)', "unblock portal unban must call firewall manager")
    assert_contains(portal, "fallback_ipset_ban", "unblock portal must keep legacy ipset fallback")
    assert_contains(portal, "NFT_BAN_SETS", "unblock portal must list nftables-backed bans")
    assert_contains(portal, '["nft", "list", "set", "inet", NFT_TABLE, set_name]', "unblock portal must read nft sets")
    assert_contains(portal, "get_nft_set_members(set_name)", "blocked list must include nft set members")

    print("firewall manager caller tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

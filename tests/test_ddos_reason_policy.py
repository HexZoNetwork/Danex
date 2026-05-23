#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import re


ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/ddos_host_logger.sh"
CONFIG = ROOT / "config.example.json"
CHALLENGE_GUARD = ROOT / "src/challenge_guard.cpp"
NGINX_SNIPPET = ROOT / "host_overrides/nginx/snippets/pteroprotect_server.conf"


HIGH_CONFIDENCE = ["bad-token:*", "sqli-probe:*", "probe-scan:*", "proxy-swarm:*", "nginx-limiter:*", "fingerprint-flood:*"]
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
    config = CONFIG.read_text(encoding="utf-8")
    challenge_guard = CHALLENGE_GUARD.read_text(encoding="utf-8")
    nginx_snippet = NGINX_SNIPPET.read_text(encoding="utf-8")
    high = function_body(text, "is_high_confidence_block_reason")
    overload = function_body(text, "is_overload_block_reason")
    add_block = function_body(text, "add_ipset_block")

    for reason in HIGH_CONFIDENCE:
        assert_contains(high, reason, f"high-confidence reason missing: {reason}")
    for reason in OVERLOAD:
        assert_contains(overload, reason, f"overload reason missing: {reason}")

    assert_contains(add_block, 'is_private_ip "${ip}" || is_host_ip "${ip}"', "local/private/host IPs must always be protected")
    assert_contains(text, "is_known_cdn_proxy_ip()", "known CDN proxy guard missing")
    assert_contains(add_block, 'is_known_cdn_proxy_ip "${ip}"', "dynamic blackhole must not target CDN edge addresses")
    assert_contains(add_block, 'reason=cdn-proxy', "CDN edge skip must be logged")
    assert_contains(add_block, 'is_recently_authenticated_ip "${ip}" && ! is_high_confidence_block_reason "${reason}"', "recent auth must not bypass high-confidence reasons")
    assert_contains(add_block, 'is_ip_trust_protected_ip "${ip}" && ! is_high_confidence_block_reason "${reason}"', "IP trust must not bypass high-confidence reasons")
    assert_contains(add_block, 'is_overload_block_reason "${reason}"', "whitelist overload override must be explicit")
    assert_contains(add_block, 'is_high_confidence_block_reason "${reason}"', "whitelist high-confidence override must be explicit")
    assert_contains(add_block, 'skip-block ip=%s reason=whitelisted', "non-high-confidence whitelist skip must remain")
    assert_contains(text, 'PENDING_BLOCK_FILE="${RUNTIME_DIR}/pending_blocks.tsv"', "metric-only blocks must have pending confirmation state")
    assert_contains(text, "block_confirmation_required()", "block confirmation policy function missing")
    assert_contains(text, 'is_high_confidence_block_reason "${reason}" && return 1', "high-confidence reasons should not be delayed")
    assert_contains(add_block, 'confirm_dynamic_block_candidate "${ip}" "${reason}"', "metric-only blocks must be confirmed before ipset add")
    assert_contains(add_block, 'observe-block-candidate', "unconfirmed candidates must be logged")
    assert_contains(text, 'family="$(block_reason_family "${reason}")"', "block escalation must be keyed by reason family")
    assert_contains(text, 'update_block_history "${ip}" "${family}" "${now}" "${new_count}"', "block history must store reason family")
    assert_contains(add_block, 'next_block_timeout "${ip}" "${timeout}" "${reason}"', "block TTL escalation must receive reason context")
    assert_contains(text, "default_confidence_score()", "confidence scoring function missing")
    assert_contains(text, "estimate_impact_score()", "impact scoring function missing")
    assert_contains(text, "mitigation_stage_for_scores()", "staged mitigation function missing")
    assert_contains(add_block, 'record_mitigation_decision "${ip}" "${reason}" "${stage}" "${confidence}" "${impact}" "no-blackhole"', "non-block stages must be logged and avoid blackhole")
    assert_contains(text, 'HEAVY_HITTER_FILE="${RUNTIME_DIR}/heavy_hitters.tsv"', "heavy hitter state file missing")
    assert_contains(text, "update_heavy_hitter_state()", "bounded heavy hitter update missing")
    assert_contains(text, 'HEAVY_HITTER_MAX_KEYS="$(clamp_min_int "$(read_network_setting heavy_hitter_max_keys 2048)" 128)"', "heavy hitter max-key config missing")
    assert_contains(text, "IP_TRUST_RESTORE_MAX_RECORDS", "IP trust restore must be bounded")
    assert_contains(text, 'tail -n "${restore_limit}" "${IP_TRUST_STATE_FILE}"', "IP trust restore should not replay unbounded state")
    assert_contains(text, 'overload_fast_ban_factor_pct 130', "overload fast-ban default must not be below normal threshold")
    assert_contains(text, 'normal_profile_max_http_access_per_window 240', "normal HTTP threshold cap must not be too aggressive")
    assert_contains(text, 'fast_http_threshold < access_threshold', "HTTP fast-ban must not fire below the normal HTTP threshold")
    assert_contains(text, 'FINGERPRINT_BASELINE_FILE="${RUNTIME_DIR}/fingerprint_baseline.tsv"', "fingerprint baseline state file missing")
    assert_contains(text, 'ATTACK_RULE_FILE="${RUNTIME_DIR}/attack_rules.tsv"', "ephemeral attack rule state file missing")
    assert_contains(text, "extract_l7_fingerprint_stats()", "L7 fingerprint extraction missing")
    assert_contains(text, "update_fingerprint_baseline()", "fingerprint baseline update missing")
    assert_contains(text, "detect_l7_fingerprint_floods()", "fingerprint flood detector missing")
    assert_contains(text, "set_attack_rule()", "ephemeral attack rule setter missing")
    assert_contains(text, 'fingerprint_deviation_multiplier 4', "fingerprint deviation config missing")
    assert_contains(text, 'origin_error_confidence_min_pct 20', "origin-error confidence config missing")
    assert_contains(text, '^/__pteroprotect/challenge(/|$)|^/locales/|^/assets/', "HTTP access scoring must ignore challenge/static paths by default")
    assert_contains(text, 'status !~ /^[23][0-9][0-9]$/', "HTTP access scoring should only count accepted 2xx/3xx requests")
    assert_contains(config, '"host_http_ignore_path_regex"', "config should expose HTTP access ignore paths")
    assert_contains(config, '"runtime_attack_rules_enabled": true', "config should expose runtime attack rule challenge gate")
    assert_contains(config, '"overload_fast_ban_factor_pct": 130', "config should expose safer overload fast-ban factor")
    assert_contains(config, '"normal_profile_max_http_access_per_window": 240', "config should expose safer normal HTTP cap")
    assert_contains(challenge_guard, 'runtime_attack_rule_matches', "challenge guard must consume runtime attack rules")
    assert_contains(challenge_guard, 'restored.sid = sid', "valid signed clearance tokens must recover after session cache loss")
    assert_contains(challenge_guard, 'normalize_attack_rule_path', "challenge guard must normalize paths like ddos logger")
    assert_contains(challenge_guard, 'runtime_attack_rule_challenge', "challenge guard must audit runtime rule challenges")
    assert_contains(nginx_snippet, 'rd=$request_uri', "challenge redirect must preserve original request URI")
    assert_contains(nginx_snippet, 'X-Forwarded-Proto $scheme', "challenge endpoints must forward scheme for cookie security")
    assert_contains(nginx_snippet, 'X-PteroProtect-Original-URI $request_uri', "nginx auth subrequests must forward original URI")
    assert_contains(nginx_snippet, 'X-PteroProtect-Original-Method $request_method', "nginx auth subrequests must forward original method")

    print("ddos reason policy tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

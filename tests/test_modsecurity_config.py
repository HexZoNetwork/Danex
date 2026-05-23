#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import re


ROOT = pathlib.Path(__file__).resolve().parents[1]
MODSEC = ROOT / "host_overrides/nginx/modsec"


def assert_contains(text: str, needle: str, message: str) -> None:
    if needle not in text:
        raise AssertionError(message)


def assert_not_contains(text: str, needle: str, message: str) -> None:
    if needle in text:
        raise AssertionError(message)


def assert_regex(text: str, pattern: str, message: str) -> None:
    if not re.search(pattern, text, re.MULTILINE):
        raise AssertionError(message)


def main() -> int:
    main_conf = (MODSEC / "main.conf").read_text(encoding="utf-8")
    exclusions = (MODSEC / "local-exclusions.conf").read_text(encoding="utf-8")

    assert_contains(main_conf, "SecRuleEngine On", "ModSecurity must be enabled")
    assert_contains(main_conf, "SecAuditEngine RelevantOnly", "audit log should stay relevant-only")
    assert_contains(main_conf, "Include /etc/nginx/modsec/rules/*.conf", "CRS rules must be included")
    assert_contains(main_conf, "Include /etc/nginx/modsec/local-exclusions.conf", "local exclusions must be loaded after CRS")

    assert_regex(exclusions, r'id:100100,phase:1,pass,nolog,ctl:requestBodyProcessor=JSON,', "API exclusion must be pass/nolog and JSON-scoped")
    assert_contains(exclusions, 'REQUEST_URI "@rx ^/api/(client|application)(/|$)"', "API exclusion must only cover client/application APIs")
    assert_not_contains(exclusions, "^/api/remote", "remote machine API must not receive broad CRS exclusions")

    assert_contains(exclusions, '^/__pteroprotect/challenge/(solve|click|verify-math)$', "challenge exclusion must be limited to noisy challenge endpoints")
    assert_not_contains(exclusions, '^/__pteroprotect/challenge(/|$)', "challenge exclusion must not cover every challenge endpoint")

    assert_contains(exclusions, '^/admin/protect/terminal(/|$)', "terminal exclusion must be limited to protected terminal route")
    assert_not_contains(exclusions, '^/admin(/|$)', "terminal exclusion must not cover all admin routes")
    assert_contains(exclusions, 'id:100103,phase:1,pass,nolog,ctl:ruleRemoveById=949110', "profile exclusion must not use broad allow")
    assert_not_contains(exclusions, 'id:100103,phase:1,allow,nolog', "profile exclusion must not short-circuit later CRS checks")

    print("modsecurity config tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

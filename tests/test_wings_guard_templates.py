#!/usr/bin/env python3
from __future__ import annotations

import pathlib


ROOT = pathlib.Path(__file__).resolve().parents[1]


def assert_contains(text: str, needle: str, message: str) -> None:
    if needle not in text:
        raise AssertionError(message)


def assert_not_contains(text: str, needle: str, message: str) -> None:
    if needle in text:
        raise AssertionError(message)


def main() -> int:
    setup = (ROOT / "setup.sh").read_text(encoding="utf-8")
    remote = (ROOT / "panel_overrides/app/Services/Nodes/AutoConfigure/RemoteScriptBuilder.php").read_text(encoding="utf-8")

    assert_contains(setup, "access_log /var/log/nginx/pteroprotect.access.log combined;", "setup wings guard must log to fail2ban-monitored access log")
    assert_not_contains(setup, 'if (\$http_authorization ~* "^Bearer\\\\s+.+") { return 418; }', "setup wings guard must not bypass challenge for arbitrary bearer")

    assert_contains(remote, "access_log /var/log/nginx/pteroprotect.access.log combined;", "remote protected wings guard must log to fail2ban-monitored access log")
    assert_contains(remote, "auth_request /__pteroprotect/challenge/check_token;", "remote protected wings guard must use challenge token auth_request")
    assert_contains(remote, "error_page 401 403 = @drop_cto;", "remote protected wings guard must drop failed auth_request")
    assert_not_contains(remote, 'if (\\$http_authorization ~* "^Bearer', "remote protected wings guard must not bypass challenge for arbitrary bearer")

    print("wings_guard_template tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

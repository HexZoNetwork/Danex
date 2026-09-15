#!/usr/bin/env python3
"""Challenge signing must use only its dedicated, configured secret."""
from __future__ import annotations

import pathlib


ROOT = pathlib.Path(__file__).resolve().parents[1]


def main() -> int:
    for relative in ("src/challenge_guard.cpp", "panel_overrides/src/challenge_guard.cpp"):
        source = (ROOT / relative).read_text(encoding="utf-8")
        if "configured_secret_ok(s.secret)" not in source:
            raise AssertionError(f"{relative} does not enforce a minimum WAF secret length")
        if 'json_get_string(net, "unblock_portal_token", "")' in source:
            raise AssertionError(f"{relative} reuses the unblock token as a WAF signing key")
        if "g_ephemeral_secret" in source:
            raise AssertionError(f"{relative} falls back to a restart-dependent signing key")
        if "must be at least 24 characters" not in source:
            raise AssertionError(f"{relative} does not reject an invalid startup secret")
    print("challenge secret policy tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

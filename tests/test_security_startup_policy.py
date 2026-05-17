#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import pathlib
import tempfile


ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/security_startup_policy.py"


def load_policy():
    spec = importlib.util.spec_from_file_location("security_startup_policy", SCRIPT)
    if spec is None or spec.loader is None:
        raise RuntimeError("failed to load policy")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def write_config(path: pathlib.Path, values: dict) -> None:
    path.write_text(json.dumps(values), encoding="utf-8")


def main() -> int:
    policy = load_policy()
    with tempfile.TemporaryDirectory() as tmp:
        root = pathlib.Path(tmp)
        good = root / "good.json"
        write_config(good, {
            "database": {"password": "long-non-default-db-password"},
            "telegram": {"token": ""},
            "network": {
                "waf_challenge_secret": "a" * 24,
                "unblock_portal_token": "b" * 24,
                "rce_control_key": "c" * 24,
                "node_auth_key": "d" * 32,
            },
        })
        if policy.validate(good):
            raise AssertionError("good config should pass startup policy")

        bad = root / "bad.json"
        write_config(bad, {
            "database": {"password": "admin001"},
            "telegram": {"token": "not-a-token"},
            "network": {
                "waf_challenge_secret": "replace_me",
                "unblock_portal_token": "short",
                "rce_control_key": "ekjo",
                "node_auth_key": "dummy",
            },
        })
        findings = policy.validate(bad)
        if len(findings) < 6:
            raise AssertionError(f"bad config should produce policy findings, got {findings!r}")

    print("security startup policy tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

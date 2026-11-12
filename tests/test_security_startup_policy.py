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
            "resilience": {"replay": {"hmac_secret": "rpl-8Kd2sN5vB7yF1jC9hM4pQ7rT3xZ6"}},
            "network": {
                "waf_challenge_secret": "waf-7Hq2mZ9pL4xR8tV1cN6wB3yF5",
                "unblock_portal_token": "unb-3Kd8sN5vB2yF6jC9hM4pQ7rT1",
                "emergency_control_token": "emg-9Pr4tW7kM2qX5nD8sJ3vG6bL1",
                "rce_control_key": "rce-6Ls1hG8bQ3zE7uA4wR2tY9mK5",
                "node_auth_key": "nod-2Vf5jR9cT4mY8pK1xH6sD3wQ7zB4nL9",
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
        if len(findings) < 7:
            raise AssertionError(f"bad config should produce policy findings, got {findings!r}")

        generated = root / "generated.json"
        write_config(generated, {"database": {"password": "long-non-default-db-password"}, "network": {}})
        changed = policy.generate_missing(generated)
        if "resilience.replay.hmac_secret" not in changed:
            raise AssertionError(f"generate_missing should fill nested replay secret, got {changed!r}")
        if policy.validate(generated):
            raise AssertionError("generated config should satisfy secret policy")

    print("security startup policy tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

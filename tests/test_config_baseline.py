#!/usr/bin/env python3
"""The small operator config must inherit the full advanced default baseline."""
from __future__ import annotations

import json
import pathlib


ROOT = pathlib.Path(__file__).resolve().parents[1]


def merge_defaults(target: dict, defaults: dict) -> None:
    for key, value in defaults.items():
        if key not in target:
            target[key] = value
        elif isinstance(target[key], dict) and isinstance(value, dict):
            merge_defaults(target[key], value)


def main() -> int:
    compact = json.loads((ROOT / "config.example.json").read_text(encoding="utf-8"))
    advanced = json.loads((ROOT / "config.advanced.example.json").read_text(encoding="utf-8"))
    merge_defaults(compact, advanced)

    checks = {
        ("network", "rate_limit_auth_rate"): "5r/s",
        ("network", "runtime_attack_rules_enabled"): True,
        ("monitor", "external_check_min_interval_sec"): 60,
        ("abuse_guard", "docker_memory_clamp_enabled"): True,
        ("resilience", "enabled"): True,
    }
    for (section, key), expected in checks.items():
        actual = compact.get(section, {}).get(key)
        if actual != expected:
            raise AssertionError(f"advanced default missing for {section}.{key}: {actual!r}")

    if compact["monitor"]["checkhost_enabled"] is not False:
        raise AssertionError("operator config must override the advanced baseline")

    setup = (ROOT / "setup.sh").read_text(encoding="utf-8")
    if '"${PROJECT_DIR}/config.advanced.example.json"' not in setup:
        raise AssertionError("setup must merge the advanced baseline into runtime config")

    print("config baseline tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

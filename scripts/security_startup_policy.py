#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
import re
import secrets
import string
import sys
from pathlib import Path


PLACEHOLDERS = {"", "replace_me", "changeme", "change_me", "default", "dummy", "fake", "test", "password", "secret", "token"}
REQUIRED_SECRET_PATHS = {
    "network.waf_challenge_secret": 24,
    "network.unblock_portal_token": 24,
    "network.emergency_control_token": 24,
    "network.rce_control_key": 24,
    "network.node_auth_key": 32,
    "resilience.replay.hmac_secret": 32,
}
WEAK_PASSWORDS = {"admin", "admin001", "password", "password123", "pterodactyl", "root", "toor"}
UNIQUE_SECRET_PATHS = [
    "network.waf_challenge_secret",
    "network.unblock_portal_token",
    "network.rce_control_key",
    "network.emergency_control_token",
    "network.node_auth_key",
    "resilience.replay.hmac_secret",
]


def json_get(data: object, dotted: str) -> object:
    cur = data
    for part in dotted.split("."):
        if not isinstance(cur, dict) or part not in cur:
            return None
        cur = cur[part]
    return cur


def json_set(data: dict, dotted: str, value: object) -> None:
    parts = dotted.split(".")
    cur = data
    for part in parts[:-1]:
        nxt = cur.get(part)
        if not isinstance(nxt, dict):
            nxt = {}
            cur[part] = nxt
        cur = nxt
    cur[parts[-1]] = value


def weak_secret(value: object, min_len: int) -> bool:
    text = str(value or "").strip().strip('"\'')
    lowered = text.lower()
    if lowered in PLACEHOLDERS or lowered.startswith("replace_me") or lowered.startswith("change_me"):
        return True
    if len(text) < min_len:
        return True
    if len(set(text)) < 6:
        return True
    return False


def _random_secret(prefix: str, length: int = 40) -> str:
    alphabet = string.ascii_letters + string.digits
    return prefix + ''.join(secrets.choice(alphabet) for _ in range(max(24, length)))


def generate_missing(path: Path) -> list[str]:
    data = json.loads(path.read_text(encoding='utf-8'))
    if not isinstance(data, dict):
        raise ValueError('config root must be an object')

    prefixes = {
        'network.waf_challenge_secret': 'waf',
        'network.unblock_portal_token': 'unb',
        'network.emergency_control_token': 'emg',
        'network.rce_control_key': 'rce',
        'network.node_auth_key': 'nod',
        'resilience.replay.hmac_secret': 'rpl',
    }
    seen = set()
    changed = []
    for dotted, min_len in REQUIRED_SECRET_PATHS.items():
        raw = str(json_get(data, dotted) or '').strip()
        if weak_secret(raw, min_len) or raw in seen:
            value = _random_secret(prefixes.get(dotted, 'pp'))
            while value in seen:
                value = _random_secret(prefixes.get(dotted, 'pp'))
            json_set(data, dotted, value)
            seen.add(value)
            changed.append(dotted)
        else:
            seen.add(raw)

    if changed:
        path.write_text(json.dumps(data, indent=2, sort_keys=True) + chr(10), encoding='utf-8')
        try:
            os.chmod(path, 0o640)
        except OSError:
            pass
    return changed


def validate(path: Path) -> list[str]:
    findings: list[str] = []
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        return [f"{path}: invalid JSON: {exc}"]

    for dotted, min_len in REQUIRED_SECRET_PATHS.items():
        value = json_get(data, dotted)
        if weak_secret(value, min_len):
            findings.append(f"{dotted} is missing, placeholder, or shorter than {min_len} chars")

    seen: dict[str, list[str]] = {}
    for dotted in UNIQUE_SECRET_PATHS:
        value = str(json_get(data, dotted) or "").strip()
        if value:
            seen.setdefault(value, []).append(dotted)
    for paths in seen.values():
        if len(paths) > 1:
            findings.append(f"control secret is reused across {', '.join(paths)}")

    db_password = str(json_get(data, "database.password") or "").strip()
    if not db_password:
        findings.append("database.password is empty")
    elif db_password.lower() in WEAK_PASSWORDS or len(db_password) < 16 or len(set(db_password)) < 6:
        findings.append("database.password is missing, short, repetitive, or a known weak/default value")
    telegram_token = str(json_get(data, "telegram.token") or "").strip()
    if telegram_token and not re.fullmatch(r"\d{8,12}:[A-Za-z0-9_-]{30,80}", telegram_token):
        findings.append("telegram.token is non-empty but does not match Telegram bot token format")
    return findings


def main() -> int:
    parser = argparse.ArgumentParser(description="PteroProtect live startup secret/default policy check.")
    parser.add_argument("config", nargs="?", default=os.environ.get("PTEROPROTECT_CONFIG_PATH", "/pteroprotect/config.json"))
    parser.add_argument("--enforce", action="store_true", help="Exit non-zero when findings are present.")
    parser.add_argument("--generate-missing", action="store_true", help="Generate strong values for weak or missing control secrets.")
    args = parser.parse_args()

    if args.generate_missing:
        try:
            changed = generate_missing(Path(args.config))
        except Exception as exc:
            print(f"security_startup_policy: secret generation failed: {exc}", file=sys.stderr)
            return 1
        for item in changed:
            print(f"security_startup_policy: generated strong value for {item}")

    findings = validate(Path(args.config))
    if not findings:
        print("security_startup_policy: ok")
        return 0
    for finding in findings:
        print(f"security_startup_policy: warning: {finding}", file=sys.stderr)
    enforce = args.enforce or os.environ.get("PTEROPROTECT_ENFORCE_STARTUP_SECRET_POLICY", "0") == "1"
    return 1 if enforce else 0


if __name__ == "__main__":
    raise SystemExit(main())

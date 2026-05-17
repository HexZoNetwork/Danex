#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import math
import os
import re
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable


REPO_ROOT = Path(__file__).resolve().parents[1]

SKIP_DIRS = {
    ".git",
    ".codespaces",
    "backups",
    "obj",
    "tmp",
    "node_modules",
    "__pycache__",
}

SKIP_PATH_PARTS = {
    "panel_overrides/public/assets",
    "tests/security/malicious",
    "tests/security/expected",
}

SKIP_SUFFIXES = {
    ".pyc",
    ".pyo",
    ".png",
    ".jpg",
    ".jpeg",
    ".gif",
    ".ico",
    ".woff",
    ".woff2",
    ".ttf",
    ".map",
}

LIVE_CONFIGS = {
    "config.json",
    "config.runtime.json",
}

PLACEHOLDER_WORDS = {
    "",
    "example",
    "example.com",
    "replace_me",
    "changeme",
    "change_me",
    "dummy",
    "fake",
    "test",
    "redacted",
    "placeholder",
    "panel_user",
    "panel_db",
    "127.0.0.1",
    "localhost",
}

SENSITIVE_KEY_RE = re.compile(
    r"(?:password|passwd|pwd|token|secret|api[_-]?key|app[_-]?key|auth[_-]?key|private[_-]?key|hmac|bearer|rce[_-]?control)",
    re.IGNORECASE,
)

RULES = [
    ("PRIVATE_KEY", re.compile(r"-----BEGIN (?:RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----")),
    ("TELEGRAM_TOKEN", re.compile(r"\b\d{8,12}:[A-Za-z0-9_-]{30,80}\b")),
    ("PTERODACTYL_TOKEN", re.compile(r"\bptl[ac]_[A-Za-z0-9]{30,}\b")),
    ("GITHUB_TOKEN", re.compile(r"\bgh[pousr]_[A-Za-z0-9_]{30,}\b")),
    ("AWS_ACCESS_KEY", re.compile(r"\b(?:AKIA|ASIA)[A-Z0-9]{16}\b")),
    ("SLACK_TOKEN", re.compile(r"\bxox[baprs]-[A-Za-z0-9-]{20,}\b")),
]

ASSIGNMENT_RE = re.compile(
    r"(?P<key>[A-Za-z0-9_.-]*(?:password|passwd|pwd|token|secret|api[_-]?key|app[_-]?key|auth[_-]?key|private[_-]?key|hmac|bearer|rce[_-]?control)[A-Za-z0-9_.-]*)"
    r"\s*[:=]\s*['\"]?(?P<value>[^'\"\s,#]+)",
    re.IGNORECASE,
)


@dataclass(frozen=True)
class Finding:
    path: Path
    line: int
    rule_id: str
    message: str
    snippet: str


def rel(path: Path) -> str:
    try:
        return str(path.absolute().relative_to(REPO_ROOT))
    except ValueError:
        return str(path)


def should_skip(path: Path, include_live_config: bool) -> bool:
    relative = rel(path)
    if any(part in SKIP_DIRS for part in path.parts):
        return True
    if any(relative.startswith(prefix + os.sep) or relative == prefix for prefix in SKIP_PATH_PARTS):
        return True
    if path.suffix.lower() in SKIP_SUFFIXES:
        return True
    if path.name in {"challenge_guard", "dann_guard"}:
        return True
    if path.is_symlink() and not include_live_config:
        return True
    if not include_live_config and (relative in LIVE_CONFIGS or path.name in LIVE_CONFIGS):
        return True
    return False


def is_binary(path: Path) -> bool:
    try:
        chunk = path.read_bytes()[:4096]
    except OSError:
        return True
    return b"\0" in chunk


def iter_files(roots: Iterable[Path], include_live_config: bool) -> Iterable[Path]:
    for root in roots:
        root = root.resolve()
        if root.is_file():
            if not should_skip(root, include_live_config) and not is_binary(root):
                yield root
            continue
        for dirpath, dirnames, filenames in os.walk(root):
            current = Path(dirpath)
            dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
            for name in filenames:
                path = current / name
                if should_skip(path, include_live_config) or is_binary(path):
                    continue
                yield path


def redact(value: str) -> str:
    value = value.strip()
    if len(value) <= 8:
        return "<redacted>"
    return f"{value[:4]}...{value[-4:]}"


def normalize_placeholder(value: str) -> str:
    value = value.strip().strip("'\"").lower()
    value = re.sub(r"[_-]?(token|secret|key|password|hmac)$", "", value)
    return value


def is_placeholder(value: str) -> bool:
    lowered = value.strip().strip("'\"").lower()
    if lowered in PLACEHOLDER_WORDS:
        return True
    if normalize_placeholder(lowered) in PLACEHOLDER_WORDS:
        return True
    return lowered.startswith("replace_me") or lowered.startswith("your_") or "example" in lowered


def entropy(value: str) -> float:
    if not value:
        return 0.0
    counts = {c: value.count(c) for c in set(value)}
    return -sum((n / len(value)) * math.log2(n / len(value)) for n in counts.values())


def is_weak_sensitive_value(value: str, key: str) -> bool:
    clean = value.strip().strip("'\"")
    if is_placeholder(clean):
        return False
    lowered = clean.lower()
    if lowered in {"password", "secret", "token", "admin", "admin001", "default"}:
        return True
    min_len = 12 if "password" in key.lower() else 24
    return len(clean) < min_len


def should_check_weak_assignments(path: Path) -> bool:
    name = path.name.lower()
    suffix = path.suffix.lower()
    if suffix in {".env", ".local", ".json", ".yml", ".yaml"}:
        return True
    if name in {"config", "config.example", "config.local"} or name.endswith(".env.example"):
        return True
    return False


def scan_text_file(path: Path) -> list[Finding]:
    findings: list[Finding] = []
    try:
        lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
    except OSError as exc:
        return [Finding(path, 0, "READ_ERROR", f"could not read file: {exc}", "")]

    for line_no, line in enumerate(lines, start=1):
        if "security-scan: allow" in line:
            continue
        trimmed = line[:500]
        for rule_id, regex in RULES:
            match = regex.search(trimmed)
            if match:
                findings.append(Finding(path, line_no, rule_id, "high-confidence secret pattern", trimmed.replace(match.group(0), redact(match.group(0)))))
        if should_check_weak_assignments(path):
            for match in ASSIGNMENT_RE.finditer(trimmed):
                key = match.group("key")
                value = match.group("value")
                if is_weak_sensitive_value(value, key):
                    findings.append(Finding(path, line_no, "WEAK_SECRET_VALUE", f"weak sensitive value assigned to {key}", trimmed.replace(value, redact(value))))
                elif len(value) >= 32 and entropy(value) >= 4.0 and SENSITIVE_KEY_RE.search(key) and not is_placeholder(value):
                    findings.append(Finding(path, line_no, "HIGH_ENTROPY_SECRET", f"high-entropy value assigned to {key}", trimmed.replace(value, redact(value))))
    return findings


def walk_json_values(obj: object, prefix: str = "") -> Iterable[tuple[str, object]]:
    if isinstance(obj, dict):
        for key, value in obj.items():
            path = f"{prefix}.{key}" if prefix else str(key)
            yield from walk_json_values(value, path)
    elif isinstance(obj, list):
        for idx, value in enumerate(obj):
            yield from walk_json_values(value, f"{prefix}[{idx}]")
    else:
        yield prefix, obj


def scan_json_file(path: Path) -> list[Finding]:
    findings: list[Finding] = []
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        return [Finding(path, 0, "JSON_PARSE_ERROR", f"invalid JSON: {exc}", "")]

    sensitive_values: dict[str, list[str]] = {}
    is_example = rel(path) == "config.example.json" or "tests/security/benign" in rel(path)

    for json_path, value in walk_json_values(data):
        if not isinstance(value, str):
            continue
        if json_path.endswith("provider_token_provider_keywords"):
            continue
        if not SENSITIVE_KEY_RE.search(json_path):
            continue
        sensitive_values.setdefault(value, []).append(json_path)
        if is_example and is_placeholder(value):
            continue
        for rule_id, regex in RULES:
            if regex.search(value):
                findings.append(Finding(path, 0, rule_id, f"secret pattern at {json_path}", f"{json_path}: {redact(value)}"))
        if is_weak_sensitive_value(value, json_path):
            findings.append(Finding(path, 0, "WEAK_SECRET_VALUE", f"weak sensitive value at {json_path}", f"{json_path}: {redact(value)}"))
        elif len(value) >= 32 and entropy(value) >= 4.0 and not is_placeholder(value):
            findings.append(Finding(path, 0, "HIGH_ENTROPY_SECRET", f"high-entropy sensitive value at {json_path}", f"{json_path}: {redact(value)}"))

    for value, paths in sensitive_values.items():
        if len(paths) > 1 and value and not is_placeholder(value):
            findings.append(Finding(path, 0, "REUSED_SECRET", f"same sensitive value reused at {', '.join(paths)}", redact(value)))
    return findings


def scan_path(path: Path) -> list[Finding]:
    if path.suffix.lower() == ".json":
        return scan_json_file(path)
    return scan_text_file(path)


def print_findings(findings: list[Finding]) -> None:
    for finding in findings:
        location = f"{rel(finding.path)}:{finding.line}" if finding.line else rel(finding.path)
        print(f"{location} {finding.rule_id} {finding.message} :: {finding.snippet}")


def run_scan(args: argparse.Namespace) -> int:
    roots = [Path(p) for p in args.paths]
    findings: list[Finding] = []
    for path in iter_files(roots, args.include_live_config):
        findings.extend(scan_path(path))
    print_findings(findings)
    print(f"security_secret_scan: files_scanned={len(list(iter_files(roots, args.include_live_config)))} findings={len(findings)}")
    return 1 if findings else 0


def run_self_test() -> int:
    benign_roots = [REPO_ROOT / "tests/security/benign", REPO_ROOT / "tests/security/edge"]
    malicious_roots = [REPO_ROOT / "tests/security/malicious"]
    benign_findings: list[Finding] = []
    for path in iter_files(benign_roots, include_live_config=False):
        benign_findings.extend(scan_path(path))
    malicious_findings: list[Finding] = []
    for root in malicious_roots:
        for path in root.rglob("*"):
            if path.is_file() and not is_binary(path):
                malicious_findings.extend(scan_path(path))

    if benign_findings:
        print("self-test failed: benign fixtures produced findings")
        print_findings(benign_findings)
        return 1
    if not malicious_findings:
        print("self-test failed: malicious fixtures produced no findings")
        return 1
    found_rules = {f.rule_id for f in malicious_findings}
    required = {"TELEGRAM_TOKEN", "PTERODACTYL_TOKEN", "PRIVATE_KEY", "WEAK_SECRET_VALUE"}
    missing = required - found_rules
    if missing:
        print(f"self-test failed: missing expected rules {', '.join(sorted(missing))}")
        print_findings(malicious_findings)
        return 1
    print(f"security_secret_scan self-test ok: malicious_findings={len(malicious_findings)}")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Scan for committed secrets and weak default sensitive values.")
    parser.add_argument("paths", nargs="*", default=[str(REPO_ROOT)], help="Paths to scan. Defaults to repo root.")
    parser.add_argument("--include-live-config", action="store_true", help="Also scan config.json and config.runtime.json.")
    parser.add_argument("--self-test", action="store_true", help="Run scanner corpus self-test.")
    args = parser.parse_args()
    if args.self_test:
        return run_self_test()
    return run_scan(args)


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Configuration samples must remain safe to publish, including dev mocks."""
from __future__ import annotations

import json
import pathlib
import re


ROOT = pathlib.Path(__file__).resolve().parents[1]
TOKEN_PATTERNS = (
    re.compile(r"\b\d{8,12}:[A-Za-z0-9_-]{30,80}\b"),  # Telegram bot token
    re.compile(r"\bptl[ac]_[A-Za-z0-9]{30,}\b"),       # Pterodactyl API token
    re.compile(r"-----BEGIN (?:RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----"),
)


def main() -> int:
    examples = sorted(ROOT.rglob("config*.example.json"))
    if not examples:
        raise AssertionError("no config examples found")
    for path in examples:
        text = path.read_text(encoding="utf-8")
        json.loads(text)
        for pattern in TOKEN_PATTERNS:
            if pattern.search(text):
                raise AssertionError(f"credential-shaped value found in sample config: {path.relative_to(ROOT)}")
    print("config example secret tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

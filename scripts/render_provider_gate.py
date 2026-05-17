#!/usr/bin/env python3
from __future__ import annotations

import ipaddress
import pathlib
import sys


def as_bool(value: str) -> bool:
    return str(value).strip().lower() in {"1", "true", "yes", "on"}


def parse_csv(value: str) -> list[str]:
    return [item.strip() for item in str(value).split(",") if item.strip()]


def normalize_cidrs(raw_list: list[str], version: int | None = None) -> list[str]:
    normalized: list[str] = []
    seen: set[str] = set()
    for item in raw_list:
        try:
            net = ipaddress.ip_network(item, strict=False)
        except Exception:
            continue
        if version is not None and net.version != version:
            continue
        text = net.with_prefixlen
        if text not in seen:
            seen.add(text)
            normalized.append(text)
    return normalized


def cidrs_from_cache(cache_file: str) -> list[str]:
    path = pathlib.Path(str(cache_file or "").strip())
    if not path.exists() or not path.is_file():
        return []
    raw: list[str] = []
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        item = line.split("#", 1)[0].strip()
        if item:
            raw.append(item)
    return normalize_cidrs(raw)


def render(path: pathlib.Path, enabled: bool, v4_raw: str, v6_raw: str, cache_file: str) -> None:
    cidrs = normalize_cidrs(parse_csv(v4_raw), 4)
    cidrs.extend(normalize_cidrs(parse_csv(v6_raw), 6))
    cidrs.extend(cidrs_from_cache(cache_file))
    cidrs = normalize_cidrs(cidrs)

    lines = [
        "# managed by pteroprotect setup.sh",
        "# Provider-range token gate:",
        "# if client IP is in listed provider CIDR and request has no token -> block.",
        "",
        "map $http_authorization $pteroprotect_req_has_bearer {",
        "    default 0;",
        "    ~*^Bearer\\s+(ptla_|ptlc_)[A-Za-z0-9._-]+$ 1;",
        "}",
        "",
        "map $pteroprotect_req_has_bearer $pteroprotect_req_has_any_token {",
        "    default 0;",
        "    1 1;",
        "}",
        "",
        "geo $pteroprotect_provider_token_range {",
        "    default 0;",
    ]

    if enabled:
        for cidr in cidrs:
            lines.append(f"    {cidr} 1;")

    lines += [
        "}",
        "",
        "map \"$pteroprotect_provider_token_range:$pteroprotect_req_has_any_token\" $pteroprotect_provider_token_block {",
        "    default 0;",
        "    \"1:0\" 1;",
        "}",
    ]

    if not enabled:
        lines.append("")
        lines.append("# disabled (set network.provider_token_gate_enabled=true and add CIDRs)")

    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    if len(sys.argv) != 6:
        print("usage: render_provider_gate.py OUT ENABLED IPV4_CIDRS IPV6_CIDRS CACHE_FILE", file=sys.stderr)
        return 2
    render(pathlib.Path(sys.argv[1]), as_bool(sys.argv[2]), sys.argv[3], sys.argv[4], sys.argv[5])
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

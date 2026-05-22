#!/usr/bin/env python3
from __future__ import annotations

import ipaddress
import json
import pathlib
import sys
import urllib.parse
import urllib.request


DEFAULT_CDN_BYPASS_CIDRS = [
    # Cloudflare: https://www.cloudflare.com/ips/
    "173.245.48.0/20",
    "103.21.244.0/22",
    "103.22.200.0/22",
    "103.31.4.0/22",
    "141.101.64.0/18",
    "108.162.192.0/18",
    "190.93.240.0/20",
    "188.114.96.0/20",
    "197.234.240.0/22",
    "198.41.128.0/17",
    "162.158.0.0/15",
    "104.16.0.0/13",
    "104.24.0.0/14",
    "172.64.0.0/13",
    "131.0.72.0/22",
    "2400:cb00::/32",
    "2606:4700::/32",
    "2803:f800::/32",
    "2405:b500::/32",
    "2405:8100::/32",
    "2a06:98c0::/29",
    "2c0f:f248::/32",
    # Fastly commonly used CDN edge ranges.
    "23.235.32.0/20",
    "43.249.72.0/22",
    "103.244.50.0/24",
    "103.245.222.0/23",
    "103.245.224.0/24",
    "104.156.80.0/20",
    "140.248.64.0/18",
    "140.248.128.0/17",
    "146.75.0.0/17",
    "151.101.0.0/16",
    "157.52.64.0/18",
    "167.82.0.0/17",
    "167.82.128.0/20",
    "167.82.160.0/20",
    "167.82.224.0/20",
    "172.111.64.0/18",
    "185.31.16.0/22",
    "199.27.72.0/21",
    "199.232.0.0/16",
    "2a04:4e40::/32",
    "2a04:4e42::/32",
]

CDN_BYPASS_URLS = [
    "https://www.cloudflare.com/ips-v4",
    "https://www.cloudflare.com/ips-v6",
    "https://api.fastly.com/public-ip-list",
]

CDN_BYPASS_ASNS = [
    "AS13335",  # Cloudflare
    "AS20940",  # Akamai
    "AS16625",  # Akamai
    "AS54113",  # Fastly
    "AS60068",  # CDN77
    "AS200325",  # bunny.net
]


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


def fetch_json(url: str) -> object:
    with urllib.request.urlopen(url, timeout=8) as response:
        return json.load(response)


def fetch_text(url: str) -> str:
    with urllib.request.urlopen(url, timeout=8) as response:
        return response.read().decode("utf-8", errors="replace")


def fetch_cdn_url_cidrs() -> list[str]:
    cidrs: list[str] = []
    for url in CDN_BYPASS_URLS:
        try:
            if url.endswith("/public-ip-list"):
                payload = fetch_json(url)
                if isinstance(payload, dict):
                    for key in ("addresses", "ipv6_addresses"):
                        value = payload.get(key, [])
                        if isinstance(value, list):
                            cidrs.extend(str(item).strip() for item in value if str(item).strip())
            else:
                for line in fetch_text(url).splitlines():
                    item = line.split("#", 1)[0].strip()
                    if item:
                        cidrs.append(item)
        except Exception:
            continue
    return normalize_cidrs(cidrs)


def fetch_asn_prefixes(asn: str) -> list[str]:
    query = urllib.parse.urlencode({"resource": asn})
    url = f"https://stat.ripe.net/data/announced-prefixes/data.json?{query}"
    try:
        payload = fetch_json(url)
    except Exception:
        return []
    if not isinstance(payload, dict) or payload.get("status") != "ok":
        return []
    prefixes = []
    data = payload.get("data", {})
    if isinstance(data, dict):
        for item in data.get("prefixes", []):
            if isinstance(item, dict):
                prefix = str(item.get("prefix", "")).strip()
                if prefix:
                    prefixes.append(prefix)
    return normalize_cidrs(prefixes)


def fetch_cdn_asn_cidrs() -> list[str]:
    cidrs: list[str] = []
    for asn in CDN_BYPASS_ASNS:
        cidrs.extend(fetch_asn_prefixes(asn))
    return normalize_cidrs(cidrs)


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
    cdn_cidrs = normalize_cidrs(DEFAULT_CDN_BYPASS_CIDRS)
    cdn_cidrs.extend(fetch_cdn_url_cidrs())
    cdn_cidrs.extend(fetch_cdn_asn_cidrs())
    cdn_cidrs = normalize_cidrs(cdn_cidrs)

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
        "geo $remote_addr $pteroprotect_cdn_proxy_remote_range {",
        "    default 0;",
    ]
    for cidr in cdn_cidrs:
        lines.append(f"    {cidr} 1;")

    lines += [
        "}",
        "",
        "geo $realip_remote_addr $pteroprotect_cdn_proxy_realip_range {",
        "    default 0;",
    ]
    for cidr in cdn_cidrs:
        lines.append(f"    {cidr} 1;")

    lines += [
        "}",
        "",
        "map \"$pteroprotect_cdn_proxy_remote_range:$pteroprotect_cdn_proxy_realip_range\" $pteroprotect_cdn_proxy_range {",
        "    default 0;",
        "    ~^1: 1;",
        "    ~:1$ 1;",
        "}",
        "",
        "map \"$pteroprotect_provider_token_range:$pteroprotect_req_has_any_token:$pteroprotect_cdn_proxy_range\" $pteroprotect_provider_token_block {",
        "    default 0;",
        "    \"1:0:0\" 1;",
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

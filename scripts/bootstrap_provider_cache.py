
def is_valid_ip(ip):
    try:
        socket.inet_pton(socket.AF_INET, ip)
        return True
    except socket.error:
        try:
            socket.inet_pton(socket.AF_INET6, ip)
            return True
        except socket.error:
            return False

import socket
#!/usr/bin/env python3
import json
import pathlib
import sys
import urllib.parse
import urllib.request


CDN_ASN_DENYLIST = {
    "AS13335",  # Cloudflare
    "AS20940",  # Akamai
    "AS16625",  # Akamai
    "AS54113",  # Fastly
    "AS60068",  # CDN77
    "AS200325",  # bunny.net
}


def as_bool(value):
    return str(value).strip().lower() in {"1", "true", "yes", "on"}


def parse_list(value):
    if isinstance(value, list):
        out = []
        for item in value:
            out.extend(parse_list(item))
        return out
    if value is None:
        return []
    text = str(value).replace("\n", ",")
    out = []
    for part in text.split(","):
        part = part.strip()
        if part:
            out.append(part)
    return out


def normalize_asn(value):
    text = str(value).strip().upper()
    if not text:
        return ""
    if not text.startswith("AS"):
        text = "AS" + text
    suffix = text[2:]
    if not suffix.isdigit():
        return ""
    return "AS" + suffix


def fetch_announced_prefixes(asn):
    query = urllib.parse.urlencode({"resource": asn})
    url = f"https://stat.ripe.net/data/announced-prefixes/data.json?{query}"
    with urllib.request.urlopen(url, timeout=30) as resp:
        payload = json.load(resp)
    if payload.get("status") != "ok":
        return []
    prefixes = []
    for item in payload.get("data", {}).get("prefixes", []):
        prefix = str(item.get("prefix", "")).strip()
        if prefix:
            prefixes.append(prefix)
    return prefixes


def resolve_ip_to_asns_and_prefix(ip):
    query = urllib.parse.urlencode({"resource": ip})
    url = f"https://stat.ripe.net/data/prefix-overview/data.json?{query}"
    with urllib.request.urlopen(url, timeout=30) as resp:
        payload = json.load(resp)
    if payload.get("status") != "ok":
        return [], ""
    data = payload.get("data", {})
    asns = []
    for item in data.get("asns", []):
        asn = normalize_asn(item.get("asn", ""))
        if asn:
            asns.append(asn)
    prefix = str(data.get("resource", "")).strip()
    return asns, prefix


def main():
    cfg_path = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else "/pteroprotect/config.json")
    force = any(arg == "--force" for arg in sys.argv[2:])
    if not cfg_path.exists():
        print("config-missing")
        return 0

    cfg = json.loads(cfg_path.read_text())
    net = cfg.get("network", {})
    enabled = as_bool(net.get("provider_token_bootstrap_enabled", False))
    if not enabled and not force:
        print("bootstrap-disabled")
        return 0

    cache_file = pathlib.Path(
        str(net.get("provider_token_cache_file", "/pteroprotect/cache/provider_ranges.txt")).strip()
        or "/pteroprotect/cache/provider_ranges.txt"
    )
    on_empty_only = as_bool(net.get("provider_token_bootstrap_on_empty_cache", True))
    if cache_file.exists() and cache_file.stat().st_size > 0 and on_empty_only and not force:
        print("cache-present")
        return 0

    cidrs = set(parse_list(net.get("provider_token_ipv4_cidrs", "")))
    cidrs.update(parse_list(net.get("provider_token_ipv6_cidrs", "")))

    asns = []
    seen_asns = set()
    for raw in parse_list(net.get("provider_token_bootstrap_asns", "")):
        asn = normalize_asn(raw)
        if asn in CDN_ASN_DENYLIST:
            continue
        if asn and asn not in seen_asns:
            seen_asns.add(asn)
            asns.append(asn)

    seed_ips = []
    for raw in parse_list(net.get("provider_token_bootstrap_ips", "")):
        ip = str(raw).strip()
        if ip:
            seed_ips.append(ip)

    for ip in seed_ips:
        resolved_asns, direct_prefix = resolve_ip_to_asns_and_prefix(ip)
        if direct_prefix:
            cidrs.add(direct_prefix)
        for asn in resolved_asns:
            if asn in CDN_ASN_DENYLIST:
                continue
            if asn not in seen_asns:
                seen_asns.add(asn)
                asns.append(asn)

    for asn in asns:
        for prefix in fetch_announced_prefixes(asn):
            cidrs.add(prefix)

    cache_file.parent.mkdir(parents=True, exist_ok=True)
    lines = [
        "# managed by pteroprotect bootstrap_provider_cache.py",
        "# source: config inline CIDRs + RIPEstat announced-prefixes ASN bootstrap",
    ]
    if asns:
        lines.append("# asns: " + ", ".join(asns))
    if seed_ips:
        lines.append("# seed_ips: " + ", ".join(seed_ips))
    for cidr in sorted(cidrs):
        lines.append(cidr)
    cache_file.write_text("\n".join(lines) + "\n")
    print(f"wrote:{cache_file}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

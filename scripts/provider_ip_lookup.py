
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
import time
import urllib.parse
import urllib.request


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


def load_json(path):
    try:
        return json.loads(path.read_text())
    except Exception:
        return {}


def fetch_prefix_overview(ip):
    query = urllib.parse.urlencode({"resource": ip})
    url = f"https://stat.ripe.net/data/prefix-overview/data.json?{query}"
    with urllib.request.urlopen(url, timeout=15) as resp:
        payload = json.load(resp)
    if payload.get("status") != "ok":
        return "", ""
    data = payload.get("data", {})
    prefix = str(data.get("resource", "")).strip()
    holder = ""
    for item in data.get("asns", []):
        holder = str(item.get("holder", "")).strip()
        if holder:
            break
    return prefix, holder


def append_prefix_once(path, prefix):
    path.parent.mkdir(parents=True, exist_ok=True)
    existing = set()
    if path.exists():
        for line in path.read_text().splitlines():
            cleaned = line.split("#", 1)[0].strip()
            if cleaned:
                existing.add(cleaned)
    if prefix and prefix not in existing:
        with path.open("a") as fh:
            fh.write(prefix + "\n")


def main():
    if len(sys.argv) < 3:
        print("other")
        return 1

    cfg_path = pathlib.Path(sys.argv[1])
    ip = str(sys.argv[2]).strip()
    cfg = load_json(cfg_path)
    net = cfg.get("network", {})

    cache_file = pathlib.Path(
        str(net.get("provider_token_ip_cache_file", "/pteroprotect/cache/provider_ip_cache.json")).strip()
        or "/pteroprotect/cache/provider_ip_cache.json"
    )
    range_file = pathlib.Path(
        str(net.get("provider_token_cache_file", "/pteroprotect/cache/provider_ranges.txt")).strip()
        or "/pteroprotect/cache/provider_ranges.txt"
    )
    ttl = int(net.get("provider_token_ip_cache_ttl_sec", 604800) or 604800)
    ttl = max(300, min(ttl, 2592000))
    keywords = [k.lower() for k in parse_list(net.get("provider_token_provider_keywords", ""))]

    cache = load_json(cache_file)
    now = int(time.time())
    entry = cache.get(ip)
    if isinstance(entry, dict) and int(entry.get("exp", 0) or 0) >= now:
        if entry.get("is_provider") and entry.get("prefix"):
            append_prefix_once(range_file, str(entry.get("prefix", "")).strip())
            print("provider")
            return 0
        print("other")
        return 0

    prefix, holder = fetch_prefix_overview(ip)
    holder_l = holder.lower()
    is_provider = False
    for keyword in keywords:
        if keyword and keyword in holder_l:
            is_provider = True
            break

    cache[ip] = {
        "holder": holder,
        "prefix": prefix,
        "is_provider": is_provider,
        "exp": now + ttl,
    }
    cache_file.parent.mkdir(parents=True, exist_ok=True)
    cache_file.write_text(json.dumps(cache, separators=(",", ":"), sort_keys=True))

    if is_provider and prefix:
        append_prefix_once(range_file, prefix)
        print("provider")
    else:
        print("other")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

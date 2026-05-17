#!/usr/bin/env python3
"""
Aggregates security metrics files into a single Prometheus textfile.

Output: /pteroprotect/runtime/resilience.prom (configurable)
"""

from __future__ import annotations

import glob
import os
import socket

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
import time
from typing import Dict, List

from resilience_runtime import as_bool, as_int, cfg_resilience, load_config, prom_line, write_prom


def log(msg: str) -> None:
    ts = time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime())
    print(f"[{ts}] [resilience-collector] {msg}", flush=True)


def parse_prom_values(path: str) -> List[float]:
    vals: List[float] = []
    try:
        with open(path, "r", encoding="utf-8", errors="ignore") as f:
            for line in f:
                s = line.strip()
                if not s or s.startswith("#"):
                    continue
                parts = s.split()
                if len(parts) < 2:
                    continue
                try:
                    vals.append(float(parts[-1]))
                except Exception:
                    continue
    except Exception:
        return []
    return vals


def main() -> int:
    cfg = load_config()
    res = cfg_resilience(cfg)
    if not as_bool(res.get("enabled", True), True):
        log("resilience disabled; exiting")
        return 0

    out_file = str(res.get("metrics_file", "/pteroprotect/runtime/resilience.prom"))
    runtime_dir = cfg.get("runtime", {}).get("state_dir", "/pteroprotect/runtime") if isinstance(cfg.get("runtime"), dict) else "/pteroprotect/runtime"
    interval = max(1, as_int(res.get("collector_interval_sec", 2), 2))

    static_sources = [
        os.path.join(runtime_dir, "self_heal.prom"),
        os.path.join(runtime_dir, "abuse_guard.prom"),
        os.path.join(runtime_dir, "security_log_watch.prom"),
        os.path.join(runtime_dir, "resilience_orchestrator.prom"),
    ]

    log(f"collector started output={out_file} interval={interval}s")

    while True:
        sources = list(static_sources)
        sources.extend(glob.glob(os.path.join(runtime_dir, "*.prom")))
        sources = sorted(set(sources))
        # Avoid recursively summing our own output.
        sources = [s for s in sources if os.path.abspath(s) != os.path.abspath(out_file)]

        by_source: Dict[str, float] = {}
        for path in sources:
            vals = parse_prom_values(path)
            if not vals:
                continue
            by_source[os.path.basename(path)] = sum(vals)

        lines = [
            "# HELP pteroprotect_resilience_collector_metric_sum Per-source sum of collected .prom gauge/counter values.",
            "# TYPE pteroprotect_resilience_collector_metric_sum gauge",
            "# HELP pteroprotect_resilience_collector_source_up Whether a metric source file is present and parsable.",
            "# TYPE pteroprotect_resilience_collector_source_up gauge",
            prom_line("pteroprotect_resilience_collector_sources_total", float(len(sources))),
        ]

        for src in sources:
            name = os.path.basename(src)
            up = 1.0 if name in by_source else 0.0
            lines.append(prom_line("pteroprotect_resilience_collector_source_up", up, {"source": name}))
            if name in by_source:
                lines.append(prom_line("pteroprotect_resilience_collector_metric_sum", by_source[name], {"source": name}))

        write_prom(out_file, lines)
        time.sleep(interval)


if __name__ == "__main__":
    raise SystemExit(main())

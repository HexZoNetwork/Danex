#!/usr/bin/env python3
from __future__ import annotations

import collections
import hashlib
import json
import math
import os
import re
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any, Deque, Dict, Iterable, List, Optional, Tuple


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

from resilience_runtime import (
    RUNTIME_DIR,
    as_bool,
    as_float,
    as_int,
    cfg_resilience,
    emit_resilience_event,
    load_config,
    load_json,
    prom_line,
    utc_ts,
    write_json,
    write_prom,
)


def log(msg: str) -> None:
    ts = time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime())
    print(f"[{ts}] [resilience] {msg}", flush=True)


ACCESS_RE = re.compile(
    r'^(?P<ip>\S+)\s+\S+\s+\S+\s+\[[^\]]+\]\s+"(?P<method>[A-Z]+)\s+(?P<target>[^\s]+)(?:\s+HTTP/[0-9.]+)?"\s+(?P<status>\d{3})\s+\S+\s+"[^"]*"\s+"(?P<ua>[^"]*)"'
)


@dataclass
class ReqSample:
    ts: float
    ip: str
    method: str
    path: str
    status: int
    ua_family: str
    ua_raw: str


class LogFollower:
    def __init__(self, path: str):
        self.path = path
        self.fp = None
        self.ino = 0

    def poll(self, max_lines: int = 2000) -> List[str]:
        if not self.path:
            return []
        try:
            st = os.stat(self.path)
        except Exception:
            return []

        if self.fp is None or self.ino != st.st_ino:
            self.fp = open(self.path, "r", encoding="utf-8", errors="ignore")
            self.fp.seek(0, os.SEEK_END)
            self.ino = st.st_ino
            return []

        out: List[str] = []
        for _ in range(max_lines):
            line = self.fp.readline()
            if not line:
                break
            out.append(line.rstrip("\n"))
        return out


class RollingBaseline:
    def __init__(self, max_points: int = 1200):
        self.values: Deque[float] = collections.deque(maxlen=max_points)

    def add(self, v: float) -> None:
        self.values.append(float(v))

    def median(self) -> float:
        if not self.values:
            return 0.0
        arr = sorted(self.values)
        n = len(arr)
        mid = n // 2
        if n % 2 == 1:
            return float(arr[mid])
        return (arr[mid - 1] + arr[mid]) / 2.0

    def mad(self) -> float:
        if not self.values:
            return 0.0
        med = self.median()
        dev = sorted(abs(v - med) for v in self.values)
        n = len(dev)
        mid = n // 2
        if n % 2 == 1:
            return float(dev[mid])
        return (dev[mid - 1] + dev[mid]) / 2.0

    def robust_z(self, v: float) -> float:
        med = self.median()
        mad = self.mad()
        if mad <= 1e-9:
            return 0.0
        return (v - med) / (1.4826 * mad)


def clamp01(v: float) -> float:
    return max(0.0, min(1.0, float(v)))


def entropy_from_counts(counts: Dict[str, int]) -> float:
    total = sum(max(0, int(v)) for v in counts.values())
    if total <= 0:
        return 0.0
    h = 0.0
    for c in counts.values():
        if c <= 0:
            continue
        p = c / total
        h -= p * math.log(max(p, 1e-12), 2)
    return h


def ua_family(ua: str) -> str:
    raw = (ua or "").strip().lower()
    if raw == "":
        return "empty"
    if any(tok in raw for tok in ["headless", "curl", "python", "wget", "bot", "spider", "scanner", "sqlmap"]):
        return "automation"
    if "mozilla" in raw or "chrome" in raw or "safari" in raw or "firefox" in raw:
        return "browser"
    return "other"


def normalize_path(target: str) -> str:
    path = target.split("?", 1)[0].strip().lower()
    path = re.sub(r"/[0-9a-f]{8,}(?=/|$)", "/{id}", path)
    path = re.sub(r"/\d{2,}(?=/|$)", "/{n}", path)
    return path if path else "/"


def parse_access_line(line: str) -> Optional[ReqSample]:
    m = ACCESS_RE.match(line.strip())
    if not m:
        return None
    method = m.group("method")
    target = m.group("target")
    status = as_int(m.group("status"), 0)
    ua = m.group("ua")
    ip = m.group("ip")
    return ReqSample(
        ts=time.time(),
        ip=ip,
        method=method,
        path=normalize_path(target),
        status=status,
        ua_family=ua_family(ua),
        ua_raw=ua,
    )


def is_challenge_path(path: str) -> bool:
    return path.startswith("/__pteroprotect/challenge")


def is_core_client_polling_path(path: str) -> bool:
    return re.match(r"^/api/client/servers/[^/]+/(resources|activity|websocket)(?:/|$)", path.lower()) is not None


def has_ua_marker(ua: str, markers: List[str]) -> bool:
    raw = (ua or "").strip().lower()
    if raw == "":
        return False
    for marker in markers:
        token = (marker or "").strip().lower()
        if token and token in raw:
            return True
    return False


def http_probe(url: str, timeout_sec: float = 2.5) -> Tuple[bool, int, float]:
    if not url:
        return False, 0, 0.0
    req = urllib.request.Request(url, method="GET", headers={"User-Agent": "PteroProtectResilience/1.0"})
    start = time.time()
    try:
        with urllib.request.urlopen(req, timeout=timeout_sec) as resp:
            _ = resp.read(128)
            return True, int(resp.status), (time.time() - start) * 1000.0
    except urllib.error.HTTPError as exc:
        return True, int(exc.code), (time.time() - start) * 1000.0
    except Exception:
        return False, 0, (time.time() - start) * 1000.0


def read_mem_pressure_pct() -> float:
    try:
        with open("/proc/meminfo", "r", encoding="utf-8") as f:
            raw = f.read().splitlines()
        vals = {}
        for line in raw:
            if ":" not in line:
                continue
            k, v = line.split(":", 1)
            vals[k.strip()] = as_float(v.strip().split()[0], 0.0)
        total = vals.get("MemTotal", 0.0)
        avail = vals.get("MemAvailable", 0.0)
        if total <= 0:
            return 0.0
        used = max(0.0, total - avail)
        return (used / total) * 100.0
    except Exception:
        return 0.0


def redis_cli(redis_url: str, *args: str) -> Optional[str]:
    if not redis_url:
        return None
    cmd = ["redis-cli", "-u", redis_url, *args]
    try:
        return subprocess.check_output(cmd, stderr=subprocess.DEVNULL, text=True, timeout=2).strip()
    except Exception:
        return None


def redis_ping_ms(redis_url: str) -> float:
    if not redis_url:
        return 0.0
    start = time.time()
    pong = redis_cli(redis_url, "PING")
    if pong != "PONG":
        return 0.0
    return (time.time() - start) * 1000.0


def redis_scan(redis_url: str, pattern: str) -> List[str]:
    if not redis_url:
        return []
    cmd = ["redis-cli", "-u", redis_url, "--scan", "--pattern", pattern]
    try:
        out = subprocess.check_output(cmd, stderr=subprocess.DEVNULL, text=True, timeout=2)
    except Exception:
        return []
    keys = [line.strip() for line in out.splitlines() if line.strip()]
    return keys


def score_from_latency(ok: bool, code: int, rtt_ms: float, good_codes: Iterable[int], soft_ms: float, hard_ms: float) -> float:
    if not ok:
        return 0.0
    if code not in set(good_codes):
        return 0.25
    if rtt_ms <= soft_ms:
        return 1.0
    if rtt_ms >= hard_ms:
        return 0.2
    ratio = (rtt_ms - soft_ms) / max(1.0, (hard_ms - soft_ms))
    return clamp01(1.0 - ratio * 0.8)


def stage_from_score(score: float, thresholds: Dict[str, Any]) -> str:
    if score >= as_float(thresholds.get("emergency", 0.88), 0.88):
        return "emergency"
    if score >= as_float(thresholds.get("constrained", 0.76), 0.76):
        return "constrained"
    if score >= as_float(thresholds.get("elevated", 0.62), 0.62):
        return "elevated"
    return "normal"


def stage_rank(stage: str) -> int:
    order = {"normal": 0, "elevated": 1, "constrained": 2, "emergency": 3}
    return order.get(stage, 0)


def route_class(path: str) -> str:
    p = path.lower().strip()
    if p.startswith("/auth"):
        return "auth"
    if p.startswith("/api/client/servers/") and ("/files/" in p or p.endswith("/upload") or "/backups" in p):
        return "resource"
    if p.startswith("/api"):
        return "api"
    if p.startswith("/api/client/servers/") and p.endswith("/websocket"):
        return "websocket"
    return "web"


def compute_feature_shedding(stage: str, profile: Dict[str, Any]) -> Dict[str, bool]:
    s1 = set(str(x) for x in profile.get("stage1", []))
    s2 = set(str(x) for x in profile.get("stage2", []))
    s3 = set(str(x) for x in profile.get("stage3", []))
    flags = {
        "chat": False,
        "ads": False,
        "create_panel": False,
        "heavy_files": False,
        "noncritical_api": False,
        "websocket": False,
        "polling": False,
    }
    if stage_rank(stage) >= stage_rank("elevated"):
        for f in s1:
            if f in flags:
                flags[f] = True
    if stage_rank(stage) >= stage_rank("constrained"):
        for f in s2:
            if f in flags:
                flags[f] = True
    if stage_rank(stage) >= stage_rank("emergency"):
        for f in s3:
            if f in flags:
                flags[f] = True
    return flags


def open_circuit(circuits: Dict[str, Any], dep: str, now: int, cooldown: int, reason: str) -> None:
    cur = circuits.get(dep, {}) if isinstance(circuits.get(dep, {}), dict) else {}
    fails = as_int(cur.get("fails", 0), 0) + 1
    backoff = min(120, max(5, cooldown * max(1, fails)))
    circuits[dep] = {
        "state": "open",
        "opened_at": now,
        "next_probe_at": now + backoff,
        "fails": fails,
        "reason": reason,
    }


def half_open_or_close(circuits: Dict[str, Any], dep: str, now: int, healthy: bool, cooldown: int) -> None:
    cur = circuits.get(dep, {}) if isinstance(circuits.get(dep, {}), dict) else {}
    state = str(cur.get("state", "closed"))
    if state == "closed":
        if not healthy:
            open_circuit(circuits, dep, now, cooldown, "dependency_unhealthy")
        return

    if state == "open":
        if now >= as_int(cur.get("next_probe_at", now + cooldown), now + cooldown):
            circuits[dep]["state"] = "half_open"
        return

    if state == "half_open":
        if healthy:
            circuits[dep] = {"state": "closed", "opened_at": 0, "next_probe_at": 0, "fails": 0, "reason": ""}
        else:
            open_circuit(circuits, dep, now, cooldown, "half_open_failed")


def read_queue_backlog_age(runtime_dir: str) -> float:
    candidates = [
        os.path.join(runtime_dir, "queue_backlog_age_seconds"),
        os.path.join(runtime_dir, "queue_age_seconds"),
    ]
    for p in candidates:
        try:
            with open(p, "r", encoding="utf-8") as f:
                return max(0.0, as_float(f.read().strip(), 0.0))
        except Exception:
            continue
    return 0.0


def fault_flag(runtime_dir: str, name: str) -> bool:
    p = os.path.join(runtime_dir, f"fault_{name}.flag")
    return os.path.isfile(p)


def is_state_changing_method(method: str) -> bool:
    return method.upper() in {"POST", "PUT", "PATCH", "DELETE"}


def is_safe_replay_path(path: str, allowed_post_paths: List[str]) -> bool:
    if path.startswith("/api/client/servers/"):
        # Avoid replaying mutable server-control ops.
        if any(tok in path for tok in ["/power", "/command", "/reinstall", "/startup", "/settings", "/network", "/users", "/backups", "/files/"]):
            return False
    if path in allowed_post_paths:
        return True
    if path.startswith("/auth"):
        return False
    return False


def prune_replay_queue(path: str, ttl_sec: int, cap: int) -> int:
    now = utc_ts()
    rows: List[str] = []
    try:
        with open(path, "r", encoding="utf-8") as f:
            rows = [line.rstrip("\n") for line in f if line.strip()]
    except Exception:
        return 0

    kept: List[Dict[str, Any]] = []
    for line in rows[-max(cap * 3, 1):]:
        try:
            obj = json.loads(line)
        except Exception:
            continue
        exp = as_int(obj.get("expires_at", 0), 0)
        if exp <= now:
            continue
        kept.append(obj)

    if len(kept) > cap:
        kept = kept[-cap:]

    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        for obj in kept:
            f.write(json.dumps(obj, ensure_ascii=True, separators=(",", ":")) + "\n")
    return len(kept)


def write_mode_files(stage: str, runtime_dir: str, monitor: Optional[Dict[str, Any]] = None) -> None:
    mode = "normal"
    if stage == "constrained":
        mode = "aggressive"
    if stage == "emergency":
        mode = "emergency"

    now = utc_ts()
    mode_payload = {"mode": mode, "updated_at": now, "source": "resilience_orchestrator"}
    lock_payload = {"enabled": stage == "emergency", "updated_at": now}
    if stage == "emergency":
        lock_payload["until"] = now + 180
        lock_payload["reason"] = "resilience_emergency"

    shm = "/dev/shm/pteroprotect"
    host_mode = load_json(os.path.join(shm, "mode.flag"), {})
    host_lock = load_json(os.path.join(shm, "strict_lockdown.flag"), {})
    host_mode_source = str(host_mode.get("source", ""))
    host_lock_source = str(host_lock.get("source", ""))
    host_mode_until = as_int(host_mode.get("until", 0), 0)
    host_lock_until = as_int(host_lock.get("until", 0), 0)
    host_mode_value = str(host_mode.get("mode", "normal"))
    host_mode_active = (
        host_mode_source != "resilience_orchestrator"
        and host_mode_value in {"normal", "aggressive", "emergency"}
        and host_mode_until > now
    )
    host_lock_active = (
        host_lock_source != "resilience_orchestrator"
        and bool(host_lock.get("enabled"))
        and host_lock_until > now
    )
    host_health_gate = read_health_gate(runtime_dir, monitor or {})
    host_lock_health_ready = bool(host_health_gate.get("degraded"))
    if host_mode_active and (host_mode_value != "emergency" or host_lock_health_ready):
        mode_payload = dict(host_mode)
        mode_payload["updated_at"] = now
        if host_mode_value == "emergency":
            if host_lock_active:
                lock_payload = host_lock
        else:
            lock_payload = {"enabled": False, "updated_at": now, "source": "resilience_orchestrator", "reason": "manual_mode_override"}
    elif stage != "emergency" and host_lock_active and host_lock_health_ready:
        mode_payload = {
            "mode": "emergency",
            "updated_at": now,
            "source": "resilience_orchestrator",
            "reason": "host_guard_lockdown",
        }
        lock_payload = host_lock

    write_json(os.path.join(runtime_dir, "mode.json"), mode_payload)
    write_json(os.path.join(runtime_dir, "lockdown.json"), lock_payload)
    # Keep shared-memory compatibility with existing guards.
    write_json(os.path.join(shm, "mode.flag"), mode_payload)
    write_json(os.path.join(shm, "strict_lockdown.flag"), lock_payload)


def read_health_gate(runtime_dir: str, monitor: Dict[str, Any]) -> Dict[str, Any]:
    now = utc_ts()
    default_max_age = as_int(monitor.get("health_snapshot_max_age_sec", 45), 45)
    max_age = max(10, default_max_age)
    p95_threshold = as_float(monitor.get("latency_p95_ms_threshold", 2500), 2500)
    error_threshold = as_float(monitor.get("error_rate_threshold", 0.5), 0.5)
    signal_threshold = max(1, as_int(monitor.get("emergency_health_signals_threshold", 1), 1))
    path = str(monitor.get("health_snapshot_file", os.path.join(runtime_dir, "self_heal_dependency.json")))
    snap = load_json(path, {})
    if not isinstance(snap, dict):
        snap = {}

    ts = as_int(snap.get("ts", 0), 0)
    age = now - ts if ts > 0 else 999999
    fresh = ts > 0 and age <= max_age
    signals = as_int(snap.get("signals", 0), 0)
    p95_ms = as_float(snap.get("p95_ms", 0.0), 0.0)
    error_rate = as_float(snap.get("error_rate", 0.0), 0.0)
    external_ok = bool(snap.get("external_ok", True))
    challenge_ok = bool(snap.get("challenge_ok", True))
    degraded = bool(
        fresh
        and (
            signals >= signal_threshold
            or p95_ms >= p95_threshold
            or error_rate >= error_threshold
            or not external_ok
            or not challenge_ok
        )
    )

    return {
        "fresh": fresh,
        "age_sec": age,
        "degraded": degraded,
        "signals": signals,
        "p95_ms": p95_ms,
        "error_rate": error_rate,
        "external_ok": external_ok,
        "challenge_ok": challenge_ok,
        "source": str(snap.get("source", "")),
        "path": path,
    }


def get_redis_ballots(redis_url: str) -> List[Dict[str, Any]]:
    keys = redis_scan(redis_url, "ddos:ballot:*")
    ballots: List[Dict[str, Any]] = []
    for k in keys[:200]:
        raw = redis_cli(redis_url, "GET", k)
        if not raw:
            continue
        try:
            obj = json.loads(raw)
        except Exception:
            continue
        if isinstance(obj, dict):
            ballots.append(obj)
    return ballots


def consensus_stage(local_stage: str, ballots: List[Dict[str, Any]], quorum: int) -> Tuple[str, float]:
    counter: Dict[str, int] = collections.Counter()
    confidences: List[float] = []
    counter[local_stage] += 1
    confidences.append(0.6)

    now = utc_ts()
    for b in ballots:
        ts = as_int(b.get("ts", 0), 0)
        if ts <= 0 or now - ts > 90:
            continue
        st = str(b.get("stage", "normal"))
        if st not in {"normal", "elevated", "constrained", "emergency"}:
            continue
        counter[st] += 1
        confidences.append(clamp01(as_float(b.get("confidence", 0.5), 0.5)))

    if not counter:
        return local_stage, 0.5

    best_stage, best_votes = max(counter.items(), key=lambda kv: (kv[1], stage_rank(kv[0])))
    if best_votes < max(1, quorum):
        return local_stage, sum(confidences) / max(1, len(confidences))

    return best_stage, sum(confidences) / max(1, len(confidences))


def main() -> int:
    cfg = load_config()
    net = cfg.get("network", {}) if isinstance(cfg.get("network"), dict) else {}
    monitor = cfg.get("monitor", {}) if isinstance(cfg.get("monitor"), dict) else {}
    res = cfg_resilience(cfg)

    if not as_bool(res.get("enabled", True), True):
        log("resilience disabled in config; exiting")
        return 0

    runtime_dir = cfg.get("runtime", {}).get("state_dir", RUNTIME_DIR) if isinstance(cfg.get("runtime"), dict) else RUNTIME_DIR
    state_file = str(res.get("state_file", os.path.join(runtime_dir, "resilience_state.json")))
    poison_file = str(res.get("poison_file", os.path.join(runtime_dir, "poison_fingerprints.json")))
    replay_file = str(res.get("replay_queue_file", os.path.join(runtime_dir, "replay_queue.jsonl")))
    metrics_file = str(res.get("orchestrator_metrics_file", os.path.join(runtime_dir, "resilience_orchestrator.prom")))
    events_file = str(res.get("events_file", os.path.join(runtime_dir, "resilience_events.jsonl")))

    sample_interval = max(1, as_int(res.get("sample_interval_sec", 2), 2))
    window_sec = max(30, as_int(res.get("window_sec", 120), 120))
    health_cfg = res.get("health", {}) if isinstance(res.get("health"), dict) else {}
    weights = health_cfg.get("weights", {}) if isinstance(health_cfg.get("weights"), dict) else {}

    detection = res.get("detection", {}) if isinstance(res.get("detection"), dict) else {}
    access_log = str(detection.get("access_log", "/var/log/nginx/pteroprotect.access.log"))
    poison_ttl = max(60, as_int(detection.get("poison_ttl_sec", 1800), 1800))
    hard_conf = clamp01(as_float(detection.get("hard_drop_confidence", 0.90), 0.90))
    soft_conf = clamp01(as_float(detection.get("soft_drop_confidence", 0.70), 0.70))
    min_samples = max(10, as_int(detection.get("min_samples", 30), 30))
    elevated_req_rate = max(1.0, as_float(detection.get("elevated_req_rate", 15.0), 15.0))
    emergency_req_rate = max(elevated_req_rate, as_float(detection.get("emergency_req_rate", 45.0), 45.0))
    elevated_bad_rate = clamp01(as_float(detection.get("elevated_bad_rate", 0.25), 0.25))
    emergency_bad_rate = clamp01(as_float(detection.get("emergency_bad_rate", 0.45), 0.45))
    elevated_drop_rate = clamp01(as_float(detection.get("elevated_drop_rate", 0.18), 0.18))
    emergency_drop_rate = clamp01(as_float(detection.get("emergency_drop_rate", 0.35), 0.35))
    elevated_ratio_req_rate_min = max(
        0.5,
        as_float(
            detection.get("elevated_ratio_req_rate_min", max(2.0, elevated_req_rate * 0.20)),
            max(2.0, elevated_req_rate * 0.20),
        ),
    )
    emergency_ratio_req_rate_min = max(
        elevated_ratio_req_rate_min,
        as_float(
            detection.get("emergency_ratio_req_rate_min", max(4.0, emergency_req_rate * 0.20)),
            max(4.0, emergency_req_rate * 0.20),
        ),
    )
    require_health_degradation_for_emergency = as_bool(
        detection.get("require_health_degradation_for_emergency", monitor.get("require_health_degradation_for_emergency", True)),
        True,
    )
    exclude_monitor_traffic_from_scoring = as_bool(detection.get("exclude_monitor_traffic_from_scoring", True), True)
    exclude_challenge_paths_from_scoring = as_bool(detection.get("exclude_challenge_paths_from_scoring", True), True)
    require_secondary_signal_for_elevated_when_healthy = as_bool(
        detection.get("require_secondary_signal_for_elevated_when_healthy", True),
        True,
    )
    monitor_ua_markers = detection.get(
        "monitor_ua_markers",
        [
            "checkhost",
            "pteroprotectresilience",
            "danexselfheal",
            "uptime-kuma",
            "statuscake",
            "pingdom",
            "healthcheck",
        ],
    )
    if not isinstance(monitor_ua_markers, list):
        monitor_ua_markers = []
    monitor_ua_markers = [str(x).strip().lower() for x in monitor_ua_markers if str(x).strip() != ""]
    trusted_monitor_ips = detection.get("trusted_monitor_ips", [])
    if not isinstance(trusted_monitor_ips, list):
        trusted_monitor_ips = []
    trusted_monitor_ips = {str(x).strip() for x in trusted_monitor_ips if str(x).strip() != ""}

    cool_cfg = res.get("cooldowns", {}) if isinstance(res.get("cooldowns"), dict) else {}
    stage_min_sec = max(5, as_int(cool_cfg.get("stage_min_sec", 30), 30))
    emergency_exit_stable_sec = max(20, as_int(cool_cfg.get("emergency_exit_stable_sec", 90), 90))
    half_open_probe_sec = max(5, as_int(cool_cfg.get("half_open_probe_sec", 15), 15))

    prg_thresholds = res.get("prg_thresholds", {}) if isinstance(res.get("prg_thresholds"), dict) else {}

    consensus_cfg = res.get("consensus", {}) if isinstance(res.get("consensus"), dict) else {}
    consensus_enabled = as_bool(consensus_cfg.get("enabled", True), True)
    quorum = max(1, as_int(consensus_cfg.get("quorum", 2), 2))
    ballot_ttl = max(10, as_int(consensus_cfg.get("ttl_sec", 30), 30))

    governor_cfg = res.get("resource_governor", {}) if isinstance(res.get("resource_governor"), dict) else {}
    governor_enabled = as_bool(governor_cfg.get("enabled", True), True)
    base_budgets = governor_cfg.get("base_budgets", {}) if isinstance(governor_cfg.get("base_budgets"), dict) else {}

    replay_cfg = res.get("replay", {}) if isinstance(res.get("replay"), dict) else {}
    replay_enabled = as_bool(replay_cfg.get("enabled", True), True)
    replay_ttl = max(30, as_int(replay_cfg.get("ttl_sec", 600), 600))
    replay_max = max(100, as_int(replay_cfg.get("max_queue", 2000), 2000))
    replay_allowed_posts = replay_cfg.get("allowed_post_paths", []) if isinstance(replay_cfg.get("allowed_post_paths"), list) else []

    follower = LogFollower(access_log)
    samples: Deque[ReqSample] = collections.deque()
    last_stage_change = 0
    stable_since = utc_ts()
    stage = "normal"
    circuits: Dict[str, Any] = {}

    poison_state = load_json(poison_file, {})
    if not isinstance(poison_state, dict):
        poison_state = {}

    baselines = {
        "req_rate": RollingBaseline(),
        "bad_rate": RollingBaseline(),
        "route_asym": RollingBaseline(),
        "ua_entropy": RollingBaseline(),
        "challenge_corr": RollingBaseline(),
    }

    log(f"orchestrator started access_log={access_log} state_file={state_file}")

    while True:
        now = utc_ts()
        lines = follower.poll()

        parsed_batch: List[ReqSample] = []
        for line in lines:
            s = parse_access_line(line)
            if s is not None:
                samples.append(s)
                parsed_batch.append(s)

        # slide request window
        cutoff = time.time() - window_sec
        while samples and samples[0].ts < cutoff:
            samples.popleft()

        total = len(samples)
        scoring_total = 0
        excluded_monitor = 0
        excluded_challenge = 0
        bad = 0
        drops = 0
        route_counts: Dict[str, int] = collections.Counter()
        ua_counts: Dict[str, int] = collections.Counter()
        challenge_fails = 0
        auth_api_bad = 0

        for s in samples:
            is_monitor_traffic = has_ua_marker(s.ua_raw, monitor_ua_markers) or (s.ip in trusted_monitor_ips)
            is_challenge_traffic = is_challenge_path(s.path)
            scoring_excluded = False
            if exclude_monitor_traffic_from_scoring and is_monitor_traffic:
                excluded_monitor += 1
                scoring_excluded = True
            if exclude_challenge_paths_from_scoring and is_challenge_traffic:
                excluded_challenge += 1
                scoring_excluded = True
            if scoring_excluded:
                continue

            scoring_total += 1
            route_counts[s.path] += 1
            ua_counts[s.ua_family] += 1
            if s.status >= 400:
                bad += 1
                if s.status in {429, 444, 503}:
                    drops += 1
                if is_challenge_traffic:
                    challenge_fails += 1
                if s.path.startswith("/auth") or s.path.startswith("/api"):
                    auth_api_bad += 1

        req_rate = float(scoring_total) / float(max(1, window_sec))
        bad_rate = float(bad) / float(max(1, scoring_total))
        drop_rate = float(drops) / float(max(1, scoring_total))
        route_top = max(route_counts.values()) if route_counts else 0
        route_asym = (float(route_top) / float(max(1, scoring_total))) if scoring_total > 0 else 0.0
        ua_ent = entropy_from_counts(ua_counts)
        challenge_corr = float(challenge_fails) / float(max(1, auth_api_bad)) if auth_api_bad > 0 else 0.0

        baselines["req_rate"].add(req_rate)
        baselines["bad_rate"].add(bad_rate)
        baselines["route_asym"].add(route_asym)
        baselines["ua_entropy"].add(ua_ent)
        baselines["challenge_corr"].add(challenge_corr)

        req_z = baselines["req_rate"].robust_z(req_rate)
        bad_z = baselines["bad_rate"].robust_z(bad_rate)
        asym_z = baselines["route_asym"].robust_z(route_asym)
        corr_z = baselines["challenge_corr"].robust_z(challenge_corr)

        ua_med = baselines["ua_entropy"].median()
        ua_drop = max(0.0, ua_med - ua_ent)
        ua_drop_scaled = clamp01(ua_drop / max(0.5, ua_med + 1e-6))

        # Convert robust z into bounded anomaly components.
        req_score = clamp01(max(0.0, req_z) / 4.0)
        bad_score = clamp01(max(0.0, bad_z) / 4.0)
        asym_score = clamp01(max(0.0, asym_z) / 4.0)
        corr_score = clamp01(max(0.0, corr_z) / 4.0)

        anomaly_score = clamp01(
            (0.35 * req_score)
            + (0.30 * bad_score)
            + (0.20 * asym_score)
            + (0.10 * ua_drop_scaled)
            + (0.05 * corr_score)
        )
        absolute_pressure = 0.0
        absolute_reasons: List[str] = []
        enough_ratio_samples = scoring_total >= min_samples
        if req_rate >= emergency_req_rate:
            absolute_pressure = max(absolute_pressure, 0.92)
            absolute_reasons.append("emergency_req_rate")
        elif req_rate >= elevated_req_rate:
            absolute_pressure = max(absolute_pressure, 0.66)
            absolute_reasons.append("elevated_req_rate")
        enough_elevated_ratio_pressure = enough_ratio_samples and req_rate >= elevated_ratio_req_rate_min
        enough_emergency_ratio_pressure = enough_ratio_samples and req_rate >= emergency_ratio_req_rate_min
        if enough_emergency_ratio_pressure and bad_rate >= emergency_bad_rate:
            absolute_pressure = max(absolute_pressure, 0.94)
            absolute_reasons.append("emergency_bad_rate")
        elif enough_elevated_ratio_pressure and bad_rate >= elevated_bad_rate:
            absolute_pressure = max(absolute_pressure, 0.68)
            absolute_reasons.append("elevated_bad_rate")
        if enough_emergency_ratio_pressure and drop_rate >= emergency_drop_rate:
            absolute_pressure = max(absolute_pressure, 0.96)
            absolute_reasons.append("emergency_drop_rate")
        elif enough_elevated_ratio_pressure and drop_rate >= elevated_drop_rate:
            absolute_pressure = max(absolute_pressure, 0.70)
            absolute_reasons.append("elevated_drop_rate")
        if route_asym >= 0.65 and enough_ratio_samples:
            absolute_pressure = max(absolute_pressure, 0.72)
            absolute_reasons.append("route_asymmetry")

        anomaly_score = max(anomaly_score, absolute_pressure)
        confidence = clamp01(float(scoring_total) / float(max(min_samples, 1)))

        # Poison fingerprinting.
        for s in parsed_batch:
            if exclude_monitor_traffic_from_scoring and (has_ua_marker(s.ua_raw, monitor_ua_markers) or (s.ip in trusted_monitor_ips)):
                continue
            if exclude_challenge_paths_from_scoring and is_challenge_path(s.path):
                continue
            if is_core_client_polling_path(s.path):
                continue
            if s.ua_family == "browser":
                continue
            if s.status < 400:
                continue

            status_family = f"{s.status // 100}xx"
            fp_raw = f"{s.method}|{s.path}|{s.ua_family}|{status_family}"
            fp = hashlib.sha256(fp_raw.encode("utf-8")).hexdigest()

            suspicious = False
            if s.status >= 429:
                suspicious = True
            if anomaly_score >= 0.70 and (s.path.startswith("/auth") or s.path.startswith("/api")):
                suspicious = True
            if not suspicious:
                continue

            cur = poison_state.get(fp, {}) if isinstance(poison_state.get(fp, {}), dict) else {}
            count = as_int(cur.get("count", 0), 0) + 1
            conf = clamp01(max(as_float(cur.get("confidence", 0.0), 0.0), anomaly_score * 0.8) + min(0.2, count / 100.0))
            action = "observe"
            if conf >= hard_conf:
                action = "hard_drop"
            elif conf >= soft_conf:
                action = "soft_drop"

            poison_state[fp] = {
                "fingerprint": fp,
                "signature": fp_raw[:240],
                "count": count,
                "confidence": conf,
                "action": action,
                "updated_at": now,
                "expires_at": now + poison_ttl,
            }

        for key in list(poison_state.keys()):
            entry = poison_state.get(key, {})
            if not isinstance(entry, dict):
                poison_state.pop(key, None)
                continue
            if as_int(entry.get("expires_at", 0), 0) < now:
                poison_state.pop(key, None)

        # Dependency health probes.
        base_url = str(monitor.get("external_url", "")).rstrip("/")
        challenge_path = str(monitor.get("challenge_path", "/__pteroprotect/challenge/page"))
        challenge_url = (base_url + challenge_path) if base_url else ""
        local_health_url = str(monitor.get("local_health_url", "http://127.0.0.1:18080/api/system"))
        control_plane_url = str(net.get("control_plane_url", "http://127.0.0.1:18446")).rstrip("/")

        ok_local, code_local, local_rtt = http_probe(local_health_url)
        challenge_enabled = as_bool(net.get("waf_challenge_enabled", False), False) or as_bool(net.get("provider_token_gate_enabled", False), False)
        ok_chal, code_chal, chal_rtt = http_probe(challenge_url) if challenge_url and challenge_enabled else (True, 204, 0.0)
        ok_nodes, code_nodes, nodes_rtt = http_probe(control_plane_url + "/nodes")
        redis_url = str(net.get("redis_url", "") or "").strip()
        redis_rtt = redis_ping_ms(redis_url)
        mem_pressure = read_mem_pressure_pct()
        queue_age = read_queue_backlog_age(runtime_dir)

        dep_scores = {
            "db": score_from_latency(ok_local, code_local, local_rtt, [200, 401, 403], 350.0, 2000.0),
            "challenge": score_from_latency(ok_chal, code_chal, chal_rtt, [200, 204], 400.0, 2500.0) if challenge_url else 0.85,
            "wings": score_from_latency(ok_nodes, code_nodes, nodes_rtt, [200], 300.0, 2000.0),
            "redis": clamp01(1.0 - max(0.0, (redis_rtt - 50.0) / 900.0)) if redis_rtt > 0 else 0.2,
            "queue": clamp01(1.0 - max(0.0, (queue_age - 5.0) / 120.0)),
        }
        health_gate = read_health_gate(runtime_dir, monitor)

        # Fault-injection hooks for deterministic recovery testing.
        if fault_flag(runtime_dir, "db_degraded"):
            dep_scores["db"] = min(dep_scores["db"], 0.2)
        if fault_flag(runtime_dir, "redis_degraded"):
            dep_scores["redis"] = min(dep_scores["redis"], 0.2)
        if fault_flag(runtime_dir, "wings_degraded"):
            dep_scores["wings"] = min(dep_scores["wings"], 0.2)
        if fault_flag(runtime_dir, "challenge_degraded"):
            dep_scores["challenge"] = min(dep_scores["challenge"], 0.2)

        health_score = 0.0
        for dep, w in weights.items():
            health_score += clamp01(as_float(w, 0.0)) * clamp01(dep_scores.get(dep, 0.0))
        health_score = clamp01(health_score)

        emergency_health_ready = (not require_health_degradation_for_emergency) or bool(health_gate.get("degraded"))
        healthy_health_gate = not bool(health_gate.get("degraded"))
        gated_absolute_pressure = absolute_pressure
        gated_anomaly_score = anomaly_score
        non_route_absolute_reasons = [reason for reason in absolute_reasons if reason != "route_asymmetry"]
        if (
            require_secondary_signal_for_elevated_when_healthy
            and healthy_health_gate
            and "route_asymmetry" in absolute_reasons
            and not non_route_absolute_reasons
        ):
            elevated_cap = max(0.0, as_float(prg_thresholds.get("elevated", 0.62), 0.62) - 0.02)
            gated_absolute_pressure = min(gated_absolute_pressure, elevated_cap)
            gated_anomaly_score = min(gated_anomaly_score, elevated_cap)
        if require_health_degradation_for_emergency and not emergency_health_ready and absolute_pressure >= as_float(prg_thresholds.get("emergency", 0.88), 0.88):
            gated_absolute_pressure = min(absolute_pressure, as_float(prg_thresholds.get("constrained", 0.76), 0.76) + 0.03)
            gated_anomaly_score = min(anomaly_score, gated_absolute_pressure)

        # Attack score increases when health drops and anomalies rise. Emergency is
        # gated by external health so healthy CheckHost/local probes do not cause
        # needless lockdown from volume alone.
        attack_score = clamp01(max(gated_absolute_pressure, (gated_anomaly_score * 0.7) + ((1.0 - health_score) * 0.3)))

        local_stage = stage_from_score(attack_score, prg_thresholds)
        if require_health_degradation_for_emergency and local_stage == "emergency" and not emergency_health_ready:
            local_stage = "constrained"

        # Dependency circuit breakers and propagation.
        for dep, dscore in dep_scores.items():
            healthy = dscore >= as_float(health_cfg.get("degraded_score", 0.70), 0.70)
            half_open_or_close(circuits, dep, now, healthy, half_open_probe_sec)

        # Consensus ballots.
        node_id = str(net.get("node_id", os.uname().nodename))
        if consensus_enabled and redis_url:
            ballot = {
                "node_id": node_id,
                "ts": now,
                "stage": local_stage,
                "attack_score": round(attack_score, 6),
                "health_score": round(health_score, 6),
                "confidence": round(confidence, 6),
            }
            redis_cli(redis_url, "SETEX", f"ddos:ballot:{node_id}", str(ballot_ttl), json.dumps(ballot, separators=(",", ":")))
            ballots = get_redis_ballots(redis_url)
            eff_stage, consensus_conf = consensus_stage(local_stage, ballots, quorum)
        else:
            ballots = []
            eff_stage = local_stage
            consensus_conf = confidence

        target_stage = eff_stage

        # Hysteresis and cooldown-based PRG transitions.
        elapsed = now - last_stage_change
        relaxing_stage = stage_rank(target_stage) < stage_rank(stage)
        if stage == "emergency":
            if stage_rank(target_stage) < stage_rank("emergency"):
                if (now - stable_since) >= emergency_exit_stable_sec:
                    if elapsed >= stage_min_sec:
                        stage = "constrained"
                        last_stage_change = now
                else:
                    target_stage = "emergency"
            else:
                stable_since = now
        else:
            if stage_rank(target_stage) > stage_rank(stage):
                if target_stage == "emergency" or elapsed >= stage_min_sec:
                    stage = target_stage
                    last_stage_change = now
            elif stage_rank(target_stage) < stage_rank(stage):
                # require stable cooldown before relaxing
                if elapsed >= stage_min_sec and (now - stable_since) >= stage_min_sec:
                    stage = target_stage
                    last_stage_change = now

        if target_stage == stage and not relaxing_stage:
            stable_since = now

        shedding = compute_feature_shedding(stage, res.get("feature_shedding", {}))

        # Resource governor budgets by stage and pressure.
        budgets = {
            "auth": as_int(base_budgets.get("auth", 40), 40),
            "api": as_int(base_budgets.get("api", 120), 120),
            "resource": as_int(base_budgets.get("resource", 80), 80),
            "websocket": as_int(base_budgets.get("websocket", 60), 60),
            "web": as_int(base_budgets.get("web", 100), 100),
        }
        if governor_enabled:
            pressure_factor = 1.0
            if mem_pressure >= as_float(governor_cfg.get("mem_pressure_pct", 90), 90):
                pressure_factor *= 0.7
            if stage == "elevated":
                pressure_factor *= 0.85
            elif stage == "constrained":
                pressure_factor *= 0.65
            elif stage == "emergency":
                pressure_factor *= 0.45
            for k, v in list(budgets.items()):
                budgets[k] = max(2, int(v * pressure_factor))

        # Replay queue maintenance.
        replay_queue_len = 0
        if replay_enabled:
            replay_queue_len = prune_replay_queue(replay_file, replay_ttl, replay_max)

        readiness = stage == "normal" and health_score >= 0.80 and attack_score <= 0.35 and consensus_conf >= 0.50

        # Rollback recommendation when attack starts shortly after deployment marker.
        rollback_recommended = False
        deploy_marker = os.path.join(runtime_dir, "deploy.marker")
        try:
            st = os.stat(deploy_marker)
            if (now - int(st.st_mtime)) <= 600 and stage_rank(stage) >= stage_rank("constrained"):
                rollback_recommended = True
        except Exception:
            rollback_recommended = False

        state = {
            "ts": now,
            "stage": stage,
            "target_stage": target_stage,
            "attack_score": round(attack_score, 6),
            "anomaly_score": round(anomaly_score, 6),
            "health_score": round(health_score, 6),
            "confidence": round(consensus_conf, 6),
            "traffic": {
                "total_requests_window": total,
                "scoring_requests_window": scoring_total,
                "excluded_requests_window": max(0, total - scoring_total),
                "excluded_monitor_window": excluded_monitor,
                "excluded_challenge_window": excluded_challenge,
                "requests_per_sec": round(req_rate, 6),
                "bad_rate": round(bad_rate, 6),
                "drop_rate": round(drop_rate, 6),
                "route_asymmetry": round(route_asym, 6),
                "absolute_pressure": round(absolute_pressure, 6),
                "gated_absolute_pressure": round(gated_absolute_pressure, 6),
                "absolute_reasons": absolute_reasons,
            },
            "health_gate": health_gate | {
                "require_degradation_for_emergency": require_health_degradation_for_emergency,
                "emergency_health_ready": emergency_health_ready,
                "healthy_gate": healthy_health_gate,
            },
            "consensus": {
                "enabled": consensus_enabled,
                "quorum": quorum,
                "ballots_seen": len(ballots),
                "effective_stage": target_stage,
            },
            "features": shedding,
            "resource_governor": {
                "enabled": governor_enabled,
                "budgets": budgets,
                "memory_pressure_pct": round(mem_pressure, 3),
            },
            "dependencies": {
                "db": {"score": dep_scores["db"], "ok": ok_local, "code": code_local, "rtt_ms": round(local_rtt, 3)},
                "challenge": {"score": dep_scores["challenge"], "ok": ok_chal, "code": code_chal, "rtt_ms": round(chal_rtt, 3)},
                "wings": {"score": dep_scores["wings"], "ok": ok_nodes, "code": code_nodes, "rtt_ms": round(nodes_rtt, 3)},
                "redis": {"score": dep_scores["redis"], "rtt_ms": round(redis_rtt, 3)},
                "queue": {"score": dep_scores["queue"], "backlog_age_sec": round(queue_age, 3)},
            },
            "circuit_breakers": circuits,
            "poison_fingerprints": {
                "count": len(poison_state),
                "hard_drop": sum(1 for p in poison_state.values() if isinstance(p, dict) and p.get("action") == "hard_drop"),
                "soft_drop": sum(1 for p in poison_state.values() if isinstance(p, dict) and p.get("action") == "soft_drop"),
            },
            "replay": {
                "enabled": replay_enabled,
                "queue_len": replay_queue_len,
                "max_queue": replay_max,
                "ttl_sec": replay_ttl,
                "allowed_post_paths": replay_allowed_posts,
            },
            "recovery": {
                "ready": readiness,
                "stable_since": stable_since,
                "cooldown_sec": stage_min_sec,
            },
            "rollback": {
                "recommended": rollback_recommended,
                "marker": deploy_marker,
            },
        }

        # Persist runtime artifacts.
        write_json(state_file, state)
        write_json(poison_file, poison_state)
        write_mode_files(stage, runtime_dir, monitor)

        emit_resilience_event(
            layer="orchestration",
            service="resilience_orchestrator",
            decision=stage,
            score=attack_score,
            confidence=consensus_conf,
            tenant_scope="global",
            expiry=now + ballot_ttl,
            extra={
                "health_score": round(health_score, 6),
                "anomaly_score": round(anomaly_score, 6),
                "traffic": {
                    "requests_per_sec": round(req_rate, 6),
                    "bad_rate": round(bad_rate, 6),
                    "drop_rate": round(drop_rate, 6),
                    "total_requests_window": total,
                    "scoring_requests_window": scoring_total,
                    "excluded_monitor_window": excluded_monitor,
                    "excluded_challenge_window": excluded_challenge,
                    "absolute_pressure": round(absolute_pressure, 6),
                    "gated_absolute_pressure": round(gated_absolute_pressure, 6),
                    "absolute_reasons": absolute_reasons,
                },
                "health_gate": health_gate | {
                    "require_degradation_for_emergency": require_health_degradation_for_emergency,
                    "emergency_health_ready": emergency_health_ready,
                    "healthy_gate": healthy_health_gate,
                },
                "features": shedding,
            },
            events_file=events_file,
        )

        prom_lines = [
            "# HELP pteroprotect_resilience_score Resilience scores and components.",
            "# TYPE pteroprotect_resilience_score gauge",
            prom_line("pteroprotect_resilience_score", attack_score, {"kind": "attack"}),
            prom_line("pteroprotect_resilience_score", anomaly_score, {"kind": "anomaly"}),
            prom_line("pteroprotect_resilience_score", health_score, {"kind": "health"}),
            prom_line("pteroprotect_resilience_confidence", consensus_conf),
            prom_line("pteroprotect_resilience_stage", float(stage_rank(stage)), {"stage": stage}),
            prom_line("pteroprotect_resilience_replay_queue", float(replay_queue_len)),
            prom_line("pteroprotect_resilience_poison_count", float(len(poison_state))),
            prom_line("pteroprotect_resilience_requests_per_sec", req_rate),
            prom_line("pteroprotect_resilience_bad_rate", bad_rate),
            prom_line("pteroprotect_resilience_drop_rate", drop_rate),
            prom_line("pteroprotect_resilience_absolute_pressure", absolute_pressure),
            prom_line("pteroprotect_resilience_gated_absolute_pressure", gated_absolute_pressure),
            prom_line("pteroprotect_resilience_health_gate_degraded", 1.0 if bool(health_gate.get("degraded")) else 0.0),
            prom_line("pteroprotect_resilience_traffic_scoring_ratio", (float(scoring_total) / float(max(1, total)))),
            prom_line("pteroprotect_resilience_route_asymmetry", route_asym),
            prom_line("pteroprotect_resilience_ua_entropy", ua_ent),
            prom_line("pteroprotect_resilience_challenge_correlation", challenge_corr),
        ]
        for dep, sc in dep_scores.items():
            prom_lines.append(prom_line("pteroprotect_resilience_dependency_score", sc, {"dependency": dep}))
        for dep, c in circuits.items():
            if not isinstance(c, dict):
                continue
            state_num = {"closed": 0, "half_open": 1, "open": 2}.get(str(c.get("state", "closed")), 0)
            prom_lines.append(prom_line("pteroprotect_resilience_circuit_state", float(state_num), {"dependency": dep, "state": str(c.get("state", "closed"))}))
        for cls, limit in budgets.items():
            prom_lines.append(prom_line("pteroprotect_resilience_budget", float(limit), {"class": cls}))
        write_prom(metrics_file, prom_lines)

        time.sleep(sample_interval)


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        raise SystemExit(0)

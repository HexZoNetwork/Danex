#!/usr/bin/env python3
"""
Shared runtime helpers for resilience instrumentation, state, and metrics.

This module is intentionally dependency-light so it can be reused by
self-heal, control-plane, anomaly services, and node agents.
"""

from __future__ import annotations

import hashlib
import json
import os
import time
from typing import Any, Dict, Iterable, List, Optional

CONFIG_PATH = os.environ.get("DANN_CONFIG_PATH", os.environ.get("PTEROPROTECT_CONFIG_PATH", "/pteroprotect/config.json"))
RUNTIME_DIR = os.environ.get("PTEROPROTECT_PANEL_RUNTIME_DIR", "/pteroprotect/runtime")


def utc_ts() -> int:
    return int(time.time())


def load_json(path: str, default: Any) -> Any:
    try:
        with open(path, "r", encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return default


def atomic_write(path: str, payload: str) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = f"{path}.tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        f.write(payload)
        if not payload.endswith("\n"):
            f.write("\n")
    os.replace(tmp, path)


def write_json(path: str, data: Dict[str, Any]) -> None:
    atomic_write(path, json.dumps(data, ensure_ascii=True, separators=(",", ":")))


def load_config(path: str = CONFIG_PATH) -> Dict[str, Any]:
    data = load_json(path, {})
    return data if isinstance(data, dict) else {}


def deep_get(d: Dict[str, Any], keys: Iterable[str], default: Any = None) -> Any:
    cur: Any = d
    for key in keys:
        if not isinstance(cur, dict) or key not in cur:
            return default
        cur = cur[key]
    return cur


def as_bool(v: Any, default: bool = False) -> bool:
    if isinstance(v, bool):
        return v
    if isinstance(v, (int, float)):
        return bool(v)
    if isinstance(v, str):
        return v.strip().lower() in {"1", "true", "yes", "on"}
    return default


def as_int(v: Any, default: int) -> int:
    try:
        return int(v)
    except Exception:
        return default


def as_float(v: Any, default: float) -> float:
    try:
        return float(v)
    except Exception:
        return default


def cfg_resilience(cfg: Dict[str, Any]) -> Dict[str, Any]:
    resilience = cfg.get("resilience", {})
    if not isinstance(resilience, dict):
        resilience = {}

    default = {
        "enabled": True,
        "mode": "adaptive",
        "state_file": os.path.join(RUNTIME_DIR, "resilience_state.json"),
        "events_file": os.path.join(RUNTIME_DIR, "resilience_events.jsonl"),
        "metrics_file": os.path.join(RUNTIME_DIR, "resilience.prom"),
        "orchestrator_metrics_file": os.path.join(RUNTIME_DIR, "resilience_orchestrator.prom"),
        "poison_file": os.path.join(RUNTIME_DIR, "poison_fingerprints.json"),
        "replay_queue_file": os.path.join(RUNTIME_DIR, "replay_queue.jsonl"),
        "collector_interval_sec": 2,
        "window_sec": 120,
        "sample_interval_sec": 2,
        "health": {
            "weights": {
                "db": 0.30,
                "redis": 0.20,
                "queue": 0.10,
                "wings": 0.20,
                "challenge": 0.20,
            },
            "degraded_score": 0.70,
            "unhealthy_score": 0.45,
        },
        "prg_thresholds": {
            "elevated": 0.62,
            "constrained": 0.76,
            "emergency": 0.88,
            "recover_to_constrained": 0.70,
            "recover_to_elevated": 0.55,
            "recover_to_normal": 0.35,
        },
        "cooldowns": {
            "stage_min_sec": 30,
            "emergency_exit_stable_sec": 90,
            "half_open_probe_sec": 15,
        },
        "consensus": {
            "enabled": True,
            "backend": "redis",
            "quorum": 2,
            "ttl_sec": 30,
        },
        "feature_shedding": {
            "profile": "aggressive",
            "stage1": ["chat", "ads", "create_panel"],
            "stage2": ["heavy_files", "noncritical_api"],
            "stage3": ["websocket", "polling"],
        },
        "replay": {
            "enabled": True,
            "max_queue": 2000,
            "ttl_sec": 600,
            "hmac_secret": "",
            "allowed_post_paths": [
                "/api/client/account/profile",
                "/api/client/account/password",
                "/api/client/chat/read",
                "/api/client/chat/notifications/read",
            ],
        },
        "resource_governor": {
            "enabled": True,
            "cpu_pressure_pct": 90,
            "mem_pressure_pct": 90,
            "base_budgets": {
                "auth": 40,
                "api": 120,
                "resource": 80,
                "websocket": 60,
                "web": 100,
            },
        },
        "criticality": {
            "core": ["auth", "api_remote", "node_control", "system_actions"],
            "shed_first": ["chat", "ads", "create_panel", "heavy_uploads"],
        },
        "detection": {
            "enabled": True,
            "access_log": "/var/log/nginx/pteroprotect.access.log",
            "waf_log": "/dev/shm/pteroprotect/waf.log",
            "min_samples": 30,
            "poison_ttl_sec": 1800,
            "hard_drop_confidence": 0.90,
            "soft_drop_confidence": 0.70,
            "elevated_ratio_req_rate_min": 3.0,
            "emergency_ratio_req_rate_min": 9.0,
            "exclude_monitor_traffic_from_scoring": True,
            "exclude_challenge_paths_from_scoring": True,
            "require_secondary_signal_for_elevated_when_healthy": True,
            "monitor_ua_markers": [
                "checkhost",
                "pteroprotectresilience",
                "danexselfheal",
                "uptime-kuma",
                "statuscake",
                "pingdom",
                "healthcheck",
            ],
            "trusted_monitor_ips": [],
        },
    }

    return deep_merge(default, resilience)


def deep_merge(base: Dict[str, Any], overlay: Dict[str, Any]) -> Dict[str, Any]:
    out = dict(base)
    for k, v in overlay.items():
        if isinstance(v, dict) and isinstance(out.get(k), dict):
            out[k] = deep_merge(out[k], v)
        else:
            out[k] = v
    return out


def emit_resilience_event(
    layer: str,
    service: str,
    decision: str,
    score: float,
    confidence: float,
    tenant_scope: str,
    expiry: int,
    extra: Optional[Dict[str, Any]] = None,
    events_file: Optional[str] = None,
) -> None:
    payload: Dict[str, Any] = {
        "ts": utc_ts(),
        "layer": str(layer),
        "service": str(service),
        "decision": str(decision),
        "score": round(float(score), 6),
        "confidence": round(float(confidence), 6),
        "tenant_scope": str(tenant_scope),
        "expiry": int(expiry),
    }
    if isinstance(extra, dict) and extra:
        payload.update(extra)

    out_file = events_file or os.path.join(RUNTIME_DIR, "resilience_events.jsonl")
    os.makedirs(os.path.dirname(out_file), exist_ok=True)
    line = json.dumps(payload, ensure_ascii=True, separators=(",", ":"))
    with open(out_file, "a", encoding="utf-8") as f:
        f.write(line + "\n")


def prom_line(name: str, value: float, labels: Optional[Dict[str, str]] = None) -> str:
    if not labels:
        return f"{name} {value}"
    safe = []
    for k, v in labels.items():
        kk = str(k).replace('"', "")
        vv = str(v).replace('\\', '\\\\').replace('"', '\\"')
        safe.append(f'{kk}="{vv}"')
    return f"{name}{{{','.join(safe)}}} {value}"


def write_prom(path: str, lines: List[str]) -> None:
    atomic_write(path, "\n".join(lines))


def hmac_ticket(secret: str, parts: List[str]) -> str:
    raw = "|".join(parts)
    digest = hashlib.sha256((secret + "|" + raw).encode("utf-8")).hexdigest()
    return digest

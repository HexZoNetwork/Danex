import socket
#!/usr/bin/env python3
import json
import os

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
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Dict, Tuple

from resilience_runtime import as_bool, cfg_resilience, emit_resilience_event, write_json

CONFIG_PATH = os.environ.get("DANN_CONFIG_PATH", "/pteroprotect/config.json")
SHM_RUNTIME_DIR = os.environ.get("PTEROPROTECT_RUNTIME_DIR", "/dev/shm/pteroprotect")
PANEL_RUNTIME_DIR = os.environ.get("PTEROPROTECT_PANEL_RUNTIME_DIR", "/pteroprotect/runtime")
DEFAULT_CHALLENGE_PATH = "/__pteroprotect/challenge/page"
DEFAULT_EXTERNAL_CHECK_API = "https://mywebcheck.netlify.app/.netlify/functions/check"


def is_placeholder_url(value: str) -> bool:
    try:
        host = urllib.parse.urlparse(str(value).strip()).hostname or ""
    except Exception:
        return True
    host = host.lower().strip(".")
    return host in {"", "example.com", "www.example.com", "example.net", "example.org"}


def resolve_external_url(cfg: dict, monitor: dict) -> str:
    monitor_url = str(monitor.get("external_url", "")).strip()
    ptlc_url = str(cfg.get("ptlc", {}).get("url", "")).strip() if isinstance(cfg, dict) else ""
    if monitor_url and not is_placeholder_url(monitor_url):
        return monitor_url.rstrip("/")
    if ptlc_url and not is_placeholder_url(ptlc_url):
        return ptlc_url.rstrip("/")
    return monitor_url.rstrip("/")


def log(msg: str) -> None:
    ts = time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime())
    print(f"[{ts}] [self-heal] {msg}", flush=True)


def run(cmd, timeout=8) -> Tuple[int, str, str]:
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        return p.returncode, p.stdout, p.stderr
    except Exception as exc:
        return 1, "", str(exc)


def load_config() -> dict:
    try:
        with open(CONFIG_PATH, "r", encoding="utf-8") as f:
            return json.load(f)
    except Exception as exc:
        log(f"config load failed: {exc}")
        return {}


def as_int(v, d: int) -> int:
    try:
        return int(v)
    except Exception:
        return d


def as_float(v, d: float) -> float:
    try:
        return float(v)
    except Exception:
        return d


def http_probe(url: str, timeout_sec: float = 6.0) -> Tuple[bool, int, float, str]:
    if not url:
        return False, 0, 0.0, "empty-url"
    req = urllib.request.Request(url, method="GET", headers={"User-Agent": "DanexSelfHeal/1.0"})
    start = time.time()
    try:
        with urllib.request.urlopen(req, timeout=timeout_sec) as resp:
            body = resp.read(256)
            elapsed = (time.time() - start) * 1000.0
            code = int(resp.status)
            return True, code, elapsed, body.decode("utf-8", errors="ignore")
    except urllib.error.HTTPError as exc:
        elapsed = (time.time() - start) * 1000.0
        try:
            body = exc.read(256)
        except Exception:
            body = b""
        return True, int(exc.code), elapsed, body.decode("utf-8", errors="ignore")
    except Exception as exc:
        elapsed = (time.time() - start) * 1000.0
        return False, 0, elapsed, str(exc)


def checkhost_probe(url: str, max_nodes: int = 8, zero_threshold: int = 3, api_url: str = "") -> Tuple[bool, float, str]:
    if not url:
        return False, 0.0, "empty-url"
    try:
        api_base = (api_url or os.environ.get("PTEROPROTECT_CHECK_API", DEFAULT_EXTERNAL_CHECK_API)).strip() or DEFAULT_EXTERNAL_CHECK_API
        separator = "&" if "?" in api_base else "?"
        req = urllib.request.Request(
            f"{api_base}{separator}{urllib.parse.urlencode({'url': url})}",
            headers={"Accept": "application/json", "User-Agent": "DanexSelfHeal/1.0 mywebcheck"},
            method="GET",
        )
        with urllib.request.urlopen(req, timeout=10) as resp:
            data = json.loads(resp.read().decode("utf-8", errors="ignore"))

        raw_results = data.get("results", [])
        results = raw_results if isinstance(raw_results, list) else []
        if max_nodes > 0:
            results = results[:max_nodes]

        latency_info = data.get("latency", {}) if isinstance(data.get("latency"), dict) else {}
        latency = as_float(latency_info.get("avg_ms", 0.0), 0.0)
        if latency <= 0:
            result_latencies = []
            for item in results:
                if isinstance(item, dict):
                    result_latencies.append(as_float(item.get("latency_ms", 0.0), 0.0))
            result_latencies = [value for value in result_latencies if value > 0]
            latency = sum(result_latencies) / len(result_latencies) if result_latencies else 0.0

        ok = bool(data.get("ok"))
        status = str(data.get("status", "unknown")).upper()
        success = as_int(data.get("success", 0), 0)
        failed = as_int(data.get("failed", 0), 0)
        tries = as_int(data.get("tries", success + failed), success + failed)

        if results:
            healthy_codes = {200, 204, 301, 302, 303, 307, 308}
            node_success = 0
            node_failed = 0
            for item in results:
                if not isinstance(item, dict):
                    continue
                code = as_int(item.get("http_status", 0), 0)
                if bool(item.get("ok")) and code in healthy_codes:
                    node_success += 1
                else:
                    node_failed += 1

            if node_success + node_failed > 0:
                success = node_success
                failed = node_failed
                tries = node_success + node_failed
                ok = node_success > 0
                status = "UP" if ok else "DOWN"

        if tries >= zero_threshold and success <= 0:
            return False, latency, f"mywebcheck status={status} success={success}/{tries} failed={failed}"

        if not ok or status != "UP" or success <= 0:
            return False, latency, f"mywebcheck status={status} success={success}/{tries} failed={failed}"

        return True, latency, f"mywebcheck status={status} success={success}/{tries} failed={failed}"
    except Exception as exc:
        return False, 0.0, f"mywebcheck-error:{exc}"


def systemd_active(name: str) -> bool:
    rc, out, _ = run(["systemctl", "is-active", name], timeout=4)
    return rc == 0 and out.strip() == "active"


def write_file(path: str, payload: str) -> None:
    try:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w", encoding="utf-8") as f:
            f.write(payload + "\n")
    except Exception as exc:
        log(f"write failed path={path}: {exc}")


def write_metrics(base_dir: str, values: Dict[str, float]) -> None:
    lines = [
        "# HELP pteroprotect_selfheal External and local health monitor metrics.",
        "# TYPE pteroprotect_selfheal gauge",
    ]
    for key, value in values.items():
        lines.append(f"pteroprotect_selfheal{{metric=\"{key}\"}} {value}")
    write_file(os.path.join(base_dir, "self_heal.prom"), "\n".join(lines))


def set_mode(mode: str) -> None:
    now = int(time.time())
    payload = json.dumps({"mode": mode, "updated_at": now}, ensure_ascii=True)
    write_file(os.path.join(SHM_RUNTIME_DIR, "mode.flag"), payload)
    write_file(os.path.join(PANEL_RUNTIME_DIR, "mode.json"), payload)


def set_lockdown(enabled: bool, ttl_sec: int = 0, reason: str = "") -> None:
    now = int(time.time())
    if enabled:
        until = now + max(30, ttl_sec)
        shm_payload = json.dumps({"enabled": True, "reason": reason, "until": until, "updated_at": now}, ensure_ascii=True)
    else:
        shm_payload = json.dumps({"enabled": False, "updated_at": now}, ensure_ascii=True)
    write_file(os.path.join(SHM_RUNTIME_DIR, "strict_lockdown.flag"), shm_payload)
    write_file(os.path.join(PANEL_RUNTIME_DIR, "lockdown.json"), shm_payload)


def read_recent_self_ddos(state_dir: str, max_age_sec: int = 30) -> bool:
    p = os.path.join(state_dir, "self_ddos_events.json")
    try:
        with open(p, "r", encoding="utf-8") as f:
            data = json.load(f)
        ts = as_int(data.get("ts", 0), 0)
        return ts > 0 and (time.time() - ts) <= max_age_sec
    except Exception:
        return False


def trigger_self_heal() -> None:
    services = ["php8.3-fpm", "nginx", "wings"]
    for svc in services:
        if systemd_active(svc):
            continue
        log(f"service {svc} not active, restarting")
        run(["systemctl", "restart", svc], timeout=12)
    run(["systemctl", "reload", "nginx"], timeout=8)


def main() -> int:
    cfg = load_config()
    runtime = cfg.get("runtime", {}) if isinstance(cfg, dict) else {}
    monitor = cfg.get("monitor", {}) if isinstance(cfg, dict) else {}
    res_cfg = cfg_resilience(cfg)

    state_dir = runtime.get("state_dir", "/pteroprotect/runtime")
    checkhost_enabled = bool(monitor.get("checkhost_enabled", True))
    checkhost_max_nodes = max(1, as_int(monitor.get("checkhost_max_nodes", 8), 8))
    checkhost_zero_node_threshold = max(1, as_int(monitor.get("checkhost_zero_node_threshold", 3), 3))
    base_url = resolve_external_url(cfg, monitor)
    challenge_path = str(monitor.get("challenge_path", DEFAULT_CHALLENGE_PATH))
    local_health_url = str(monitor.get("local_health_url", "http://127.0.0.1:18080/api/system"))

    normal_sec = max(2, as_int(monitor.get("check_interval_normal_sec", 5), 5))
    anomaly_sec = max(2, as_int(monitor.get("check_interval_anomaly_sec", 2), 2))
    lockdown_ttl_sec = max(60, as_int(monitor.get("lockdown_ttl_sec", 180), 180))

    p95_threshold_ms = as_float(monitor.get("latency_p95_ms_threshold", 10000), 10000)
    error_rate_threshold = as_float(monitor.get("error_rate_threshold", 0.5), 0.5)
    external_fail_streak_threshold = max(2, as_int(monitor.get("external_fail_streak_threshold", 3), 3))

    orchestrator_primary = as_bool(res_cfg.get("orchestrator_primary", True), True)
    events_file = str(res_cfg.get("events_file", os.path.join(PANEL_RUNTIME_DIR, "resilience_events.jsonl")))

    window = []
    external_fail_streak = 0
    mode = "normal"

    log(f"started url={base_url} checkhost_primary={checkhost_enabled} orchestrator_primary={orchestrator_primary}")

    while True:
        ts = time.time()
        external_ok = False
        external_latency = 0.0
        external_src = ""

        challenge_url = (base_url + challenge_path) if base_url else ""

        if checkhost_enabled and challenge_url:
            ok, lat, src = checkhost_probe(
                challenge_url,
                max_nodes=checkhost_max_nodes,
                zero_threshold=checkhost_zero_node_threshold,
                api_url=str(monitor.get("check_api_url", "")),
            )
            external_ok, external_latency, external_src = ok, lat, src
            if not external_ok:
                ok2, code2, lat2, _ = http_probe(local_health_url, timeout_sec=5.0)
                external_ok = ok2 and code2 in (200, 401, 403)
                external_latency = lat2
                external_src = f"local-fallback:{code2}:{src}"
        else:
            ok2, code2, lat2, _ = http_probe(local_health_url, timeout_sec=5.0)
            external_ok = ok2 and code2 in (200, 401, 403)
            external_latency = lat2
            external_src = f"local-primary:{code2}"

        challenge_ok = True
        if challenge_url:
            okc, codec, _, _ = http_probe(challenge_url, timeout_sec=6.0)
            challenge_ok = okc and codec in (200, 204, 301, 302, 303, 307, 308)

        window.append((ts, external_ok and challenge_ok, external_latency))
        window = [x for x in window if (ts - x[0]) <= 30]

        sample_count = len(window)
        ok_count = sum(1 for x in window if x[1])
        err_count = sample_count - ok_count
        error_rate = (err_count / sample_count) if sample_count else 0.0
        latencies = sorted(x[2] for x in window if x[2] > 0)
        p95 = latencies[int(0.95 * (len(latencies) - 1))] if latencies else 0.0

        if external_ok and challenge_ok:
            external_fail_streak = 0
        else:
            external_fail_streak += 1

        cond_latency = p95 > p95_threshold_ms
        cond_error = error_rate > error_rate_threshold
        cond_external = external_fail_streak >= external_fail_streak_threshold
        signals = sum(1 for x in (cond_latency, cond_error, cond_external) if x)

        self_ddos_recent = read_recent_self_ddos(state_dir, max_age_sec=30)

        # Persist a dependency snapshot for orchestration and debugging.
        dep_snapshot = {
            'ts': int(ts),
            'external_ok': bool(external_ok),
            'challenge_ok': bool(challenge_ok),
            'external_latency_ms': float(external_latency),
            'p95_ms': float(p95),
            'error_rate': float(error_rate),
            'signals': int(signals),
            'source': external_src,
        }
        write_json(os.path.join(PANEL_RUNTIME_DIR, 'self_heal_dependency.json'), dep_snapshot)

        if signals >= 2:
            trigger_self_heal()
            if not orchestrator_primary:
                if self_ddos_recent:
                    if mode != "elevated":
                        set_mode("elevated")
                        set_lockdown(False)
                        mode = "elevated"
                else:
                    set_mode("emergency")
                    set_lockdown(True, lockdown_ttl_sec, reason=f"signals={signals};src={external_src}")
                    mode = "lockdown"
        elif signals == 1:
            if not orchestrator_primary:
                if mode != "elevated":
                    set_mode("aggressive")
                    set_lockdown(False)
                    mode = "elevated"
        else:
            if not orchestrator_primary:
                if mode != "normal":
                    set_mode("normal")
                    set_lockdown(False)
                    mode = "normal"

        # Standardized resilience event schema emission.
        score = min(1.0, max(0.0, (signals / 3.0) * 0.7 + min(0.3, error_rate)))
        confidence = min(1.0, sample_count / 30.0) if sample_count > 0 else 0.0
        emit_resilience_event(
            layer='l7',
            service='self_heal_monitor',
            decision='degraded' if signals > 0 else 'healthy',
            score=score,
            confidence=confidence,
            tenant_scope='global',
            expiry=int(time.time()) + max(normal_sec, anomaly_sec) * 2,
            extra={
                'signals': signals,
                'p95_ms': round(p95, 3),
                'error_rate': round(error_rate, 6),
                'external_fail_streak': external_fail_streak,
                'source': external_src,
                'challenge_ok': challenge_ok,
                'external_ok': external_ok,
            },
            events_file=events_file,
        )

        write_metrics(PANEL_RUNTIME_DIR, {
            "signals": float(signals),
            "p95_ms": float(p95),
            "error_rate": float(error_rate),
            "external_fail_streak": float(external_fail_streak),
            "challenge_ok": 1.0 if challenge_ok else 0.0,
            "external_ok": 1.0 if external_ok else 0.0,
            "self_ddos_recent": 1.0 if self_ddos_recent else 0.0,
            "orchestrator_primary": 1.0 if orchestrator_primary else 0.0,
        })

        sleep_sec = anomaly_sec if signals >= 1 else normal_sec
        time.sleep(sleep_sec)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(0)

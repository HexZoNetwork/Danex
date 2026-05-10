#!/usr/bin/env python3
import json
import os
import subprocess
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse

CONFIG_PATH = os.environ.get('PTEROPROTECT_CONFIG_PATH', '/pteroprotect/config.json')
RUNTIME_DIR = os.environ.get('PTEROPROTECT_PANEL_RUNTIME_DIR', '/pteroprotect/runtime')
REG = {}
LOCK = threading.Lock()


def cfg():
    try:
        with open(CONFIG_PATH, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception:
        return {}


def net(k, d=None):
    return (cfg().get('network') or {}).get(k, d)


def redis_cli(*args):
    url = str(net('redis_url', '') or '').strip()
    if not url:
        return None
    cmd = ['redis-cli', '-u', url, *args]
    try:
        return subprocess.check_output(cmd, stderr=subprocess.DEVNULL, text=True).strip()
    except Exception:
        return None


def persist_node(node_id, payload):
    redis_cli('SETEX', f'node:heartbeat:{node_id}', '30', json.dumps(payload, separators=(',', ':')))


def load_nodes():
    nodes = {}
    for k, v in list(REG.items()):
        if v.get('exp', 0) > time.time():
            nodes[k] = v
    return nodes


def load_json(path, default):
    try:
        with open(path, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception:
        return default


def resilience_state():
    c = cfg()
    path = (
        (c.get('resilience', {}) or {}).get('state_file')
        if isinstance(c, dict)
        else None
    )
    if not isinstance(path, str) or not path.strip():
        path = os.path.join(RUNTIME_DIR, 'resilience_state.json')
    return load_json(path, {})


def resilience_healthz():
    st = resilience_state()
    state = st if isinstance(st, dict) else {}
    deps = state.get('dependencies', {}) if isinstance(state.get('dependencies', {}), dict) else {}
    rec = state.get('recovery', {}) if isinstance(state.get('recovery', {}), dict) else {}
    stage = str(state.get('stage', 'unknown'))
    attack_score = float(state.get('attack_score', 1.0) or 1.0)
    health_score = float(state.get('health_score', 0.0) or 0.0)

    degraded = []
    for dep, row in deps.items():
        if not isinstance(row, dict):
            continue
        score = float(row.get('score', 0.0) or 0.0)
        if score < 0.7:
            degraded.append({'dependency': dep, 'score': score})

    ok = stage in {'normal', 'elevated'} and attack_score < 0.8 and health_score >= 0.45
    return {
        'ok': bool(ok),
        'stage': stage,
        'attack_score': attack_score,
        'health_score': health_score,
        'degraded_dependencies': degraded,
        'recovery_ready': bool(rec.get('ready', False)),
        'ts': int(time.time()),
    }


class H(BaseHTTPRequestHandler):
    def log_message(self, *args):
        return

    def _resp(self, code, obj):
        b = json.dumps(obj, separators=(',', ':')).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Content-Length', str(len(b)))
        self.end_headers()
        self.wfile.write(b)

    def _read(self):
        try:
            n = int(self.headers.get('Content-Length', '0'))
        except Exception:
            n = 0
        raw = self.rfile.read(max(0, min(1024*1024, n))) if n else b'{}'
        try:
            return json.loads(raw.decode())
        except Exception:
            return {}

    def do_GET(self):
        path = urlparse(self.path).path

        if path == '/health':
            return self._resp(200, {'ok': True})

        if path == '/nodes':
            with LOCK:
                return self._resp(200, {'ok': True, 'nodes': load_nodes()})

        if path == '/resilience/healthz':
            hz = resilience_healthz()
            return self._resp(200 if hz.get('ok') else 503, hz)

        if path == '/resilience/state':
            st = resilience_state()
            return self._resp(200, {'ok': True, 'state': st})

        if path == '/resilience/recovery/readiness':
            st = resilience_state()
            if not isinstance(st, dict):
                return self._resp(503, {'ok': False, 'ready': False, 'reason': 'state_unavailable'})
            rec = st.get('recovery', {}) if isinstance(st.get('recovery', {}), dict) else {}
            ready = bool(rec.get('ready', False))
            return self._resp(200 if ready else 503, {
                'ok': True,
                'ready': ready,
                'stage': st.get('stage', 'unknown'),
                'health_score': st.get('health_score', 0),
                'attack_score': st.get('attack_score', 1),
                'stable_since': rec.get('stable_since', 0),
                'cooldown_sec': rec.get('cooldown_sec', 0),
            })

        return self._resp(404, {'ok': False, 'error': 'not_found'})

    def do_POST(self):
        path = urlparse(self.path).path
        if path != '/heartbeat':
            return self._resp(404, {'ok': False, 'error': 'not_found'})

        data = self._read()
        key = self.headers.get('X-PteroProtect-Node-Key', '')
        expect = str(net('node_auth_key', '') or '')
        if expect and key != expect:
            return self._resp(401, {'ok': False, 'error': 'unauthorized'})

        node_id = str(data.get('node_id') or '')
        if not node_id:
            return self._resp(400, {'ok': False, 'error': 'node_id_required'})

        now = int(time.time())
        payload = {
            'node_id': node_id,
            'ip': data.get('ip', ''),
            'ports': data.get('ports', []),
            'services': data.get('services', []),
            'version': data.get('version', 'unknown'),
            'resilience': data.get('resilience', {}),
            'seen_at': now,
            'exp': now + 30,
        }
        with LOCK:
            REG[node_id] = payload
        persist_node(node_id, payload)
        return self._resp(200, {'ok': True, 'server_time': now})


def main():
    host = '127.0.0.1'
    port = int(os.environ.get('PTEROPROTECT_CONTROL_PLANE_PORT', '18446'))
    srv = ThreadingHTTPServer((host, port), H)
    srv.serve_forever()


if __name__ == '__main__':
    main()

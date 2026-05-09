#!/usr/bin/env python3
import json, os, time, subprocess, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

CONFIG_PATH = os.environ.get('PTEROPROTECT_CONFIG_PATH', '/pteroprotect/config.json')
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
        if self.path == '/health':
            return self._resp(200, {'ok': True})
        if self.path == '/nodes':
            with LOCK:
                return self._resp(200, {'ok': True, 'nodes': load_nodes()})
        return self._resp(404, {'ok': False, 'error': 'not_found'})

    def do_POST(self):
        if self.path != '/heartbeat':
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

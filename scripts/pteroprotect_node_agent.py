#!/usr/bin/env python3
import json, os, socket, time, urllib.request

CONFIG_PATH = os.environ.get('PTEROPROTECT_CONFIG_PATH', '/pteroprotect/config.json')


def cfg():
    try:
        with open(CONFIG_PATH, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception:
        return {}


def net(k, d=None):
    return (cfg().get('network') or {}).get(k, d)


def post(url, headers, body):
    req = urllib.request.Request(url, data=json.dumps(body).encode(), headers=headers, method='POST')
    with urllib.request.urlopen(req, timeout=3) as r:
        return r.status


def detect_ip():
    return os.environ.get('PTEROPROTECT_NODE_IP', '127.0.0.1')


def main():
    while True:
        c = cfg()
        n = c.get('network') or {}
        cp = str(n.get('control_plane_url') or 'http://127.0.0.1:18446').rstrip('/')
        body = {
            'node_id': str(n.get('node_id') or socket.gethostname()),
            'ip': detect_ip(),
            'ports': str(n.get('public_tcp_ports') or '80,443,8080').split(','),
            'services': n.get('protected_services') or [],
            'version': 'pteroprotect-node-agent-1',
        }
        headers = {'Content-Type': 'application/json'}
        key = str(n.get('node_auth_key') or '')
        if key:
            headers['X-PteroProtect-Node-Key'] = key
        try:
            post(cp + '/heartbeat', headers, body)
        except Exception:
            pass
        time.sleep(10)


if __name__ == '__main__':
    main()

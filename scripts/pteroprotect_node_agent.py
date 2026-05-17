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
import socket
import time
import urllib.request

CONFIG_PATH = os.environ.get('PTEROPROTECT_CONFIG_PATH', '/pteroprotect/config.json')
RUNTIME_DIR = os.environ.get('PTEROPROTECT_PANEL_RUNTIME_DIR', '/pteroprotect/runtime')


def cfg():
    try:
        with open(CONFIG_PATH, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception:
        return {}


def post(url, headers, body):
    req = urllib.request.Request(url, data=json.dumps(body).encode(), headers=headers, method='POST')
    with urllib.request.urlopen(req, timeout=3) as r:
        return r.status


def detect_ip():
    return os.environ.get('PTEROPROTECT_NODE_IP', '127.0.0.1')


def read_resilience_snapshot():
    p = os.path.join(RUNTIME_DIR, 'resilience_state.json')
    try:
        with open(p, 'r', encoding='utf-8') as f:
            data = json.load(f)
        if not isinstance(data, dict):
            return {}
        return {
            'stage': data.get('stage', 'unknown'),
            'attack_score': data.get('attack_score', 0),
            'health_score': data.get('health_score', 0),
            'confidence': data.get('confidence', 0),
            'ts': data.get('ts', 0),
        }
    except Exception:
        return {}


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
            'version': 'pteroprotect-node-agent-2',
            'resilience': read_resilience_snapshot(),
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

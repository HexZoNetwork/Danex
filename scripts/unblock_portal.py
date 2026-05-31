#!/usr/bin/env python3
import json
import os
import fcntl
import ipaddress

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
import re
import secrets
import socket
import subprocess
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse


GUARD_HOME = os.environ.get("DANN_GUARD_HOME", "/pteroprotect")
CONFIG_PATH = Path(GUARD_HOME) / "config.json"
RUNTIME_LOG = Path("/dev/shm/pteroprotect/ddos_host.log")
BLACKLIST_FILE = Path("/dev/shm/pteroprotect/unblock_blacklist.json")
TOKEN_FAIL_MAX = 3
TOKEN_FAIL_WINDOW_SEC = 300
TOKEN_FAIL_BAN_SEC = 3600
_TOKEN_FAIL = {}
_TOKEN_FAIL_LOCK = threading.Lock()
REQ_RATE_MAX = 60
REQ_RATE_WINDOW_SEC = 10
REQ_RATE_BAN_SEC = 900
API_ACTION_MAX = 300
API_ACTION_WINDOW_SEC = 60
API_MAX_IPS_PER_REQ = 25
RATE_MAP_MAX_ENTRIES = 10000
_REQ_RATE = {}
_API_ACTION_RATE = {}
_LOCAL_BAN_UNTIL = {}


def read_config():
    cfg = {}
    try:
        cfg = json.loads(CONFIG_PATH.read_text())
    except Exception:
        cfg = {}
    network = cfg.get("network", {}) if isinstance(cfg, dict) else {}
    return {
        "bind": str(network.get("unblock_portal_bind", "0.0.0.0")),
        "port": int(network.get("unblock_portal_port", 18443)),
        "token": str(network.get("unblock_portal_token", "")),
    }


def today_key():
    return time.strftime("%Y-%m-%d", time.gmtime())


def run_cmd(cmd):
    try:
        out = subprocess.check_output(cmd, stderr=subprocess.DEVNULL, text=True)
        return out.strip()
    except Exception:
        return ""


def run_rc(cmd):
    try:
        return subprocess.call(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except Exception:
        return 1


def client_ip(handler):
    try:
        return str(handler.client_address[0]).strip()
    except Exception:
        return ""


def ban_ip(ip):
    if not is_ip(ip):
        return
    timeout = str(TOKEN_FAIL_BAN_SEC)
    if ":" in ip:
        run_rc(["ipset", "add", "pteroprotect_block_v6", ip, "timeout", timeout, "-exist"])
    else:
        run_rc(["ipset", "add", "pteroprotect_block_v4", ip, "timeout", timeout, "-exist"])
    status = run_cmd(["fail2ban-client", "status"])
    m = re.search(r"Jail list:\s*(.+)$", status, re.MULTILINE)
    if m:
        for jail in [x.strip() for x in m.group(1).split(",") if x.strip()]:
            run_rc(["fail2ban-client", "set", jail, "banip", ip])
    with _TOKEN_FAIL_LOCK:
        until = int(time.time()) + max(TOKEN_FAIL_BAN_SEC, REQ_RATE_BAN_SEC)
        _LOCAL_BAN_UNTIL[ip] = until
    try:
        print(f"[portal-ban] ip={ip} until={_LOCAL_BAN_UNTIL.get(ip, 0)}")
    except Exception:
        pass


def register_token_failure(ip, presented_token=""):
    if not presented_token:
        return 0
    now = int(time.time())
    with _TOKEN_FAIL_LOCK:
        prune_rate_maps_locked(now)
        data = load_blacklist_locked()
        fails = data.setdefault("fails", {})
        rec = fails.get(ip)
        if not rec or now - int(rec.get("first", 0)) > TOKEN_FAIL_WINDOW_SEC:
            rec = {"first": now, "count": 0}
        rec["count"] = int(rec.get("count", 0)) + 1
        fails[ip] = rec
        count = rec["count"]
        if count >= TOKEN_FAIL_MAX:
            data.setdefault("blacklist", {})[ip] = {"ts": now, "reason": "wrong_unblock_token_3x"}
        save_blacklist_locked(data)
    return count


def clear_token_failure(ip):
    with _TOKEN_FAIL_LOCK:
        data = load_blacklist_locked()
        changed = False
        if ip in data.get("fails", {}):
            data["fails"].pop(ip, None)
            changed = True
        if ip in data.get("blacklist", {}):
            data["blacklist"].pop(ip, None)
            changed = True
        if changed:
            save_blacklist_locked(data)


def is_locally_banned(ip):
    if not is_ip(ip):
        return False
    now = int(time.time())
    with _TOKEN_FAIL_LOCK:
        data = load_blacklist_locked()
        if ip in data.get("blacklist", {}):
            return True
        until = int(_LOCAL_BAN_UNTIL.get(ip, 0))
        if until <= now:
            _LOCAL_BAN_UNTIL.pop(ip, None)
            return False
        return True


def register_request_rate(ip):
    now = int(time.time())
    with _TOKEN_FAIL_LOCK:
        prune_rate_maps_locked(now)
        rec = _REQ_RATE.get(ip)
        if not rec or now - int(rec.get("first", 0)) > REQ_RATE_WINDOW_SEC:
            rec = {"first": now, "count": 0}
        rec["count"] = int(rec.get("count", 0)) + 1
        _REQ_RATE[ip] = rec
        count = rec["count"]
        if count > REQ_RATE_MAX:
            _LOCAL_BAN_UNTIL[ip] = now + REQ_RATE_BAN_SEC
            try:
                print(f"[portal-rate] ip={ip} count={count} window={REQ_RATE_WINDOW_SEC}s action=ban")
            except Exception:
                pass
            return False
    return True


def register_api_action(ip):
    now = int(time.time())
    with _TOKEN_FAIL_LOCK:
        prune_rate_maps_locked(now)
        rec = _API_ACTION_RATE.get(ip)
        if not rec or now - int(rec.get("first", 0)) > API_ACTION_WINDOW_SEC:
            rec = {"first": now, "count": 0}
        rec["count"] = int(rec.get("count", 0)) + 1
        _API_ACTION_RATE[ip] = rec
        return int(rec["count"]) <= API_ACTION_MAX


def load_json(path: Path):
    try:
        return json.loads(path.read_text())
    except Exception:
        return {}


def write_json(path: Path, obj):
    path.parent.mkdir(parents=True, exist_ok=True)
    data = json.dumps(obj, indent=2, sort_keys=True) + "\n"
    fd, tmp = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=str(path.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp, path)
    finally:
        try:
            os.unlink(tmp)
        except FileNotFoundError:
            pass


def load_blacklist_locked():
    data = load_json(BLACKLIST_FILE)
    if not isinstance(data, dict) or data.get("date") != today_key():
        data = {"date": today_key(), "fails": {}, "blacklist": {}}
        write_json(BLACKLIST_FILE, data)
    data.setdefault("fails", {})
    data.setdefault("blacklist", {})
    return data


def save_blacklist_locked(data):
    data["date"] = today_key()
    write_json(BLACKLIST_FILE, data)


def prune_rate_maps_locked(now=None):
    now = int(now or time.time())
    for key, rec in list(_TOKEN_FAIL.items()):
        if now - int(rec.get("first", 0)) > TOKEN_FAIL_WINDOW_SEC:
            _TOKEN_FAIL.pop(key, None)
    for key, rec in list(_REQ_RATE.items()):
        if now - int(rec.get("first", 0)) > REQ_RATE_WINDOW_SEC:
            _REQ_RATE.pop(key, None)
    for key, rec in list(_API_ACTION_RATE.items()):
        if now - int(rec.get("first", 0)) > API_ACTION_WINDOW_SEC:
            _API_ACTION_RATE.pop(key, None)
    for key, until in list(_LOCAL_BAN_UNTIL.items()):
        if int(until) <= now:
            _LOCAL_BAN_UNTIL.pop(key, None)
    for table in (_TOKEN_FAIL, _REQ_RATE, _API_ACTION_RATE, _LOCAL_BAN_UNTIL):
        overflow = len(table) - RATE_MAP_MAX_ENTRIES
        if overflow > 0:
            for key in list(table.keys())[:overflow]:
                table.pop(key, None)


def get_ipset_members(set_name):
    out = run_cmd(["ipset", "list", set_name])
    members = []
    in_members = False
    for line in out.splitlines():
        line = line.strip()
        if line == "Members:":
            in_members = True
            continue
        if not in_members or not line:
            continue
        ip = line.split()[0].strip()
        if ip:
            members.append(ip)
    return members


def get_fail2ban_banned_ips():
    ips = set()
    status = run_cmd(["fail2ban-client", "status"])
    m = re.search(r"Jail list:\s*(.+)$", status, re.MULTILINE)
    if not m:
        return []
    jails = [x.strip() for x in m.group(1).split(",") if x.strip()]
    for jail in jails:
        jail_status = run_cmd(["fail2ban-client", "status", jail])
        m2 = re.search(r"Banned IP list:\s*(.*)$", jail_status, re.MULTILINE)
        if not m2:
            continue
        for ip in m2.group(1).split():
            ip = ip.strip()
            if ip:
                ips.add(ip)
    return sorted(ips)


def read_reason_map():
    reason_map = {}
    if not RUNTIME_LOG.exists():
        return reason_map
    try:
        for line in RUNTIME_LOG.read_text(errors="ignore").splitlines()[-4000:]:
            if "[mitigate] blocked ip=" not in line:
                continue
            m = re.search(r"blocked ip=([0-9A-Fa-f:\.]+)\s+ttl=\d+\s+reason=([^\s]+)", line)
            if not m:
                continue
            reason_map[m.group(1)] = m.group(2)
    except Exception:
        return reason_map
    return reason_map


def collect_blocked_ips():
    reason_map = read_reason_map()
    allow = set(get_essential_allowlist())
    rows = []
    seen = set()
    for src, set_name in (("ipset_v4", "pteroprotect_block_v4"), ("ipset_v6", "pteroprotect_block_v6")):
        for ip in get_ipset_members(set_name):
            if ip in seen:
                continue
            seen.add(ip)
            rows.append({"ip": ip, "source": src, "reason": reason_map.get(ip, "-"), "allowlisted": allowlist_contains(allow, ip)})
    for ip in get_fail2ban_banned_ips():
        if ip in seen:
            continue
        seen.add(ip)
        rows.append({"ip": ip, "source": "fail2ban", "reason": reason_map.get(ip, "-"), "allowlisted": allowlist_contains(allow, ip)})
    rows.sort(key=lambda r: r["ip"])
    return rows


def is_ip(s):
    try:
        ipaddress.ip_address(str(s).strip())
        return True
    except Exception:
        return False


def is_ip_or_cidr(s):
    try:
        text = str(s or "").strip()
        if "/" in text:
            ipaddress.ip_network(text, strict=False)
        else:
            ipaddress.ip_address(text)
        return True
    except Exception:
        return False


def normalize_ip_or_cidr(s):
    text = str(s or "").strip()
    if not text:
        return ""
    try:
        if "/" in text:
            return str(ipaddress.ip_network(text, strict=False))
        return str(ipaddress.ip_address(text))
    except Exception:
        return ""


def is_ipv6_spec(value):
    try:
        if "/" in value:
            return ipaddress.ip_network(value, strict=False).version == 6
        return ipaddress.ip_address(value).version == 6
    except Exception:
        return ":" in value


def allowlist_contains(allowlist, ip):
    try:
        addr = ipaddress.ip_address(str(ip).strip())
    except Exception:
        return str(ip).strip() in allowlist
    for item in allowlist:
        try:
            text = str(item).strip()
            if "/" in text:
                if addr in ipaddress.ip_network(text, strict=False):
                    return True
            elif addr == ipaddress.ip_address(text):
                return True
        except Exception:
            continue
    return False


def unblock_ip(ip):
    ip = normalize_ip_or_cidr(ip)
    if not is_ip_or_cidr(ip):
        return {"ok": False, "error": "invalid_ip"}
    removed_v4 = subprocess.call(["ipset", "del", "pteroprotect_block_v4", ip], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL) == 0
    removed_v6 = subprocess.call(["ipset", "del", "pteroprotect_block_v6", ip], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL) == 0
    removed_f2b = False
    if is_ip(ip):
        status = run_cmd(["fail2ban-client", "status"])
        m = re.search(r"Jail list:\s*(.+)$", status, re.MULTILINE)
        if m:
            jails = [x.strip() for x in m.group(1).split(",") if x.strip()]
            for jail in jails:
                rc = subprocess.call(["fail2ban-client", "set", jail, "unbanip", ip], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                if rc == 0:
                    removed_f2b = True
    return {"ok": True, "removed_v4": removed_v4, "removed_v6": removed_v6, "removed_f2b": removed_f2b}


def get_essential_allowlist():
    cfg = load_json(CONFIG_PATH)
    network = cfg.get("network", {}) if isinstance(cfg, dict) else {}
    v = network.get("essential_allowlist", [])
    if isinstance(v, list):
        return [str(x).strip() for x in v if str(x).strip()]
    if isinstance(v, str):
        return [x.strip() for x in v.split(",") if x.strip()]
    return []


def config_lock_file():
    CONFIG_PATH.parent.mkdir(parents=True, exist_ok=True)
    return open(CONFIG_PATH.with_suffix(CONFIG_PATH.suffix + ".lock"), "a+")


def add_essential_allowlist(ips):
    with config_lock_file() as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        cfg = load_json(CONFIG_PATH)
        if not isinstance(cfg, dict):
            cfg = {}
        network = cfg.get("network")
        if not isinstance(network, dict):
            network = {}
            cfg["network"] = network
        v = network.get("essential_allowlist", [])
        if isinstance(v, list):
            cur = [str(x).strip() for x in v if str(x).strip()]
        elif isinstance(v, str):
            cur = [x.strip() for x in v.split(",") if x.strip()]
        else:
            cur = []
        seen = {x: 1 for x in cur}
        changed = 0
        for ip in ips:
            spec = normalize_ip_or_cidr(ip)
            if is_ip_or_cidr(spec) and spec not in seen:
                cur.append(spec)
                seen[spec] = 1
                changed += 1
        network["essential_allowlist"] = cur
        write_json(CONFIG_PATH, cfg)
    for ip in ips:
        spec = normalize_ip_or_cidr(ip)
        if is_ip_or_cidr(spec):
            apply_runtime_allow_rule(spec)
    return changed


def apply_runtime_allow_rule(ip):
    spec = normalize_ip_or_cidr(ip)
    if not spec:
        return
    if is_ipv6_spec(spec):
        chains = ["PTEROPROTECT-DYNBLOCK-V6", "PTEROPROTECT-HOST-V6", "PTEROPROTECT-HOST-V6-BW"]
        src = spec if "/" in spec else f"{spec}/128"
        for ch in chains:
            if run_rc(["ip6tables", "-S", ch]) != 0:
                continue
            if run_rc(["ip6tables", "-C", ch, "-s", src, "-j", "RETURN"]) != 0:
                run_rc(["ip6tables", "-I", ch, "1", "-s", src, "-j", "RETURN"])
    else:
        chains = ["PTEROPROTECT-DYNBLOCK", "PTEROPROTECT-HOST", "PTEROPROTECT-HOST-BW"]
        src = spec if "/" in spec else f"{spec}/32"
        for ch in chains:
            if run_rc(["iptables", "-S", ch]) != 0:
                continue
            if run_rc(["iptables", "-C", ch, "-s", src, "-j", "RETURN"]) != 0:
                run_rc(["iptables", "-I", ch, "1", "-s", src, "-j", "RETURN"])


def remove_runtime_allow_rule(ip):
    spec = normalize_ip_or_cidr(ip)
    if not spec:
        return
    if is_ipv6_spec(spec):
        chains = ["PTEROPROTECT-DYNBLOCK-V6", "PTEROPROTECT-HOST-V6", "PTEROPROTECT-HOST-V6-BW"]
        src = spec if "/" in spec else f"{spec}/128"
        for ch in chains:
            if run_rc(["ip6tables", "-S", ch]) != 0:
                continue
            while run_rc(["ip6tables", "-C", ch, "-s", src, "-j", "RETURN"]) == 0:
                run_rc(["ip6tables", "-D", ch, "-s", src, "-j", "RETURN"])
    else:
        chains = ["PTEROPROTECT-DYNBLOCK", "PTEROPROTECT-HOST", "PTEROPROTECT-HOST-BW"]
        src = spec if "/" in spec else f"{spec}/32"
        for ch in chains:
            if run_rc(["iptables", "-S", ch]) != 0:
                continue
            while run_rc(["iptables", "-C", ch, "-s", src, "-j", "RETURN"]) == 0:
                run_rc(["iptables", "-D", ch, "-s", src, "-j", "RETURN"])


HTML_PAGE = """<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>PteroProtect Unblock Portal</title>
  <style>
    body { font-family: ui-sans-serif, system-ui; margin: 20px; background: linear-gradient(180deg,#08101d,#0e1b30); color: #e7edf8; }
    .card { max-width: 1080px; margin: 0 auto; background: #111a2b; border: 1px solid #1e2a43; border-radius: 12px; padding: 16px; box-shadow: 0 10px 30px rgba(0,0,0,.35); }
    input, button { font-size: 14px; padding: 8px 10px; border-radius: 8px; border: 1px solid #2d3d5e; background: #0d1424; color: #e7edf8; }
    button { cursor: pointer; background: #1453ff; border-color: #1453ff; }
    button.alt { background: #23314f; border-color: #23314f; }
    button.warn { background:#8f2e00; border-color:#8f2e00; }
    .ok { color:#8bf4be; }
    .bad { color:#ff9b9b; }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    th, td { padding: 8px; border-bottom: 1px solid #1e2a43; text-align: left; font-size: 13px; }
    .row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .muted { color: #8ea3c7; font-size: 12px; }
  </style>
</head>
<body>
  <div class="card">
    <div class="row">
      <button onclick="loadList()">Refresh List</button>
      <button class="alt" onclick="selectAll(true)">Select All</button>
      <button class="alt" onclick="selectAll(false)">Clear</button>
      <label class="muted"><input type="checkbox" id="also_allow"> add to allowlist</label>
      <button onclick="unblockSelected()">Unblock Selected</button>
      <button class="warn" onclick="removeAllowSelected()">Remove Allowlist</button>
    </div>
    <div class="muted" id="stat">Load blocked list...</div>
    <table>
      <thead><tr><th></th><th>IP</th><th>Source</th><th>Reason</th><th>Allowlisted</th></tr></thead>
      <tbody id="rows"></tbody>
    </table>
  </div>
<script>
let cache = [];
const Q_TOKEN = new URLSearchParams(window.location.search).get("token") || "";
function headers() {
  return { "X-Admin-Token": Q_TOKEN };
}
function esc(s){ return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
async function loadList(){
  const r = await fetch('/api/list?token=' + encodeURIComponent(Q_TOKEN), { headers: headers() });
  if(!r.ok){ document.getElementById('stat').textContent = 'Auth failed / error'; return; }
  const d = await r.json();
  cache = d.items || [];
  const body = document.getElementById('rows');
  body.innerHTML = cache.map(it => `<tr><td><input type="checkbox" data-ip="${esc(it.ip)}"></td><td>${esc(it.ip)}</td><td>${esc(it.source)}</td><td>${esc(it.reason||'-')}</td><td>${it.allowlisted ? '<span class="ok">yes</span>' : '-'}</td></tr>`).join('');
  document.getElementById('stat').textContent = `Blocked IP total: ${cache.length}`;
}
function selectAll(v){
  document.querySelectorAll('#rows input[type=checkbox]').forEach(el => el.checked = v);
}
async function unblockSelected(){
  const ips = [...document.querySelectorAll('#rows input[type=checkbox]:checked')].map(x => x.dataset.ip);
  if(!ips.length){ return; }
  const alsoAllow = !!document.getElementById('also_allow').checked;
  const MAX_PER_REQ = 25;
  let totalUnblocked = 0;
  let totalAllowAdded = 0;
  const fetchWithTimeout = async (url, opts, timeoutMs) => {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), timeoutMs);
    try {
      return await fetch(url, { ...opts, signal: ctrl.signal });
    } finally {
      clearTimeout(timer);
    }
  };
  for (let i = 0; i < ips.length; i += MAX_PER_REQ) {
    const chunk = ips.slice(i, i + MAX_PER_REQ);
    document.getElementById('stat').textContent = `Unblocking ${Math.min(i + chunk.length, ips.length)}/${ips.length}...`;
    let r;
    try {
      r = await fetchWithTimeout('/api/unblock?token=' + encodeURIComponent(Q_TOKEN), { method:'POST', headers: { ...headers(), 'Content-Type':'application/json' }, body: JSON.stringify({ ips: chunk, allowlist: alsoAllow }) }, 15000);
    } catch(err) {
      document.getElementById('stat').textContent = 'Unblock failed: request timeout, retry.';
      return;
    }
    if(!r.ok){
      let err = `HTTP ${r.status}`;
      try { const e = await r.json(); if (e && e.error) err = e.error; } catch(_) {}
      document.getElementById('stat').textContent = `Unblock failed: ${err}`;
      return;
    }
    const d = await r.json();
    totalUnblocked += Number(d.unblocked || 0);
    totalAllowAdded += Number(d.allowlisted_added || 0);
  }
  document.getElementById('stat').textContent = `Unblocked: ${totalUnblocked} | allowlist added: ${totalAllowAdded}`;
  await loadList();
}
async function removeAllowSelected(){
  const ips = [...document.querySelectorAll('#rows input[type=checkbox]:checked')].map(x => x.dataset.ip);
  if(!ips.length){ return; }
  const r = await fetch('/api/allowlist/remove?token=' + encodeURIComponent(Q_TOKEN), { method:'POST', headers: { ...headers(), 'Content-Type':'application/json' }, body: JSON.stringify({ ips }) });
  if(!r.ok){ document.getElementById('stat').textContent = 'Remove allowlist failed'; return; }
  const d = await r.json();
  document.getElementById('stat').textContent = `Allowlist removed: ${d.removed || 0}`;
  await loadList();
}
loadList();
</script>
</body>
</html>"""


class Handler(BaseHTTPRequestHandler):
    server_version = "PteroProtectUnblock/1.0"

    def _send_json(self, obj, code=200):
        data = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def _send_html(self, html, code=200):
        data = html.encode()
        self.send_response(code)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def _query_token(self):
        parsed = urlparse(self.path)
        try:
            qp = parse_qs(parsed.query, keep_blank_values=True)
            values = qp.get("token", [])
            if values:
                return str(values[0])
        except Exception:
            pass
        return ""

    def _silent_drop(self, delay_sec=0):
        try:
            if delay_sec > 0:
                time.sleep(min(delay_sec, 1))
            try:
                self.send_response(403)
                self.send_header("Cache-Control", "no-store")
                self.send_header("Content-Length", "0")
                self.end_headers()
            except Exception:
                pass
            try:
                self.connection.shutdown(socket.SHUT_RDWR)
            except Exception:
                pass
            self.connection.close()
        except Exception:
            pass

    def _authorized(self):
        token = self.server.admin_token
        if not token:
            return False
        ip = client_ip(self)
        q_token = self._query_token()
        if q_token and secrets.compare_digest(q_token, token):
            clear_token_failure(ip)
            return True
        if is_locally_banned(ip):
            return False
        if is_ip(ip) and not register_request_rate(ip):
            ban_ip(ip)
            return False
        if is_ip(ip):
            register_token_failure(ip, q_token)
        return False

    def do_GET(self):
        parsed = urlparse(self.path)
        p = parsed.path
        if p == "/":
            if not self._authorized():
                self._silent_drop()
                return
            self._send_html(HTML_PAGE)
            return
        if p == "/api/list":
            if not self._authorized():
                self._silent_drop()
                return
            self._send_json({"items": collect_blocked_ips()})
            return
        self._send_json({"error": "not_found"}, 404)

    def do_POST(self):
        p = urlparse(self.path).path
        if p not in ("/api/unblock", "/api/allowlist/remove"):
            self._send_json({"error": "not_found"}, 404)
            return
        ip = client_ip(self)
        if not self._authorized():
            self._silent_drop()
            return
        if is_ip(ip) and not register_api_action(ip):
            self._send_json({"error": "rate_limited", "retry_after_sec": API_ACTION_WINDOW_SEC}, 429)
            return
        try:
            ln = int(self.headers.get("Content-Length", "0"))
            body = self.rfile.read(ln).decode() if ln > 0 else "{}"
            data = json.loads(body)
        except Exception:
            self._send_json({"error": "bad_json"}, 400)
            return
        ips = data.get("ips", [])
        if not isinstance(ips, list):
            self._send_json({"error": "bad_payload"}, 400)
            return
        if len(ips) == 0:
            self._send_json({"error": "empty_ips"}, 400)
            return
        if len(ips) > API_MAX_IPS_PER_REQ:
            self._send_json({"error": "too_many_ips", "max_ips": API_MAX_IPS_PER_REQ}, 429)
            return
        if p == "/api/allowlist/remove":
            removed = remove_essential_allowlist([ip.strip() for ip in ips if isinstance(ip, str)])
            self._send_json({"removed": removed})
            return

        allowlist = bool(data.get("allowlist", False))
        ok = 0
        details = []
        for ip in ips:
            if isinstance(ip, str):
                ip = ip.strip()
                res = unblock_ip(ip)
                if res.get("ok"):
                    ok += 1
                details.append({"ip": ip, **res})
        added = 0
        if allowlist:
            added = add_essential_allowlist([d["ip"] for d in details if d.get("ok")])
        self._send_json({"unblocked": ok, "allowlisted_added": added, "items": details})

    def log_message(self, fmt, *args):
        return


def remove_essential_allowlist(ips):
    cfg = load_json(CONFIG_PATH)
    if not isinstance(cfg, dict):
        return 0
    network = cfg.get("network")
    if not isinstance(network, dict):
        return 0
    cur = get_essential_allowlist()
    cur_set = set(cur)
    removed = 0
    for ip in ips:
        spec = normalize_ip_or_cidr(ip)
        if spec in cur_set:
            cur_set.remove(spec)
            removed += 1
    network["essential_allowlist"] = sorted(cur_set)
    write_json(CONFIG_PATH, cfg)
    for ip in ips:
        spec = normalize_ip_or_cidr(ip)
        if is_ip_or_cidr(spec):
            remove_runtime_allow_rule(spec)
    return removed

def main():
    cfg = read_config()
    token = cfg["token"].strip()
    if not token:
        print("[unblock-portal] missing network.unblock_portal_token in config.json")
        raise SystemExit(1)
    srv = ThreadingHTTPServer((cfg["bind"], cfg["port"]), Handler)
    srv.admin_token = token
    print(f"[unblock-portal] listening on {cfg['bind']}:{cfg['port']}")
    srv.serve_forever()


if __name__ == "__main__":
    main()

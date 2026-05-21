#!/usr/bin/env python3
import base64
import concurrent.futures
import hashlib
import fcntl
import errno
import hmac
import html
import ipaddress
import json
import os
import pty
import selectors
import secrets
import select
import signal
import subprocess
import struct
import tempfile
import termios
import threading
import time
from http import cookies
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

GUARD_HOME = os.environ.get("DANN_GUARD_HOME", "/pteroprotect")
CONFIG_PATH = Path(GUARD_HOME) / "config.json"
AUDIT_LOG = Path("/var/log/pteroprotect/emergency_audit.jsonl")
BLACKLIST_FILE = Path("/dev/shm/pteroprotect/emergency_blacklist.json")
ADMINCTL = "/usr/local/bin/pteroprotect-adminctl"
SESSION_TTL_DEFAULT = 900
MAX_BODY = 16384
FAIL_MAX = 3
FAIL_WINDOW = 300
ACTION_MAX = 60
ACTION_WINDOW = 60
WS_GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"
WS_MAX_FRAME = 65536
PTY_IDLE_TIMEOUT = 900
_LOCK = threading.Lock()
_FAILS = {}
_ACTIONS = {}

SERVICES = [
    "nginx", "pteroq", "wings", "fail2ban", "docker",
    "pteroprotect", "pteroprotect-hostguard", "pteroprotect-ddoslog",
    "pteroprotect-unblock-portal", "pteroprotect-emergency-panel", "pteroprotect-challenge",
    "pteroprotect-panel-sync", "pteroprotect-selfheal", "pteroprotect-abuse-guard",
    "pteroprotect-log-watch", "pteroprotect-resilience", "pteroprotect-resilience-collector",
]
SERVICE_ACTIONS = {"status", "is-active", "start", "restart", "reload"}
MODES = {"normal", "aggressive", "emergency", "lockdown", "clear-lockdown"}
FW_ACTIONS = {"allow", "ban", "unban"}
DOCKER_ACTIONS = {"ps", "stats", "restart", "start", "stop"}
PROTECT_STACK = [
    "pteroprotect", "pteroprotect-hostguard", "pteroprotect-ddoslog", "pteroprotect-challenge",
    "pteroprotect-panel-sync", "pteroprotect-selfheal", "pteroprotect-abuse-guard",
    "pteroprotect-log-watch", "pteroprotect-resilience", "pteroprotect-resilience-collector",
]


def now():
    return int(time.time())


def today_key():
    return time.strftime("%Y-%m-%d", time.gmtime())


def load_json(path: Path):
    try:
        return json.loads(path.read_text())
    except Exception:
        return {}


def write_json_atomic(path: Path, obj):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=str(path.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            json.dump(obj, f, separators=(",", ":"), sort_keys=True)
            f.write("\n")
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
        write_json_atomic(BLACKLIST_FILE, data)
    data.setdefault("fails", {})
    data.setdefault("blacklist", {})
    return data


def save_blacklist_locked(data):
    data["date"] = today_key()
    write_json_atomic(BLACKLIST_FILE, data)


def read_config():
    cfg = load_json(CONFIG_PATH)
    net = cfg.get("network", {}) if isinstance(cfg, dict) else {}
    return {
        "bind": str(net.get("emergency_control_bind", "0.0.0.0")),
        "port": int(net.get("emergency_control_port", 18447)),
        "token": str(net.get("emergency_control_token", "")),
        "session_ttl": int(net.get("emergency_control_session_ttl_sec", SESSION_TTL_DEFAULT) or SESSION_TTL_DEFAULT),
        "query_bootstrap": bool(net.get("emergency_control_query_bootstrap_enabled", True)),
    }


def run(cmd, timeout=12):
    try:
        p = subprocess.run(cmd, text=True, capture_output=True, timeout=timeout)
        return {"exit": p.returncode, "output": (p.stdout + p.stderr).strip()[:12000]}
    except subprocess.TimeoutExpired:
        return {"exit": 124, "output": "timeout"}
    except Exception as e:
        return {"exit": 1, "output": str(e)}


def adminctl(*args, timeout=20):
    return run([ADMINCTL, *[str(a) for a in args]], timeout=timeout)


def client_ip(handler):
    try:
        return str(handler.client_address[0])
    except Exception:
        return ""


def valid_ip_or_cidr(value):
    try:
        if "/" in value:
            ipaddress.ip_network(value, strict=False)
        else:
            ipaddress.ip_address(value)
        return True
    except ValueError:
        return False


def compact_status(service):
    if "*" in service:
        return {"service": service, "state": "pattern", "detail": "status unavailable for wildcard"}
    r = adminctl("service-is-active", service, timeout=5)
    state = (r.get("output") or "unknown").splitlines()[0].strip() if r.get("output") else "unknown"
    return {"service": service, "state": state, "exit": r.get("exit", 1)}


def audit(ip, action, target, result, extra=None):
    AUDIT_LOG.parent.mkdir(parents=True, exist_ok=True)
    row = {
        "ts": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "ip": ip,
        "action": action,
        "target": target,
        "result": result,
        "extra": extra or {},
    }
    with AUDIT_LOG.open("a", encoding="utf-8") as f:
        fcntl.flock(f.fileno(), fcntl.LOCK_EX)
        f.write(json.dumps(row, separators=(",", ":")) + "\n")


def recent_audit(limit=80):
    try:
        lines = AUDIT_LOG.read_text(errors="ignore").splitlines()[-limit:]
        return [json.loads(x) for x in lines if x.strip()]
    except Exception:
        return []


def sign_session(token, sid, exp):
    key = token.encode()
    msg = f"{sid}.{exp}".encode()
    return hmac.new(key, msg, "sha256").hexdigest()


def make_cookie(token, ttl):
    sid = secrets.token_urlsafe(24)
    csrf = secrets.token_urlsafe(24)
    exp = now() + max(60, min(3600, ttl))
    sig = sign_session(token, sid, exp)
    raw = json.dumps({"sid": sid, "csrf": csrf, "exp": exp, "sig": sig}, separators=(",", ":"))
    return base64.urlsafe_b64encode(raw.encode()).decode().rstrip("="), csrf


def parse_session(raw, token):
    if not raw:
        return None
    try:
        raw += "=" * (-len(raw) % 4)
        data = json.loads(base64.urlsafe_b64decode(raw.encode()).decode())
        sid = str(data.get("sid", ""))
        csrf = str(data.get("csrf", ""))
        exp = int(data.get("exp", 0))
        sig = str(data.get("sig", ""))
        if exp <= now() or not sid or not csrf:
            return None
        if not hmac.compare_digest(sig, sign_session(token, sid, exp)):
            return None
        return {"sid": sid, "csrf": csrf, "exp": exp}
    except Exception:
        return None


def is_banned(ip):
    with _LOCK:
        data = load_blacklist_locked()
        return ip in data.get("blacklist", {})


def fail_auth(ip, presented_token=""):
    if not presented_token:
        return
    t = now()
    with _LOCK:
        data = load_blacklist_locked()
        fails = data.setdefault("fails", {})
        rec = fails.get(ip, {"first": t, "count": 0})
        if t - int(rec.get("first", 0)) > FAIL_WINDOW:
            rec = {"first": t, "count": 0}
        rec["count"] += 1
        fails[ip] = rec
        if rec["count"] >= FAIL_MAX:
            data.setdefault("blacklist", {})[ip] = {"ts": t, "reason": "wrong_emergency_token_3x"}
        save_blacklist_locked(data)


def clear_auth_fail(ip):
    with _LOCK:
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


def action_allowed(ip):
    t = now()
    with _LOCK:
        rec = _ACTIONS.get(ip, {"first": t, "count": 0})
        if t - int(rec.get("first", 0)) > ACTION_WINDOW:
            rec = {"first": t, "count": 0}
        rec["count"] += 1
        _ACTIONS[ip] = rec
        return rec["count"] <= ACTION_MAX


def valid_ws_key(key):
    try:
        return len(base64.b64decode(key, validate=True)) == 16
    except Exception:
        return False


def accept_ws(conn, key):
    accept = base64.b64encode(hashlib.sha1((key + WS_GUID).encode()).digest()).decode()
    conn.sendall((
        "HTTP/1.1 101 Switching Protocols\r\n"
        "Upgrade: websocket\r\n"
        "Connection: Upgrade\r\n"
        f"Sec-WebSocket-Accept: {accept}\r\n\r\n"
    ).encode())


def read_exact(conn, size):
    data = b""
    while len(data) < size:
        try:
            chunk = conn.recv(size - len(data))
        except BlockingIOError:
            readable, _, _ = select.select([conn], [], [], 5)
            if not readable:
                return None
            continue
        if not chunk:
            return None
        data += chunk
    return data


def ws_recv(conn):
    first = read_exact(conn, 2)
    if first is None:
        return None
    opcode = first[0] & 0x0F
    if opcode not in {1, 2, 8, 9, 10} or not (first[1] & 0x80):
        return None
    size = first[1] & 0x7F
    if size == 126:
        raw = read_exact(conn, 2)
        if raw is None:
            return None
        size = struct.unpack("!H", raw)[0]
    elif size == 127:
        raw = read_exact(conn, 8)
        if raw is None:
            return None
        size = struct.unpack("!Q", raw)[0]
    if size > WS_MAX_FRAME:
        return None
    mask = read_exact(conn, 4)
    payload = read_exact(conn, size)
    if mask is None or payload is None:
        return None
    return opcode, bytes(b ^ mask[i % 4] for i, b in enumerate(payload))


def ws_send(conn, payload, opcode=2):
    header = bytearray([0x80 | opcode])
    size = len(payload)
    if size < 126:
        header.append(size)
    elif size < 65536:
        header.extend([126, (size >> 8) & 0xFF, size & 0xFF])
    else:
        header.append(127)
        header.extend(struct.pack("!Q", size))
    conn.sendall(bytes(header) + payload)


def pty_resize(fd, cols, rows):
    cols = max(20, min(300, int(cols)))
    rows = max(5, min(120, int(rows)))
    fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack("HHHH", rows, cols, 0, 0))


HTML = """<!doctype html><html><head><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1"><title>PteroProtect Emergency</title><style>
:root{--bg:#050509;--panel:#0b0b10;--panel2:#111117;--line:rgba(139,92,246,.32);--soft:rgba(139,92,246,.16);--txt:#f7f7fb;--mut:#a3a3b2;--ok:#10b981;--bad:#ef4444;--warn:#f59e0b;--vio:#8b5cf6}*{box-sizing:border-box}body{font-family:Inter,ui-sans-serif,system-ui;background:radial-gradient(circle at 10% 0,rgba(139,92,246,.18),transparent 28rem),radial-gradient(circle at 90% 10%,rgba(6,182,212,.10),transparent 26rem),var(--bg);color:var(--txt);margin:0;padding:20px}.wrap{max-width:1320px;margin:auto}.hero{border:1px solid var(--line);background:linear-gradient(135deg,rgba(139,92,246,.16),rgba(17,17,23,.92));border-radius:22px;padding:20px;box-shadow:0 24px 70px rgba(0,0,0,.55)}h1{margin:0;font-size:28px}.card{background:rgba(11,11,16,.96);border:1px solid var(--soft);border-radius:16px;padding:16px;margin:12px 0;box-shadow:0 18px 48px rgba(0,0,0,.42)}input,select,button{background:var(--panel2);color:var(--txt);border:1px solid var(--line);border-radius:10px;padding:9px 11px}button{background:linear-gradient(135deg,#8b5cf6,#5b21b6);cursor:pointer;font-weight:700}button.alt{background:#15151d}button.warn{background:linear-gradient(135deg,#ef4444,#991b1b)}button.good{background:linear-gradient(135deg,#10b981,#047857)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.muted{color:var(--mut);font-size:12px}.pill{display:inline-flex;border:1px solid var(--line);border-radius:999px;padding:4px 8px;color:#ddd6fe;font-size:12px}pre{white-space:pre-wrap;max-height:360px;overflow:auto;background:#07070b;padding:10px;border-radius:8px}.ok{color:var(--ok)}.bad{color:var(--bad)}.warntext{color:var(--warn)}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid var(--soft);padding:8px;text-align:left;font-size:13px}@media(max-width:720px){body{padding:10px}.hero{padding:14px}h1{font-size:22px}}
</style></head><body><div class=wrap><div class=hero><div class=row><h1>PteroProtect Emergency Control</h1><span class=pill>public token mode</span><span class=pill>No arbitrary shell</span></div><p class=muted>Open with <code>?token=...</code>. Wrong or missing token returns an empty response/drop like unblock portal.</p><div class=row><button onclick=loadAll()>Refresh Health</button><button class=good onclick=recover('failed')>Restart Failed Services</button><button class=good onclick=recover('protect-stack')>Restart Protect Stack</button><button class=alt onclick="dockerQuick('ps')">Docker PS</button><button class=alt onclick="dockerQuick('stats')">Docker Stats</button><button class=warn onclick=reboot()>Reboot VPS</button><span id=stat class=muted></span></div></div><div class=grid><div class=card><h3>Protection Mode</h3><div class=row><select id=mode><option>normal</option><option>aggressive</option><option>emergency</option><option>lockdown</option><option>clear-lockdown</option></select><input id=ttl type=number value=600 min=60 max=86400><button onclick=setMode()>Apply</button></div><pre id=modeout></pre></div><div class=card><h3>Firewall IP/CIDR</h3><div class=row><select id=fwa><option>ban</option><option>unban</option><option>allow</option></select><input id=fwv placeholder="IP or CIDR"><input id=fwttl type=number value=3600><button onclick=fw()>Run</button></div><p class=muted>Ban TTL 0 = permanent.</p><pre id=fwout></pre></div><div class=card><h3>Service Control</h3><div class=row><select id=svc></select><select id=svca><option>status</option><option>is-active</option><option>start</option><option>restart</option><option>reload</option></select><button onclick=serviceRun()>Run</button></div><pre id=svcout></pre></div><div class=card><h3>Docker / Containers</h3><div class=row><select id=docka><option>ps</option><option>stats</option><option>restart</option><option>start</option><option>stop</option></select><input id=dockt placeholder="container name/id"><button onclick=dockerRun()>Run</button></div><pre id=dockout></pre></div></div><div class=card><h3>Service Health</h3><table><thead><tr><th>Service</th><th>State</th><th>Quick</th></tr></thead><tbody id=services></tbody></table></div><div class=card><h3>Audit</h3><pre id=audit></pre></div></div><script>
const CSRF='__CSRF__'; if(location.search){history.replaceState(null,'',location.pathname)} const services=__SERVICES__; document.getElementById('svc').innerHTML=services.map(s=>`<option>${s}</option>`).join('');
const rootBtn=document.createElement('button');rootBtn.className='warn';rootBtn.textContent='Root Terminal';rootBtn.onclick=()=>startTerminal();document.querySelector('.hero .row')?.insertBefore(rootBtn,document.getElementById('stat'));
async function req(p,o={}){o.headers=Object.assign({'Content-Type':'application/json','X-CSRF-Token':CSRF},o.headers||{});let r=await fetch(p,Object.assign({credentials:'same-origin'},o));let txt=await r.text();let d=txt?JSON.parse(txt):{error:'empty_response'};if(!r.ok)throw d;return d}
async function loadAll(){try{let d=await req('/api/status');document.getElementById('services').innerHTML=d.services.map(s=>`<tr><td>${esc(s.service)}</td><td class="${s.state==='active'?'ok':(s.state==='failed'?'bad':'warntext')}">${esc(s.state)}</td><td><button class=alt onclick="quickService('${esc(s.service)}','restart')">restart</button></td></tr>`).join('');document.getElementById('audit').textContent=JSON.stringify(d.audit,null,2);document.getElementById('stat').textContent='updated '+new Date().toLocaleTimeString()}catch(e){document.getElementById('stat').textContent=e.error||'empty/error'}}
async function serviceRun(){let out=document.getElementById('svcout');try{out.textContent=JSON.stringify(await req('/api/service',{method:'POST',body:JSON.stringify({service:svc.value,action:svca.value})}),null,2);loadAll()}catch(e){out.textContent=JSON.stringify(e,null,2)}}
async function quickService(service,action){document.getElementById('svc').value=service;document.getElementById('svca').value=action;await serviceRun()}
async function setMode(){let out=document.getElementById('modeout');try{out.textContent=JSON.stringify(await req('/api/mode',{method:'POST',body:JSON.stringify({mode:mode.value,ttl:Number(ttl.value)})}),null,2);loadAll()}catch(e){out.textContent=JSON.stringify(e,null,2)}}
async function fw(){let out=document.getElementById('fwout');try{out.textContent=JSON.stringify(await req('/api/firewall',{method:'POST',body:JSON.stringify({action:fwa.value,value:fwv.value,ttl:Number(fwttl.value)})}),null,2);loadAll()}catch(e){out.textContent=JSON.stringify(e,null,2)}}
async function dockerRun(){let out=document.getElementById('dockout');try{out.textContent=JSON.stringify(await req('/api/docker',{method:'POST',body:JSON.stringify({action:docka.value,target:dockt.value})}),null,2)}catch(e){out.textContent=JSON.stringify(e,null,2)}}
async function dockerQuick(action){docka.value=action;dockt.value='';await dockerRun()}
async function recover(target){if(!confirm('Run recovery: '+target+' ?'))return;try{let d=await req('/api/recover',{method:'POST',body:JSON.stringify({target})});document.getElementById('svcout').textContent=JSON.stringify(d,null,2);loadAll()}catch(e){document.getElementById('svcout').textContent=JSON.stringify(e,null,2)}}
async function reboot(){if(prompt('Type REBOOT to reboot VPS')!=='REBOOT')return;try{alert(JSON.stringify(await req('/api/reboot',{method:'POST',body:JSON.stringify({confirm:'REBOOT'})})))}catch(e){alert(JSON.stringify(e))}}
async function loadScript(src){return new Promise((res,rej)=>{let s=document.createElement('script');s.src=src;s.onload=res;s.onerror=rej;document.head.appendChild(s)})}
async function startTerminal(){if(prompt('Start full emergency root terminal. Type ROOT to continue.')!=='ROOT')return;if(!window.Terminal){let l=document.createElement('link');l.rel='stylesheet';l.href='https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.min.css';document.head.appendChild(l);await loadScript('https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.min.js')}let ov=document.createElement('div');ov.style.cssText='position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.92);padding:16px;display:flex;flex-direction:column;gap:8px';ov.innerHTML='<div class=row><strong>Emergency Root Terminal</strong><button id=termClose class=alt>Close</button><span id=termStatus class=muted>connecting</span></div><div id=term style="flex:1;border:1px solid rgba(239,68,68,.45);background:#07070b;border-radius:8px;padding:6px"></div>';document.body.appendChild(ov);let term=new window.Terminal({cursorBlink:true,convertEol:true,fontSize:13,theme:{background:'#07070b',foreground:'#f7f7fb'}});term.open(document.getElementById('term'));term.focus();let proto=location.protocol==='https:'?'wss://':'ws://';let ws=new WebSocket(proto+location.host+'/api/terminal/ws');ws.binaryType='arraybuffer';document.getElementById('termClose').onclick=()=>{try{ws.close()}catch(e){}ov.remove()};ws.onopen=()=>{document.getElementById('termStatus').textContent='connected';term.write('\r\n[PteroProtect Emergency] root terminal connected\r\n');ws.send(JSON.stringify({type:'resize',cols:term.cols||120,rows:term.rows||32}))};ws.onmessage=e=>{if(e.data instanceof ArrayBuffer)term.write(new Uint8Array(e.data));else term.write(String(e.data))};ws.onclose=()=>{document.getElementById('termStatus').textContent='disconnected';term.write('\r\n[disconnected]\r\n')};ws.onerror=()=>{document.getElementById('termStatus').textContent='websocket error'};term.onData(d=>{if(ws.readyState===WebSocket.OPEN)ws.send(d)});window.addEventListener('resize',()=>{if(ws.readyState===WebSocket.OPEN)ws.send(JSON.stringify({type:'resize',cols:term.cols||120,rows:term.rows||32}))},{passive:true})}
function esc(s){return String(s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}
</script></body></html>""".replace("__SERVICES__", json.dumps(SERVICES)).replace("<select id=svc></select>", "<select id=svc>__SERVICE_OPTIONS__</select>").replace("<tbody id=services></tbody>", "<tbody id=services>__SERVICE_ROWS__</tbody>").replace("<pre id=audit></pre>", "<pre id=audit>__AUDIT__</pre>")


def render_html(csrf):
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        statuses = list(pool.map(compact_status, SERVICES))
    options = "".join(f"<option>{html.escape(s)}</option>" for s in SERVICES)
    rows = []
    for item in statuses:
        state = str(item.get("state", "unknown"))
        cls = "ok" if state == "active" else ("bad" if state == "failed" else "warntext")
        service = str(item.get("service", ""))
        rows.append(f"<tr><td>{html.escape(service)}</td><td class=\"{cls}\">{html.escape(state)}</td><td><button class=alt onclick=\"quickService('{html.escape(service)}','restart')\">restart</button></td></tr>")
    audit_rows = recent_audit()
    audit_text = json.dumps(audit_rows, indent=2) if audit_rows else "No audit entries yet."
    return (HTML
        .replace("__CSRF__", csrf)
        .replace("__SERVICE_OPTIONS__", options)
        .replace("__SERVICE_ROWS__", "".join(rows))
        .replace("__AUDIT__", html.escape(audit_text)))


class Handler(BaseHTTPRequestHandler):
    server_version = "PteroProtectEmergency/1.0"

    def _json(self, obj, code=200):
        data = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def _html(self, body, session_cookie=None):
        data = body.encode()
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Referrer-Policy", "no-referrer")
        if session_cookie:
            secure = "; Secure" if self.headers.get("X-Forwarded-Proto", "").lower() == "https" else ""
            self.send_header("Set-Cookie", f"pp_emergency={session_cookie}; Path=/; HttpOnly; SameSite=Strict; Max-Age={self.server.session_ttl}{secure}")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def _body(self):
        ln = int(self.headers.get("Content-Length", "0") or 0)
        if ln > MAX_BODY:
            raise ValueError("body_too_large")
        raw = self.rfile.read(ln).decode() if ln else "{}"
        return json.loads(raw or "{}")

    def _query_token(self):
        try:
            values = parse_qs(urlparse(self.path).query, keep_blank_values=True).get("token", [])
            return str(values[0]) if values else ""
        except Exception:
            return ""

    def _silent_drop(self, delay_sec=2):
        try:
            time.sleep(delay_sec)
            self.connection.close()
        except Exception:
            pass

    def _cookie_session(self):
        c = cookies.SimpleCookie(self.headers.get("Cookie", ""))
        morsel = c.get("pp_emergency")
        return parse_session(morsel.value if morsel else "", self.server.admin_token)

    def _terminal_ws(self):
        if not self._require():
            return
        if self.headers.get("Upgrade", "").lower() != "websocket" or "upgrade" not in self.headers.get("Connection", "").lower():
            self._json({"error": "websocket_upgrade_required"}, 426)
            return
        key = self.headers.get("Sec-WebSocket-Key", "")
        if self.headers.get("Sec-WebSocket-Version", "") != "13" or not valid_ws_key(key):
            self._json({"error": "bad_websocket_request"}, 426)
            return
        accept_ws(self.connection, key)
        audit(client_ip(self), "terminal", "root-pty", "start")
        pid, fd = pty.fork()
        if pid == 0:
            os.environ.clear()
            os.environ.update({
                "TERM": "xterm-256color",
                "HOME": "/root",
                "USER": "root",
                "LOGNAME": "root",
                "PATH": "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
            })
            os.chdir("/root")
            os.execl("/bin/bash", "bash", "-l")
        pty_resize(fd, 120, 32)
        sel = selectors.DefaultSelector()
        self.connection.setblocking(False)
        os.set_blocking(fd, False)
        sel.register(self.connection, selectors.EVENT_READ, "ws")
        sel.register(fd, selectors.EVENT_READ, "pty")
        last = time.time()
        try:
            while True:
                if time.time() - last > PTY_IDLE_TIMEOUT:
                    ws_send(self.connection, b"\r\n[PteroProtect Emergency] idle timeout\r\n", 1)
                    break
                for key_obj, _ in sel.select(timeout=1):
                    if key_obj.data == "pty":
                        try:
                            data = os.read(fd, 8192)
                        except OSError as exc:
                            if exc.errno in {errno.EIO, errno.EBADF}:
                                return
                            raise
                        if not data:
                            return
                        ws_send(self.connection, data)
                    else:
                        msg = ws_recv(self.connection)
                        if msg is None:
                            return
                        opcode, payload = msg
                        last = time.time()
                        if opcode == 8:
                            return
                        if opcode == 9:
                            ws_send(self.connection, payload, 10)
                            continue
                        if opcode in {1, 2}:
                            try:
                                obj = json.loads(payload.decode("utf-8"))
                                if isinstance(obj, dict) and obj.get("type") == "resize":
                                    pty_resize(fd, obj.get("cols", 120), obj.get("rows", 32))
                                    continue
                            except Exception:
                                pass
                            os.write(fd, payload)
        finally:
            audit(client_ip(self), "terminal", "root-pty", "stop")
            try:
                os.kill(pid, signal.SIGHUP)
            except Exception:
                pass
            try:
                os.close(fd)
            except Exception:
                pass

    def _require(self):
        ip = client_ip(self)
        session = self._cookie_session()
        csrf = self.headers.get("X-CSRF-Token", "")
        if session and (self.command == "GET" or hmac.compare_digest(csrf, session["csrf"])):
            if not action_allowed(ip):
                self._json({"error": "rate_limited"}, 429)
                return None
            return {"session": session}

        token = self.headers.get("X-Admin-Token", "")
        if not self.server.admin_token or token == "" or not hmac.compare_digest(token, self.server.admin_token):
            if is_banned(ip):
                self._silent_drop()
                return None
            fail_auth(ip, token)
            self._silent_drop()
            return None
        clear_auth_fail(ip)
        if not action_allowed(ip):
            self._json({"error": "rate_limited"}, 429)
            return None
        return {"token": True}

    def do_GET(self):
        if urlparse(self.path).path == "/api/terminal/ws":
            self._terminal_ws()
            return
        if urlparse(self.path).path == "/":
            query_token = self._query_token()
            if query_token:
                if not self.server.query_bootstrap or not hmac.compare_digest(query_token, self.server.admin_token):
                    ip = client_ip(self)
                    if is_banned(ip):
                        self._silent_drop()
                        return
                    fail_auth(ip, query_token)
                    self._silent_drop()
                    return
                clear_auth_fail(client_ip(self))
                session_cookie, csrf = make_cookie(self.server.admin_token, self.server.session_ttl)
                self._html(render_html(csrf), session_cookie)
                return
            auth = self._require()
            if not auth:
                return
            csrf = auth.get("session", {}).get("csrf", "")
            self._html(render_html(csrf))
            return
        if urlparse(self.path).path == "/api/status":
            if not self._require():
                return
            with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
                services = list(pool.map(compact_status, SERVICES))
            self._json({"services": services, "audit": recent_audit()})
            return
        self._json({"error": "not_found"}, 404)

    def do_POST(self):
        ip = client_ip(self)
        path = urlparse(self.path).path
        if path == "/login":
            self._silent_drop()
            return

        if not self._require():
            return
        try:
            data = self._body()
        except Exception:
            self._json({"error": "bad_json"}, 400)
            return

        if path == "/api/service":
            service = str(data.get("service", ""))
            action = str(data.get("action", ""))
            if service not in SERVICES or action not in SERVICE_ACTIONS:
                self._json({"error": "not_allowed"}, 422)
                return
            if service == "pteroprotect-emergency-panel" and action in {"restart", "reload"}:
                self._json({"error": "self_restart_disabled"}, 422)
                return
            r = adminctl(f"service-{action}", service, timeout=25)
            audit(ip, "service", f"{action}:{service}", "ok" if r["exit"] == 0 else "failed", r)
            self._json(r)
            return

        if path == "/api/mode":
            mode = str(data.get("mode", ""))
            ttl = int(data.get("ttl", 600) or 600)
            if mode not in MODES or ttl < 60 or ttl > 86400:
                self._json({"error": "bad_mode_or_ttl"}, 422)
                return
            r = adminctl("mode", mode, str(ttl), timeout=20)
            audit(ip, "mode", mode, "ok" if r["exit"] == 0 else "failed", r)
            self._json(r)
            return

        if path == "/api/firewall":
            action = str(data.get("action", ""))
            value = str(data.get("value", "")).strip()
            ttl = int(data.get("ttl", 3600) or 3600)
            if action not in FW_ACTIONS or not valid_ip_or_cidr(value) or ttl < 0 or ttl > 2592000:
                self._json({"error": "bad_firewall_request"}, 422)
                return
            args = ["firewall", action, value] + ([str(ttl)] if action == "ban" else [])
            r = adminctl(*args, timeout=20)
            audit(ip, "firewall", f"{action}:{value}", "ok" if r["exit"] == 0 else "failed", r)
            self._json(r)
            return

        if path == "/api/docker":
            action = str(data.get("action", ""))
            target = str(data.get("target", "")).strip()
            if action not in DOCKER_ACTIONS or (action in {"restart", "start", "stop"} and not target):
                self._json({"error": "bad_docker_request"}, 422)
                return
            r = adminctl("docker", action, target, timeout=30)
            audit(ip, "docker", f"{action}:{target}", "ok" if r["exit"] == 0 else "failed", r)
            self._json(r)
            return

        if path == "/api/recover":
            target = str(data.get("target", ""))
            if target not in {"failed", "protect-stack"}:
                self._json({"error": "bad_recover_target"}, 422)
                return
            results = []
            candidates = PROTECT_STACK if target == "protect-stack" else [s["service"] for s in [compact_status(x) for x in SERVICES] if s.get("state") in {"failed", "inactive"}]
            for service in candidates:
                if service == "pteroprotect-emergency-panel":
                    continue
                r = adminctl("service-restart", service, timeout=25)
                results.append({"service": service, **r})
            audit(ip, "recover", target, "ok", {"count": len(results)})
            self._json({"target": target, "results": results})
            return

        if path == "/api/reboot":
            if str(data.get("confirm", "")) != "REBOOT":
                self._json({"error": "confirm_required"}, 422)
                return
            r = adminctl("reboot", timeout=5)
            audit(ip, "reboot", "host", "requested", r)
            self._json(r)
            return

        self._json({"error": "not_found"}, 404)

    def log_message(self, fmt, *args):
        return


def main():
    cfg = read_config()
    if not cfg["token"]:
        print("[emergency] missing network.emergency_control_token")
        raise SystemExit(1)
    srv = ThreadingHTTPServer((cfg["bind"], cfg["port"]), Handler)
    srv.admin_token = cfg["token"].strip()
    srv.session_ttl = cfg["session_ttl"]
    srv.query_bootstrap = cfg["query_bootstrap"]
    print(f"[emergency] listening on {cfg['bind']}:{cfg['port']}")
    srv.serve_forever()


if __name__ == "__main__":
    main()

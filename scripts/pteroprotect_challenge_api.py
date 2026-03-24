#!/usr/bin/env python3
import base64
import hashlib
import hmac
import ipaddress
import json
import os
import random
import secrets
import threading
import time
from http import HTTPStatus
from http.cookies import SimpleCookie
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlencode, urlparse

CONFIG_PATH = os.environ.get("PTEROPROTECT_CONFIG_PATH", "/pteroprotect/config.json")

_cfg_cache = {"at": 0.0, "data": {}}
_nonce_lock = threading.Lock()
_nonces = {}
_ephemeral_secret = secrets.token_urlsafe(32)
_rate_lock = threading.Lock()
_rate_windows = {}


def _b64u(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).decode().rstrip("=")


def _ub64u(data: str) -> bytes:
    pad = "=" * (-len(data) % 4)
    return base64.urlsafe_b64decode(data + pad)


def load_cfg():
    now = time.time()
    if now - _cfg_cache["at"] < 2.0 and _cfg_cache["data"]:
        return _cfg_cache["data"]
    data = {}
    try:
        with open(CONFIG_PATH, "r", encoding="utf-8") as f:
            raw = json.load(f)
            if isinstance(raw, dict):
                data = raw
    except Exception:
        data = {}
    _cfg_cache["at"] = now
    _cfg_cache["data"] = data
    return data


def net_setting(name, default):
    cfg = load_cfg()
    net = cfg.get("network") if isinstance(cfg, dict) else {}
    if not isinstance(net, dict):
        return default
    return net.get(name, default)


def challenge_secret():
    secret = str(net_setting("waf_challenge_secret", "") or "").strip()
    if secret:
        return secret
    fallback = str(net_setting("unblock_portal_token", "") or "").strip()
    if fallback:
        return fallback
    env_secret = str(os.environ.get("PTEROPROTECT_WAF_CHALLENGE_SECRET", "") or "").strip()
    if env_secret:
        return env_secret
    env_fallback = str(os.environ.get("PTEROPROTECT_UNBLOCK_PORTAL_TOKEN", "") or "").strip()
    if env_fallback:
        return env_fallback
    return _ephemeral_secret


def challenge_ttl():
    try:
        ttl = int(net_setting("waf_challenge_ttl_sec", 1800))
    except Exception:
        ttl = 1800
    return max(60, min(86400, ttl))


def cookie_name():
    return str(net_setting("waf_challenge_cookie_name", "pp_clearance") or "pp_clearance")


def is_enabled():
    val = str(net_setting("waf_challenge_enabled", True)).strip().lower()
    return val not in ("0", "false", "off", "no")


def client_ip(handler):
    def _first_ip(raw: str) -> str:
        candidate = str(raw or "").split(",", 1)[0].strip()
        if not candidate:
            return ""
        try:
            ipaddress.ip_address(candidate)
            return candidate
        except Exception:
            return ""

    peer = (handler.client_address[0] or "").strip()
    allow_forwarded = peer in ("127.0.0.1", "::1", "::ffff:127.0.0.1")
    if allow_forwarded:
        real_ip = _first_ip(handler.headers.get("X-Real-IP", ""))
        if real_ip:
            return real_ip
        xff_ip = _first_ip(handler.headers.get("X-Forwarded-For", ""))
        if xff_ip:
            return xff_ip

    resolved_peer = _first_ip(peer)
    return resolved_peer or "127.0.0.1"


def ua_fingerprint(handler):
    ua = (handler.headers.get("User-Agent") or "").strip().encode()
    return hashlib.sha256(ua).hexdigest()[:24]


def sign_payload(raw_payload: bytes) -> str:
    sig = hmac.new(challenge_secret().encode(), raw_payload, hashlib.sha256).digest()
    return _b64u(sig)


def issue_token(ip: str, ua_fp: str):
    payload = {
        "ip": ip,
        "ua": ua_fp,
        "exp": int(time.time()) + challenge_ttl(),
    }
    raw = json.dumps(payload, separators=(",", ":"), ensure_ascii=True).encode()
    b = _b64u(raw)
    return f"{b}.{sign_payload(raw)}"


def verify_token(token: str, ip: str, ua_fp: str) -> bool:
    if "." not in token:
        return False
    b, sig = token.split(".", 1)
    try:
        raw = _ub64u(b)
    except Exception:
        return False
    expected = sign_payload(raw)
    if not hmac.compare_digest(expected, sig):
        return False
    try:
        payload = json.loads(raw.decode("utf-8"))
    except Exception:
        return False
    if not isinstance(payload, dict):
        return False
    if int(payload.get("exp", 0)) < int(time.time()):
        return False
    if str(payload.get("ip", "")) != ip:
        return False
    if str(payload.get("ua", "")) != ua_fp:
        return False
    return True


def cleanup_nonces():
    now = time.time()
    with _nonce_lock:
        expired = [k for k, v in _nonces.items() if float(v.get("exp", 0)) <= now]
        for k in expired:
            _nonces.pop(k, None)


def normalize_redirect_path(raw: str) -> str:
    try:
        parsed = urlparse(str(raw or ""))
    except Exception:
        return "/"

    path = parsed.path or "/"
    if not path.startswith("/"):
        return "/"

    sanitized = path
    if parsed.query:
        sanitized += "?" + parsed.query
    if parsed.fragment:
        sanitized += "#" + parsed.fragment

    if "\n" in sanitized or "\r" in sanitized or "\x00" in sanitized:
        return "/"

    if len(sanitized) > 2048:
        return "/"

    return sanitized


def rate_allow(ip: str, bucket: str, limit: int, window_sec: int) -> bool:
    now = int(time.time())
    key = f"{bucket}:{ip}"
    with _rate_lock:
        rec = _rate_windows.get(key)
        if not rec or now - int(rec.get("start", 0)) >= window_sec:
            rec = {"start": now, "count": 0}
        rec["count"] = int(rec.get("count", 0)) + 1
        _rate_windows[key] = rec
        if int(rec["count"]) > limit:
            return False
    return True


class Handler(BaseHTTPRequestHandler):
    server_version = "PteroProtectChallenge/1.0"

    def log_message(self, fmt, *args):
        return

    def _json(self, status: int, obj: dict, extra_headers=None):
        body = json.dumps(obj, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("X-Frame-Options", "DENY")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("Content-Length", str(len(body)))
        if extra_headers:
            for k, v in extra_headers.items():
                self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def _html(self, status: int, html: str):
        body = html.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("X-Frame-Options", "DENY")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("Content-Security-Policy", "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _parse_cookie(self):
        c = SimpleCookie()
        try:
            c.load(self.headers.get("Cookie") or "")
        except Exception:
            return {}
        out = {}
        for k in c:
            out[k] = c[k].value
        return out

    def _read_json(self):
        try:
            ln = int(self.headers.get("Content-Length", "0"))
        except Exception:
            ln = 0
        if ln <= 0 or ln > 64 * 1024:
            return {}
        raw = self.rfile.read(ln)
        try:
            data = json.loads(raw.decode("utf-8"))
        except Exception:
            return {}
        return data if isinstance(data, dict) else {}

    def do_GET(self):
        parsed = urlparse(self.path)
        path = parsed.path
        q = parse_qs(parsed.query)
        ip = client_ip(self)
        ua = ua_fingerprint(self)

        if path == "/health":
            return self._json(200, {"ok": True, "enabled": is_enabled()})

        if path == "/check":
            if not is_enabled():
                self.send_response(HTTPStatus.NO_CONTENT)
                self.send_header("Cache-Control", "no-store")
                self.end_headers()
                return
            ck = self._parse_cookie()
            tok = ck.get(cookie_name(), "")
            if tok and verify_token(tok, ip, ua):
                self.send_response(HTTPStatus.NO_CONTENT)
                self.send_header("Cache-Control", "no-store")
                self.end_headers()
                return
            self.send_response(HTTPStatus.UNAUTHORIZED)
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            return

        if path == "/new":
            if not is_enabled():
                return self._json(200, {"ok": True, "disabled": True})
            if not rate_allow(ip, "challenge_new", 40, 60):
                return self._json(429, {"ok": False, "error": "rate_limited"})
            cleanup_nonces()
            a = random.randint(11, 89)
            b = random.randint(11, 89)
            op = random.choice(["+", "-"])
            ans = a + b if op == "+" else a - b
            nonce = secrets.token_urlsafe(18)
            with _nonce_lock:
                _nonces[nonce] = {
                    "ans": str(ans),
                    "ip": ip,
                    "ua": ua,
                    "exp": time.time() + 120,
                }
            return self._json(200, {"ok": True, "nonce": nonce, "question": f"{a} {op} {b} = ?"})

        if path == "/page":
            rd = normalize_redirect_path(q.get("rd", ["/"])[0])
            rd_js = json.dumps(rd)
            html = f"""<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>PteroProtect Challenge</title>
<style>
body{{background:#0f1b2b;color:#d6e9ff;font-family:system-ui;margin:0;display:grid;place-items:center;height:100vh}}
.c{{width:min(420px,92vw);background:#11273f;border:1px solid #27507a;border-radius:12px;padding:18px}}
h2{{margin:0 0 8px;color:#9fd2ff}} p{{color:#b8d6f2}} input,button{{width:100%;padding:10px;border-radius:8px;border:1px solid #2d5a86;background:#0e2136;color:#d6e9ff}}
button{{margin-top:10px;background:#1f6feb;border-color:#2d7cf0;cursor:pointer}} .e{{color:#ff8f8f;min-height:18px}}
</style></head><body><div class="c">
<h2>Security Check</h2><p>Buktikan kamu bukan flood bot. Clearance berlaku 30 menit.</p>
<p id="q">Loading challenge...</p><input id="a" placeholder="Jawaban"/><button id="b">Verify</button><div class="e" id="e"></div>
</div><script>
let nonce=""; const elQ=document.getElementById("q"), elA=document.getElementById("a"), elB=document.getElementById("b"), elE=document.getElementById("e");
async function loadC(){{elE.textContent=""; const r=await fetch("/__pteroprotect/challenge/new",{{cache:"no-store"}}); const j=await r.json(); if(!j.ok) throw new Error("challenge unavailable"); nonce=j.nonce; elQ.textContent=j.question;}}
elB.onclick=async()=>{{try{{ const r=await fetch("/__pteroprotect/challenge/solve",{{method:"POST",headers:{{"Content-Type":"application/json"}},body:JSON.stringify({{nonce:nonce,answer:elA.value||"",rd:{rd_js}}})}}); const j=await r.json(); if(!j.ok) throw new Error(j.error||"failed"); location.href=j.redirect||{rd_js}; }}catch(err){{elE.textContent=String(err.message||err);}}}};
loadC().catch(e=>elE.textContent=String(e.message||e));
</script></body></html>"""
            return self._html(200, html)

        return self._json(404, {"ok": False, "error": "not_found"})

    def do_HEAD(self):
        parsed = urlparse(self.path)
        path = parsed.path
        ip = client_ip(self)
        ua = ua_fingerprint(self)

        if path == "/check":
            if not is_enabled():
                self.send_response(HTTPStatus.NO_CONTENT)
                self.send_header("Cache-Control", "no-store")
                self.end_headers()
                return
            ck = self._parse_cookie()
            tok = ck.get(cookie_name(), "")
            if tok and verify_token(tok, ip, ua):
                self.send_response(HTTPStatus.NO_CONTENT)
            else:
                self.send_response(HTTPStatus.UNAUTHORIZED)
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            return

        if path in ("/health", "/page", "/new"):
            self.send_response(HTTPStatus.OK)
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            return

        self.send_response(HTTPStatus.NOT_FOUND)
        self.end_headers()

    def do_POST(self):
        parsed = urlparse(self.path)
        path = parsed.path
        ip = client_ip(self)
        ua = ua_fingerprint(self)

        if path != "/solve":
            return self._json(404, {"ok": False, "error": "not_found"})

        if not is_enabled():
            return self._json(200, {"ok": True, "redirect": "/"})
        if not rate_allow(ip, "challenge_solve", 80, 60):
            return self._json(429, {"ok": False, "error": "rate_limited"})

        body = self._read_json()
        nonce = str(body.get("nonce", "")).strip()
        answer = str(body.get("answer", "")).strip()
        rd = normalize_redirect_path(str(body.get("rd", "/")).strip())

        with _nonce_lock:
            rec = _nonces.pop(nonce, None)
        if not rec:
            return self._json(401, {"ok": False, "error": "nonce_invalid"})
        if rec.get("exp", 0) < time.time():
            return self._json(401, {"ok": False, "error": "nonce_expired"})
        if rec.get("ip", "") != ip or rec.get("ua", "") != ua:
            return self._json(401, {"ok": False, "error": "fingerprint_mismatch"})
        if rec.get("ans", "") != answer:
            return self._json(401, {"ok": False, "error": "answer_wrong"})

        tok = issue_token(ip, ua)
        max_age = challenge_ttl()
        headers = {
            "Set-Cookie": f"{cookie_name()}={tok}; Path=/; Max-Age={max_age}; HttpOnly; Secure; SameSite=Lax",
        }
        return self._json(200, {"ok": True, "redirect": rd}, extra_headers=headers)


def main():
    bind = str(net_setting("waf_challenge_bind", "127.0.0.1"))
    try:
        port = int(net_setting("waf_challenge_port", 18444))
    except Exception:
        port = 18444
    srv = ThreadingHTTPServer((bind, port), Handler)
    srv.serve_forever()


if __name__ == "__main__":
    main()

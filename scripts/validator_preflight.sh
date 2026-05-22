#!/usr/bin/env bash
set -euo pipefail

CONFIG_PATH="${DANN_CONFIG_PATH:-/pteroprotect/config.json}"
PANEL_ENV="/var/www/pterodactyl/.env"

ok=0
warn=0
fail=0

say_ok() { echo "[OK] $*"; ok=$((ok+1)); }
say_warn() { echo "[WARN] $*"; warn=$((warn+1)); }
say_fail() { echo "[FAIL] $*"; fail=$((fail+1)); }

have() { command -v "$1" >/dev/null 2>&1; }

if [[ ! -f "$CONFIG_PATH" ]]; then
  say_fail "config not found: $CONFIG_PATH"
  echo "summary ok=${ok} warn=${warn} fail=${fail}"
  exit 2
fi

if ! have python3; then
  say_fail "python3 is required"
  echo "summary ok=${ok} warn=${warn} fail=${fail}"
  exit 2
fi

read_cfg() {
  python3 - "$CONFIG_PATH" "$1" "$2" <<'PY'
import json,sys
p,key,d=sys.argv[1],sys.argv[2],sys.argv[3]
try:
    with open(p,'r',encoding='utf-8') as f:
        j=json.load(f)
except Exception:
    print(d); raise SystemExit(0)
cur=j
for part in key.split('.'):
    if isinstance(cur,dict) and part in cur:
        cur=cur[part]
    else:
        print(d); raise SystemExit(0)
if isinstance(cur,bool):
    print('true' if cur else 'false')
elif isinstance(cur,(int,float,str)):
    print(cur)
else:
    print(d)
PY
}

is_placeholder_url() {
  local value="${1:-}"
  python3 - "$value" <<'PY'
import sys
import urllib.parse

value = (sys.argv[1] if len(sys.argv) > 1 else "").strip()
host = (urllib.parse.urlparse(value).hostname or "").lower().strip(".")
raise SystemExit(0 if host in {"", "example.com", "www.example.com", "example.net", "example.org"} else 1)
PY
}

EXTERNAL_URL="$(read_cfg monitor.external_url "")"
PTLC_URL="$(read_cfg ptlc.url "")"
if [[ -z "$EXTERNAL_URL" ]] || is_placeholder_url "$EXTERNAL_URL"; then
  EXTERNAL_URL="$PTLC_URL"
fi
CHALLENGE_PATH="$(read_cfg monitor.challenge_path "/__pteroprotect/challenge/page")"
LOCAL_HEALTH="$(read_cfg monitor.local_health_url "http://127.0.0.1:18080/api/system")"

if [[ -n "$EXTERNAL_URL" ]]; then
  code="$(curl -k -sS -o /dev/null -w '%{http_code}' --connect-timeout 5 --max-time 10 "${EXTERNAL_URL%/}${CHALLENGE_PATH}" || true)"
  if [[ "$code" =~ ^(200|204|301|302|303|307|308)$ ]]; then
    say_ok "challenge endpoint reachable ($code) (${EXTERNAL_URL%/}${CHALLENGE_PATH})"
  else
    say_fail "challenge endpoint unreachable ($code) for ${EXTERNAL_URL%/}${CHALLENGE_PATH}"
  fi
else
  say_warn "monitor.external_url/ptlc.url empty; challenge check skipped"
fi

lh_code="$(curl -sS -o /dev/null -w '%{http_code}' --connect-timeout 4 --max-time 8 "$LOCAL_HEALTH" || true)"
if [[ "$lh_code" =~ ^(200|401|403)$ ]]; then
  say_ok "local health reachable ($LOCAL_HEALTH => $lh_code)"
else
  say_fail "local health failed ($LOCAL_HEALTH => $lh_code)"
fi

for svc in nginx wings php8.3-fpm; do
  if systemctl is-active --quiet "$svc"; then
    say_ok "service active: $svc"
  else
    say_fail "service inactive: $svc"
  fi
done

PANEL_DB=""
if [[ -f "$PANEL_ENV" ]]; then
  PANEL_DB="$(awk -F= '/^DB_DATABASE=/{print $2}' "$PANEL_ENV" | tr -d '"' | tail -n1)"
fi
if [[ -z "$PANEL_DB" ]]; then
  PANEL_DB="panel"
fi

if have mysql; then
  daemon_listen="$(mysql -N -B "$PANEL_DB" -e "SELECT daemonListen FROM nodes ORDER BY id LIMIT 1;" 2>/dev/null || true)"
  if [[ -n "$daemon_listen" ]]; then
    if ss -lnt 2>/dev/null | awk '{print $4}' | grep -Eq ":${daemon_listen}$"; then
      say_ok "panel daemonListen=${daemon_listen} is listening"
    else
      say_fail "panel daemonListen=${daemon_listen} not listening"
    fi
  else
    say_warn "cannot read nodes.daemonListen from db=${PANEL_DB}"
  fi

  token_id_cfg="$(read_cfg token_id "")"
  token_id_db="$(mysql -N -B "$PANEL_DB" -e "SELECT daemon_token_id FROM nodes ORDER BY id LIMIT 1;" 2>/dev/null || true)"
  if [[ -n "$token_id_cfg" && -n "$token_id_db" ]]; then
    if [[ "$token_id_cfg" == "$token_id_db" ]]; then
      say_ok "token_id matches panel db"
    else
      say_fail "token_id mismatch cfg=${token_id_cfg} db=${token_id_db}"
    fi
  else
    say_warn "token_id check skipped (missing cfg/db value)"
  fi
else
  say_warn "mysql cli not found; db checks skipped"
fi

echo "summary ok=${ok} warn=${warn} fail=${fail}"
if (( fail > 0 )); then
  exit 2
fi
exit 0

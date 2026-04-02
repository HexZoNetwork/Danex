#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'USAGE'
Usage:
  defense_scorecard.sh <base_url> [api_client_token] [server_id_or_uuid]

Description:
  Safe, bounded defense audit across L7 and basic L4 indicators.
  Produces a transparent scorecard with HTTP status distributions.

Examples:
  defense_scorecard.sh https://panel.example.com
  defense_scorecard.sh https://panel.example.com ptlc_xxx 123

Notes:
  - This script is non-destructive (not a volumetric DDoS tool).
  - Optional token+server enables nodefs abuse smoke check.
USAGE
}

BASE_URL="${1:-}"
API_TOKEN="${2:-}"
SERVER_ID="${3:-}"

if [[ -z "${BASE_URL}" || "${BASE_URL}" == "-h" || "${BASE_URL}" == "--help" ]]; then
    usage
    exit 1
fi

BASE_URL="${BASE_URL%/}"
HOST="$(printf '%s' "${BASE_URL}" | sed -E 's#^https?://##; s#/.*$##')"
UA_BROWSER='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36'
UA_HEADLESS='Mozilla/5.0 HeadlessChrome/124.0.0.0'

tmp_dir="$(mktemp -d /tmp/pteroprotect-scorecard.XXXXXX)"
trap 'rm -rf "${tmp_dir}"' EXIT

code() {
    local raw
    raw="$(
        curl -ksS -o /dev/null -w "%{http_code}" \
        --connect-timeout 4 --max-time 12 \
        "$@" 2>/dev/null || true
    )"
    if [[ "${raw}" =~ ^[0-9]{3}$ ]]; then
        printf '%s\n' "${raw}"
    else
        printf '000\n'
    fi
}

dist() {
    local file="$1"
    awk '{c[$1]++} END {for (k in c) printf("  %s %d\n", k, c[k])}' "${file}" | sort -n
}

echo "=== PteroProtect Defense Scorecard ==="
echo "target=${BASE_URL}"
echo "timestamp_utc=$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo

echo "[L7] Single probe checks"
echo "  root_browser $(code -H "User-Agent: ${UA_BROWSER}" "${BASE_URL}/")"
echo "  root_headless $(code -H "User-Agent: ${UA_HEADLESS}" "${BASE_URL}/")"
echo "  challenge_new_browser $(code -H "User-Agent: ${UA_BROWSER}" "${BASE_URL}/__pteroprotect/challenge/new?hc=8&dm=8&m=0")"
echo "  challenge_new_headless $(code -H "User-Agent: ${UA_HEADLESS}" "${BASE_URL}/__pteroprotect/challenge/new?hc=8&dm=8&m=0")"
echo "  sqli_union $(code "${BASE_URL}/?id=1+UNION+SELECT+1")"
echo "  sqli_sleep $(code "${BASE_URL}/?q=1;select+sleep(2)")"
echo "  api_client_noauth $(code "${BASE_URL}/api/client")"
echo "  api_application_noauth $(code "${BASE_URL}/api/application")"
echo

echo "[L7] API burst distribution (/api/client)"
seq 160 | xargs -P 45 -I{} bash -lc '
  raw="$(curl -ksS -o /dev/null -w "%{http_code}" --connect-timeout 3 --max-time 8 "'"${BASE_URL}"'/api/client" 2>/dev/null || true)"
  c="$(printf "%s" "${raw}" | grep -Eo "[0-9]{3}$" || true)"
  [[ -n "${c}" ]] || c="000"
  echo "${c}"
' > "${tmp_dir}/api_burst.txt"
dist "${tmp_dir}/api_burst.txt"
echo

echo "[L7] Auth abuse distribution (/auth/login)"
seq 90 | xargs -P 25 -I{} bash -lc '
  raw="$(curl -ksS -o /dev/null -w "%{http_code}" --connect-timeout 3 --max-time 8 \
    -X POST "'"${BASE_URL}"'/auth/login" \
    -H "Content-Type: application/json" \
    --data "{\"user\":\"u{}\",\"password\":\"x\"}" 2>/dev/null || true)"
  c="$(printf "%s" "${raw}" | grep -Eo "[0-9]{3}$" || true)"
  [[ -n "${c}" ]] || c="000"
  echo "${c}"
' > "${tmp_dir}/auth_burst.txt"
dist "${tmp_dir}/auth_burst.txt"
echo

echo "[L7] Chat abuse distribution (/api/client/chat/messages)"
seq 140 | xargs -P 40 -I{} bash -lc '
  raw="$(curl -ksS -o /dev/null -w "%{http_code}" --connect-timeout 3 --max-time 8 "'"${BASE_URL}"'/api/client/chat/messages" 2>/dev/null || true)"
  c="$(printf "%s" "${raw}" | grep -Eo "[0-9]{3}$" || true)"
  [[ -n "${c}" ]] || c="000"
  echo "${c}"
' > "${tmp_dir}/chat_burst.txt"
dist "${tmp_dir}/chat_burst.txt"
echo

echo "[L4] TCP connect indicator (:443)"
seq 80 | xargs -P 25 -I{} bash -lc '
  t="$({ time timeout 5 bash -lc "exec 3<>/dev/tcp/'"${HOST}"'/443"; } 2>&1 | awk "/real/{print \$2}")"
  if [[ -n "${t}" ]]; then
    echo "ok ${t}"
  else
    echo "fail 0m5.000s"
  fi
' > "${tmp_dir}/tcp_conn.txt"

ok_count="$(awk '$1=="ok"{c++} END{print c+0}' "${tmp_dir}/tcp_conn.txt")"
fail_count="$(awk '$1=="fail"{c++} END{print c+0}' "${tmp_dir}/tcp_conn.txt")"
echo "  tcp_connect_ok=${ok_count}"
echo "  tcp_connect_fail=${fail_count}"
awk '
function tosec(v, a){ split(v,a,"m"); gsub("s","",a[2]); return (a[1]*60)+a[2] }
$1=="ok"{
  x=tosec($2);
  if(n==0 || x<min) min=x;
  if(n==0 || x>max) max=x;
  sum+=x;
  n++;
}
END{
  if(n>0) printf("  tcp_connect_latency_s avg=%.3f min=%.3f max=%.3f samples=%d\n", sum/n, min, max, n);
}' "${tmp_dir}/tcp_conn.txt"
echo

if [[ -n "${API_TOKEN}" && -n "${SERVER_ID}" ]]; then
    echo "[L7] NodeFS abuse smoke (authenticated)"
    bash scripts/smoke_nodefs_abuse.sh "${BASE_URL}" "${API_TOKEN}" "${SERVER_ID}" "/" 240 16
    echo
fi

echo "done=1"

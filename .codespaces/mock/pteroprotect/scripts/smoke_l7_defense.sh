#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'USAGE'
Usage:
  smoke_l7_defense.sh <base_url> [burst_requests] [concurrency]

Example:
  smoke_l7_defense.sh https://panel.example.com 120 40

Notes:
  - Bounded, non-destructive L7 defense smoke test.
  - Verifies headless/spoof/query-pattern handling + rate-limit behavior.
Defaults:
  burst_requests=100
  concurrency=30
Hard caps:
  burst_requests<=220
  concurrency<=80
USAGE
}

BASE_URL="${1:-}"
BURST="${2:-100}"
CONC="${3:-30}"

if [[ -z "${BASE_URL}" || "${BASE_URL}" == "-h" || "${BASE_URL}" == "--help" ]]; then
    usage
    exit 1
fi

if ! [[ "${BURST}" =~ ^[0-9]+$ ]]; then
    echo "burst_requests must be integer" >&2
    exit 1
fi
if ! [[ "${CONC}" =~ ^[0-9]+$ ]]; then
    echo "concurrency must be integer" >&2
    exit 1
fi
if (( BURST < 20 )); then BURST=20; fi
if (( BURST > 220 )); then BURST=220; fi
if (( CONC < 1 )); then CONC=1; fi
if (( CONC > 80 )); then CONC=80; fi

BASE_URL="${BASE_URL%/}"
TARGET_WEB="${BASE_URL}/"
TARGET_API="${BASE_URL}/api/client"
TARGET_CHALLENGE_NEW="${BASE_URL}/__pteroprotect/challenge/new?hc=8&dm=8&m=0"

curl_code() {
    local url="$1"
    shift
    curl -ksS -o /dev/null -w "%{http_code}" --connect-timeout 4 --max-time 10 "$@" "${url}" || echo "000"
}

echo "target_web=${TARGET_WEB}"
echo "target_api=${TARGET_API}"
echo "target_challenge_new=${TARGET_CHALLENGE_NEW}"
echo "burst=${BURST}"
echo "concurrency=${CONC}"
echo

normal_code="$(curl_code "${TARGET_WEB}" -H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36')"
headless_code="$(curl_code "${TARGET_WEB}" -H 'User-Agent: Mozilla/5.0 HeadlessChrome/124.0.0.0')"
spoof_code="$(curl_code "${TARGET_WEB}" -H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36' -H 'X-Forwarded-For: 1.2.3.4' -H 'X-Real-IP: 5.6.7.8')"
pipe_code="$(curl_code "${TARGET_WEB}?a=abc|=def")"
challenge_browser_code="$(curl_code "${TARGET_CHALLENGE_NEW}" -H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36')"
challenge_headless_code="$(curl_code "${TARGET_CHALLENGE_NEW}" -H 'User-Agent: Mozilla/5.0 HeadlessChrome/124.0.0.0')"

echo "single_request_checks:"
echo "  normal_browser=${normal_code}"
echo "  headless_ua=${headless_code}"
echo "  spoof_headers=${spoof_code}"
echo "  query_pipe_pattern=${pipe_code}"
echo "  challenge_new_browser=${challenge_browser_code}"
echo "  challenge_new_headless=${challenge_headless_code}"
echo

tmp="$(mktemp -d /tmp/pteroprotect-l7-smoke.XXXXXX)"
trap 'rm -rf "${tmp}"' EXIT

seq "${BURST}" | xargs -P "${CONC}" -I{} bash -lc '
code="$(curl -ksS -o /dev/null -w "%{http_code}" \
  --connect-timeout 3 --max-time 8 \
  -H "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36" \
  -H "Accept-Language: en-US,en;q=0.9" \
  -H "Accept-Encoding: gzip, deflate, br" \
  "'"${TARGET_API}"'" 2>/dev/null || true)"
if [[ -z "$code" || "$code" == "000" ]]; then
  echo "000"
else
  echo "$code"
fi
' > "${tmp}/burst.txt"

echo "burst_status_distribution(api/client):"
awk '{c[$1]++} END {for (k in c) printf("  %s %d\n", k, c[k])}' "${tmp}/burst.txt" | sort -n

echo
echo "interpretation_hint:"
echo "  expected under protection: challenge_new_headless=401 and burst has 000/429/444 share."
echo "  if only 200/302/401 and zero 403/429, protection may not be active on runtime panel yet."

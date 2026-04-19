#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: smoke_http_protection.sh <url> [requests] [concurrency]

Safe HTTP smoke test (non-DDoS):
- checks baseline status
- checks challenge/internal endpoint exposure
- checks SQLi probe response
- runs a bounded burst and prints status distribution

Defaults:
  requests=120
  concurrency=12
Hard caps:
  requests<=400
  concurrency<=40
USAGE
}

URL="${1:-}"
REQ="${2:-120}"
CONC="${3:-12}"

if [[ -z "${URL}" || "${URL}" == "-h" || "${URL}" == "--help" ]]; then
    usage
    exit 1
fi

if ! [[ "${REQ}" =~ ^[0-9]+$ ]] || ! [[ "${CONC}" =~ ^[0-9]+$ ]]; then
    echo "requests and concurrency must be integers" >&2
    exit 1
fi

if (( REQ < 1 )); then REQ=1; fi
if (( CONC < 1 )); then CONC=1; fi
if (( REQ > 400 )); then REQ=400; fi
if (( CONC > 40 )); then CONC=40; fi

tmp="$(mktemp -d /tmp/pteroprotect-smoke.XXXXXX)"
trap 'rm -rf "${tmp}"' EXIT

request_code() {
    local target="$1"
    curl -sS -o /dev/null -w "%{http_code}" \
        --connect-timeout 4 --max-time 10 \
        "${target}" || echo "000"
}

printf 'target=%s\n' "${URL}"
printf 'requests=%s concurrency=%s\n' "${REQ}" "${CONC}"

base_code="$(request_code "${URL}")"
challenge_code="$(request_code "${URL%/}/__pteroprotect/challenge/check")"
sqli_code="$(request_code "${URL}?id=1+union+select+1")"

printf 'baseline_code=%s\n' "${base_code}"
printf 'challenge_check_public_code=%s\n' "${challenge_code}"
printf 'sqli_probe_code=%s\n' "${sqli_code}"

seq "${REQ}" | xargs -P "${CONC}" -I{} bash -lc '
code="$(curl -sS -o /dev/null -w "%{http_code}" --connect-timeout 4 --max-time 8 "'"${URL}"'" || echo 000)"
echo "${code}"
' > "${tmp}/codes.txt"

echo "status_distribution:"
awk '{c[$1]++} END {for (k in c) printf("  %s %d\n", k, c[k])}' "${tmp}/codes.txt" | sort -n

echo "sample_first_20:"
sed -n '1,20p' "${tmp}/codes.txt" | awk '{printf("%s%s", NR==1?"":" ", $1)} END{print ""}'

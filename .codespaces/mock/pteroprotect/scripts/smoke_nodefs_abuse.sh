#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'USAGE'
Usage:
  smoke_nodefs_abuse.sh <base_url> <client_api_token> <server_id_or_uuid> [file_path] [requests] [concurrency]

Example:
  smoke_nodefs_abuse.sh https://panel.example.com \
    ptlc_xxx_or_client_token \
    123 \
    /server.properties \
    300 \
    20

Notes:
  - Safe bounded smoke test for /api/client/servers/{server}/files/list and /files/contents.
  - This is intended to verify node file-abuse throttling, not to perform destructive load tests.
Defaults:
  file_path=/
  requests=240
  concurrency=16
Hard caps:
  requests<=1200
  concurrency<=60
USAGE
}

BASE_URL="${1:-}"
API_TOKEN="${2:-}"
SERVER_ID="${3:-}"
FILE_PATH="${4:-/}"
REQ="${5:-240}"
CONC="${6:-16}"

if [[ -z "${BASE_URL}" || -z "${API_TOKEN}" || -z "${SERVER_ID}" || "${BASE_URL}" == "-h" || "${BASE_URL}" == "--help" ]]; then
    usage
    exit 1
fi

if ! [[ "${REQ}" =~ ^[0-9]+$ ]] || ! [[ "${CONC}" =~ ^[0-9]+$ ]]; then
    echo "requests and concurrency must be integers" >&2
    exit 1
fi

if (( REQ < 1 )); then REQ=1; fi
if (( CONC < 1 )); then CONC=1; fi
if (( REQ > 1200 )); then REQ=1200; fi
if (( CONC > 60 )); then CONC=60; fi

BASE_URL="${BASE_URL%/}"
AUTH_HEADER="Authorization: Bearer ${API_TOKEN}"
LIST_URL="${BASE_URL}/api/client/servers/${SERVER_ID}/files/list?directory=%2F"
ENCODED_FILE_PATH="$(python3 - <<'PY' "${FILE_PATH}"
import sys
from urllib.parse import quote
print(quote(sys.argv[1], safe='/'))
PY
)"
CONTENTS_URL="${BASE_URL}/api/client/servers/${SERVER_ID}/files/contents?file=${ENCODED_FILE_PATH}"

tmp="$(mktemp -d /tmp/pteroprotect-nodefs-smoke.XXXXXX)"
trap 'rm -rf "${tmp}"' EXIT

echo "target_base=${BASE_URL}"
echo "server=${SERVER_ID}"
echo "file_path=${FILE_PATH}"
echo "requests=${REQ} concurrency=${CONC}"
echo "list_url=${LIST_URL}"
echo "contents_url=${CONTENTS_URL}"

request_once() {
    local url="$1"
    local out
    out="$(curl -sS -o /dev/null -w "%{http_code} %{time_total}" \
        --connect-timeout 4 --max-time 12 \
        -H "${AUTH_HEADER}" \
        "${url}" || echo "000 99.999")"
    echo "${out}"
}

echo "baseline_list: $(request_once "${LIST_URL}")"
echo "baseline_contents: $(request_once "${CONTENTS_URL}")"

seq "${REQ}" | xargs -P "${CONC}" -I{} bash -lc '
i="{}"
if (( i % 2 == 0 )); then
  url="'"${LIST_URL}"'"
else
  url="'"${CONTENTS_URL}"'"
fi
curl -sS -o /dev/null -w "%{http_code} %{time_total}\n" \
  --connect-timeout 4 --max-time 12 \
  -H "'"${AUTH_HEADER}"'" \
  "${url}" || echo "000 99.999"
' > "${tmp}/results.txt"

echo "status_distribution:"
awk '{c[$1]++} END {for (k in c) printf("  %s %d\n", k, c[k])}' "${tmp}/results.txt" | sort -n

echo "latency_summary_seconds:"
awk '
{
  t=$2+0;
  if (NR==1 || t<min) min=t;
  if (NR==1 || t>max) max=t;
  sum+=t;
}
END {
  if (NR==0) { print "  no_samples"; exit; }
  printf("  count=%d avg=%.3f min=%.3f max=%.3f\n", NR, sum/NR, min, max);
}' "${tmp}/results.txt"

echo "sample_first_30:"
sed -n '1,30p' "${tmp}/results.txt"

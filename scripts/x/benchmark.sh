#!/usr/bin/env bash
set -euo pipefail

OUT_DIR="${1:-./artifacts/phase2-bench}"
BASE_URL="${BASE_URL:-http://127.0.0.1}"
CHALLENGE_PATH="${CHALLENGE_PATH:-/__pteroprotect/challenge/check-web}"
API_PATH="${API_PATH:-/api/client}"
SESSION_PATH="${SESSION_PATH:-/__pteroprotect/challenge/check-token}"
REQUESTS_STEADY="${REQUESTS_STEADY:-1000}"
REQUESTS_BURST="${REQUESTS_BURST:-10000}"
CONCURRENCY_STEADY="${CONCURRENCY_STEADY:-50}"
CONCURRENCY_BURST="${CONCURRENCY_BURST:-250}"
TIMEOUT_SEC="${TIMEOUT_SEC:-8}"

mkdir -p "$OUT_DIR"
RAW_CSV="$OUT_DIR/raw.csv"
JSON_OUT="$OUT_DIR/summary.json"
MD_OUT="$OUT_DIR/summary.md"

: > "$RAW_CSV"

have_cmd() { command -v "$1" >/dev/null 2>&1; }

if ! have_cmd curl; then
  echo "curl is required" >&2
  exit 2
fi

run_case() {
  local scenario="$1" requests="$2" concurrency="$3" url="$4"
  echo "[bench] scenario=$scenario requests=$requests concurrency=$concurrency url=$url"

  seq "$requests" | xargs -P "$concurrency" -I{} bash -lc '
    out="$(curl -sS -o /dev/null -w "%{http_code},%{time_total}" --max-time "'"$TIMEOUT_SEC"'" "'"$url"'" 2>/dev/null || true)"
    if [[ -z "$out" ]]; then
      echo "599,9.999999"
    else
      echo "$out"
    fi
  ' | awk -F',' -v s="$scenario" '{print s "," $1 "," $2}' >> "$RAW_CSV"
}

run_case "challenge_steady" "$REQUESTS_STEADY" "$CONCURRENCY_STEADY" "$BASE_URL$CHALLENGE_PATH"
run_case "api_steady" "$REQUESTS_STEADY" "$CONCURRENCY_STEADY" "$BASE_URL$API_PATH"
run_case "session_steady" "$REQUESTS_STEADY" "$CONCURRENCY_STEADY" "$BASE_URL$SESSION_PATH"
run_case "challenge_burst" "$REQUESTS_BURST" "$CONCURRENCY_BURST" "$BASE_URL$CHALLENGE_PATH"
run_case "api_burst" "$REQUESTS_BURST" "$CONCURRENCY_BURST" "$BASE_URL$API_PATH"
run_case "session_burst" "$REQUESTS_BURST" "$CONCURRENCY_BURST" "$BASE_URL$SESSION_PATH"

summarize() {
  local scenario="$1"
  local tmp="$OUT_DIR/.${scenario}.times"
  awk -F',' -v s="$scenario" '$1==s {print $3+0}' "$RAW_CSV" | sort -n > "$tmp"
  local n
  n="$(wc -l < "$tmp" | tr -d ' ')"
  if [[ "$n" -eq 0 ]]; then
    echo "$scenario,0,0,0,0,0,0,0,0,0,0"
    return
  fi

  local p50_i p95_i p99_i
  p50_i=$(( (n * 50 + 99) / 100 ))
  p95_i=$(( (n * 95 + 99) / 100 ))
  p99_i=$(( (n * 99 + 99) / 100 ))
  (( p50_i < 1 )) && p50_i=1
  (( p95_i < 1 )) && p95_i=1
  (( p99_i < 1 )) && p99_i=1
  (( p50_i > n )) && p50_i=$n
  (( p95_i > n )) && p95_i=$n
  (( p99_i > n )) && p99_i=$n

  local p50 p95 p99 mean
  p50="$(sed -n "${p50_i}p" "$tmp")"
  p95="$(sed -n "${p95_i}p" "$tmp")"
  p99="$(sed -n "${p99_i}p" "$tmp")"
  mean="$(awk '{s+=$1} END {if (NR==0) print 0; else printf "%.6f", s/NR}' "$tmp")"

  local counters
  counters="$(awk -F',' -v s="$scenario" '
    $1==s {
      if ($2 !~ /^2/) err++;
      if ($2==401) c401++;
      if ($2==403) c403++;
      if ($2==429) c429++;
      total++;
    }
    END {
      if (total==0) { print "0,0,0,0,0"; exit; }
      printf "%d,%.6f,%d,%d,%d", err+0, (err+0)/total, c401+0, c403+0, c429+0;
    }
  ' "$RAW_CSV")"
  IFS=',' read -r errors err_rate c401 c403 c429 <<< "$counters"
  echo "$scenario,$n,$mean,$p50,$p95,$p99,$errors,$err_rate,$c401,$c403,$c429"
}

SCENARIOS=(challenge_steady api_steady session_steady challenge_burst api_burst session_burst)

{
  echo '{'
  echo '  "base_url": '"\"$BASE_URL\""','
  echo '  "generated_at_utc": '"\"$(date -u +%FT%TZ)\""','
  echo '  "results": ['
  first=1
  for s in "${SCENARIOS[@]}"; do
    row="$(summarize "$s")"
    IFS=',' read -r scenario total mean p50 p95 p99 errors err_rate c401 c403 c429 <<< "$row"
    if [[ $first -eq 0 ]]; then echo '    ,'; fi
    first=0
    printf '    {"scenario":"%s","total":%s,"mean_sec":%s,"p50_sec":%s,"p95_sec":%s,"p99_sec":%s,"errors":%s,"error_rate":%s,"code_401":%s,"code_403":%s,"code_429":%s}' \
      "$scenario" "$total" "$mean" "$p50" "$p95" "$p99" "$errors" "$err_rate" "$c401" "$c403" "$c429"
    echo
  done
  echo '  ]'
  echo '}'
} > "$JSON_OUT"

{
  echo "# Phase 2 Benchmark Summary"
  echo
  echo "- Base URL: $BASE_URL"
  echo "- Generated: $(date -u +%FT%TZ)"
  echo
  echo "| Scenario | Total | Mean (s) | p50 (s) | p95 (s) | p99 (s) | Errors | Error Rate | 401 | 403 | 429 |"
  echo "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|"
  for s in "${SCENARIOS[@]}"; do
    row="$(summarize "$s")"
    IFS=',' read -r scenario total mean p50 p95 p99 errors err_rate c401 c403 c429 <<< "$row"
    echo "| $scenario | $total | $mean | $p50 | $p95 | $p99 | $errors | $err_rate | $c401 | $c403 | $c429 |"
  done
} > "$MD_OUT"

echo "[bench] done"
echo "[bench] raw: $RAW_CSV"
echo "[bench] json: $JSON_OUT"
echo "[bench] md: $MD_OUT"

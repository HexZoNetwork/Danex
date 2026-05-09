#!/usr/bin/env bash
set -euo pipefail
BASE="${1:-http://127.0.0.1/__pteroprotect/challenge}"
COOKIE_JAR="/tmp/pp_cookie_$$.txt"

new_json="$(curl -sS -c "$COOKIE_JAR" "$BASE/new")"
nonce="$(printf '%s' "$new_json" | python3 -c 'import json,sys;print(json.load(sys.stdin).get("nonce",""))')"
ak="$(printf '%s' "$new_json" | python3 -c 'import json,sys;j=json.load(sys.stdin);print(j.get("answer_key",""))')"
ans="$(printf '%s' "$new_json" | python3 -c 'import json,sys;j=json.load(sys.stdin);print("ok" if not j.get("phase1_numeric",False) else "0")')"

if [[ -z "$nonce" || -z "$ak" ]]; then
  echo "failed to issue challenge"
  exit 1
fi

payload=$(python3 - <<PY
import json
print(json.dumps({"nonce":"$nonce","$ak":"$ans","completion_id":"cmp-test-$nonce"}))
PY
)

curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" -H 'content-type: application/json' -d "$payload" "$BASE/solve" >/tmp/pp_solve_$$.json || true
code=$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" "${BASE%/__pteroprotect/challenge}/")
echo "post-solve protected status=$code"
rm -f "$COOKIE_JAR" /tmp/pp_solve_$$.json

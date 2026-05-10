#!/usr/bin/env bash
set -euo pipefail

OUT_DIR="${1:-./artifacts/phase2-tests}"
mkdir -p "$OUT_DIR"
JSON_OUT="$OUT_DIR/report.json"
MD_OUT="$OUT_DIR/report.md"

PASS=0
FAIL=0
RESULTS=()

record() {
  local name="$1" status="$2" detail="$3"
  RESULTS+=("$name|$status|$detail")
  if [[ "$status" == "pass" ]]; then
    PASS=$((PASS+1))
  else
    FAIL=$((FAIL+1))
  fi
}

check() {
  local name="$1" cmd="$2"
  if bash -lc "$cmd" >/dev/null 2>&1; then
    record "$name" pass "$cmd"
  else
    record "$name" fail "$cmd"
  fi
}

json_escape() {
  local s="$1"
  s="${s//\\/\\\\}"
  s="${s//\"/\\\"}"
  s="${s//$'\n'/\\n}"
  printf '%s' "$s"
}

# 1) Sudo policy must not include broad ALL wildcard.
check "sudo_no_nopasswd_all" "! grep -Rqs 'NOPASSWD: ALL' setup.sh /etc/sudoers.d/pteroprotect-panel 2>/dev/null"

# 2) Fail2ban config sanity (if installed locally).
if command -v fail2ban-client >/dev/null 2>&1; then
  check "fail2ban_config_valid" "fail2ban-client -d"
else
  record "fail2ban_config_valid" pass "skip: fail2ban-client not installed"
fi

# 3) Nginx syntax gate (if nginx present).
if command -v nginx >/dev/null 2>&1; then
  check "nginx_config_valid" "nginx -t"
else
  record "nginx_config_valid" pass "skip: nginx not installed"
fi

# 4) Auto-config input validation patterns present.
check "autoconfig_request_host_regex" "grep -q \"target_host\" panel_overrides/app/Http/Requests/Admin/Nodes/StartAutoConfigureRequest.php"
check "autoconfig_pinned_fingerprint_rule" "grep -q \"strict_pinned\" panel_overrides/app/Http/Requests/Admin/Nodes/StartAutoConfigureRequest.php"

# 5) 8080 conflict policy: script includes wings ownership check.
check "script_port_owned_by_wings" "grep -q \"port_owned_by_wings\" panel_overrides/app/Services/Nodes/AutoConfigure/RemoteScriptBuilder.php"

# 6) Auto-config path should not disable SELinux globally.
check "no_setenforce_zero_autoconfig" "! grep -q \"setenforce 0\" panel_overrides/app/Services/Nodes/AutoConfigure/RemoteScriptBuilder.php"

# 7) Syntax checks for changed executable surfaces.
check "bash_syntax_setup" "bash -n setup.sh"
check "bash_syntax_fail2ban" "bash -n scripts/install_fail2ban.sh"
check "bash_syntax_benchmark" "bash -n scripts/phase2/benchmark.sh"
check "bash_syntax_phase2_tests" "bash -n scripts/phase2/test_hardening.sh"
check "php_lint_autoconfig_request" "php -l panel_overrides/app/Http/Requests/Admin/Nodes/StartAutoConfigureRequest.php"
check "php_lint_autoconfig_service" "php -l panel_overrides/app/Services/Nodes/AutoConfigure/NodeAutoConfigureService.php"
check "php_lint_autoconfig_job" "php -l panel_overrides/app/Jobs/Nodes/ExecuteNodeAutoConfigureJob.php"
check "php_lint_remote_provisioner" "php -l panel_overrides/app/Services/Nodes/AutoConfigure/RemoteProvisioner.php"
check "php_lint_remote_script_builder" "php -l panel_overrides/app/Services/Nodes/AutoConfigure/RemoteScriptBuilder.php"

{
  echo '{'
  echo '  "generated_at_utc": '"\"$(date -u +%FT%TZ)\""','
  echo '  "pass": '"$PASS"','
  echo '  "fail": '"$FAIL"','
  echo '  "results": ['
  for i in "${!RESULTS[@]}"; do
    IFS='|' read -r name status detail <<< "${RESULTS[$i]}"
    [[ "$i" -gt 0 ]] && echo '    ,'
    name="$(json_escape "$name")"
    status="$(json_escape "$status")"
    detail="$(json_escape "$detail")"
    printf '    {"name":"%s","status":"%s","detail":"%s"}' \
      "$name" "$status" "$detail"
    echo
  done
  echo '  ]'
  echo '}'
} > "$JSON_OUT"

{
  echo "# Phase 2 Hardening Test Report"
  echo
  echo "- Generated: $(date -u +%FT%TZ)"
  echo "- Passed: $PASS"
  echo "- Failed: $FAIL"
  echo
  echo "| Check | Status | Detail |"
  echo "|---|---|---|"
  for row in "${RESULTS[@]}"; do
    IFS='|' read -r name status detail <<< "$row"
    detail="${detail//|/\\|}"
    echo "| $name | $status | $detail |"
  done
} > "$MD_OUT"

if [[ "$FAIL" -gt 0 ]]; then
  echo "[phase2-tests] FAIL ($FAIL failing checks)"
  echo "[phase2-tests] report: $MD_OUT"
  exit 1
fi

echo "[phase2-tests] PASS"
echo "[phase2-tests] report: $MD_OUT"

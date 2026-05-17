#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUN_LIVE=0
RUN_BUILD=0
INCLUDE_LIVE_CONFIG=0

usage() {
    cat <<'EOF'
Usage: scripts/validate_all.sh [--live] [--build] [--include-live-config]

Default validation is static and non-destructive. It does not reload services,
install packages, mutate firewall state, or contact external services.

Options:
  --live                 Also run host-local nginx/fail2ban config checks.
  --build                Also run make -j2.
  --include-live-config  Include config.json/config.runtime.json in secret scan.
EOF
}

log() { printf '[validate] %s\n' "$*"; }
warn() { printf '[validate] warning: %s\n' "$*" >&2; }
fail() { printf '[validate] error: %s\n' "$*" >&2; exit 1; }
have() { command -v "$1" >/dev/null 2>&1; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --live) RUN_LIVE=1 ;;
        --build) RUN_BUILD=1 ;;
        --include-live-config) INCLUDE_LIVE_CONFIG=1 ;;
        -h|--help) usage; exit 0 ;;
        *) fail "unknown argument: $1" ;;
    esac
    shift
done

cd "${ROOT_DIR}"

tracked_files() {
    git ls-files \
        ':!.codespaces/**' \
        ':!backups/**' \
        ':!panel_overrides/public/assets/**' \
        ':!**/__pycache__/**' \
        ':!*.pyc'
}

run_bash_syntax() {
    log "checking shell syntax"
    local failed=0 file
    while IFS= read -r file; do
        [[ "${file}" == *.sh || "${file}" == "setup.sh" || "${file}" == "check.sh" || "${file}" == "code.sh" ]] || continue
        if ! bash -n "${file}"; then
            failed=1
        fi
    done < <(tracked_files)
    (( failed == 0 )) || fail "shell syntax failed"
}

run_python_compile() {
    log "checking Python syntax"
    local failed=0 file
    while IFS= read -r file; do
        [[ "${file}" == *.py ]] || continue
        if ! python3 - "${file}" <<'PY'
import pathlib
import sys
path = pathlib.Path(sys.argv[1])
source = path.read_text(encoding="utf-8", errors="replace")
compile(source, str(path), "exec")
PY
        then
            failed=1
        fi
    done < <(tracked_files)
    (( failed == 0 )) || fail "Python syntax failed"
}

run_php_lint() {
    if ! have php; then
        warn "php not found; skipping PHP lint"
        return 0
    fi
    log "checking PHP syntax"
    local failed=0 file
    while IFS= read -r file; do
        [[ "${file}" == *.php ]] || continue
        if ! php -l "${file}" >/dev/null; then
            failed=1
        fi
    done < <(tracked_files)
    (( failed == 0 )) || fail "PHP lint failed"
}

run_systemd_verify() {
    if ! have systemd-analyze; then
        warn "systemd-analyze not found; skipping unit verification"
        return 0
    fi
    log "checking systemd unit syntax"
    local failed=0 file
    while IFS= read -r file; do
        [[ "${file}" == systemd/*.service ]] || continue
        if ! systemd-analyze verify "${file}" >/dev/null; then
            failed=1
        fi
    done < <(tracked_files)
    (( failed == 0 )) || fail "systemd unit verification failed"
}

run_secret_scan() {
    log "running secret scanner self-test"
    python3 scripts/security_secret_scan.py --self-test
    log "running repository secret scan"
    if (( INCLUDE_LIVE_CONFIG == 1 )); then
        python3 scripts/security_secret_scan.py --include-live-config
    else
        python3 scripts/security_secret_scan.py
    fi
}

run_python_tests() {
    log "running Python regression tests"
    local failed=0 file found=0
    shopt -s nullglob
    for file in tests/test_*.py; do
        found=1
        if ! python3 "${file}"; then
            failed=1
        fi
    done
    shopt -u nullglob
    (( found == 1 )) || return 0
    (( failed == 0 )) || fail "Python regression tests failed"
}

run_live_checks() {
    (( RUN_LIVE == 1 )) || return 0
    log "running live local config checks"
    if have nginx; then
        nginx -t
    else
        warn "nginx not found; skipping nginx -t"
    fi
    if have fail2ban-client; then
        fail2ban-client -t
    else
        warn "fail2ban-client not found; skipping fail2ban-client -t"
    fi
}

run_build() {
    (( RUN_BUILD == 1 )) || return 0
    log "running C++ build"
    make -j2
}

run_bash_syntax
run_python_compile
run_php_lint
run_systemd_verify
run_secret_scan
run_python_tests
run_live_checks
run_build

log "all requested checks passed"

#!/usr/bin/env bash
set -euo pipefail

RUNTIME_DIR="/dev/shm/pteroprotect"
MODE_FILE="${RUNTIME_DIR}/mode.flag"
LOCKDOWN_FILE="${RUNTIME_DIR}/strict_lockdown.flag"
MODE="${1:-status}"
TTL="${2:-600}"

mkdir -p "${RUNTIME_DIR}"

write_mode() {
    local mode="$1"
    local now
    now="$(date +%s)"
    printf '{"mode":"%s","updated_at":%s}\n' "${mode}" "${now}" > "${MODE_FILE}"
}

write_lockdown() {
    local ttl="$1"
    local now until
    now="$(date +%s)"
    until=$(( now + ttl ))
    printf '{"enabled":true,"reason":"manual-mode","until":%s,"updated_at":%s}\n' "${until}" "${now}" > "${LOCKDOWN_FILE}"
}

clear_lockdown() {
    rm -f "${LOCKDOWN_FILE}"
}

status() {
    echo "mode_file=${MODE_FILE}"
    if [[ -f "${MODE_FILE}" ]]; then
        cat "${MODE_FILE}"
    else
        echo '{"mode":"normal"}'
    fi
    echo "---"
    echo "lockdown_file=${LOCKDOWN_FILE}"
    if [[ -f "${LOCKDOWN_FILE}" ]]; then
        cat "${LOCKDOWN_FILE}"
    else
        echo '{"enabled":false}'
    fi
}

case "${MODE}" in
    normal)
        write_mode "normal"
        clear_lockdown
        ;;
    aggressive)
        write_mode "aggressive"
        clear_lockdown
        ;;
    emergency)
        write_mode "emergency"
        write_lockdown "${TTL}"
        ;;
    lockdown)
        write_lockdown "${TTL}"
        ;;
    clear-lockdown)
        clear_lockdown
        ;;
    status)
        status
        exit 0
        ;;
    *)
        echo "usage: $0 {status|normal|aggressive|emergency [ttl]|lockdown [ttl]|clear-lockdown}" >&2
        exit 1
        ;;
esac

status

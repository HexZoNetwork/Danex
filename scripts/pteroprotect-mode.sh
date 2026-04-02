#!/usr/bin/env bash
set -euo pipefail

SHM_RUNTIME_DIR="${PTEROPROTECT_RUNTIME_DIR:-/dev/shm/pteroprotect}"
PANEL_RUNTIME_DIR="${PTEROPROTECT_PANEL_RUNTIME_DIR:-/pteroprotect/runtime}"
MODE="${1:-status}"
TTL="${2:-600}"

mkdir -p "${SHM_RUNTIME_DIR}" "${PANEL_RUNTIME_DIR}"

MODE_FILES=(
    "${SHM_RUNTIME_DIR}/mode.flag"
    "${PANEL_RUNTIME_DIR}/mode.json"
)
LOCKDOWN_FILES=(
    "${SHM_RUNTIME_DIR}/strict_lockdown.flag"
    "${PANEL_RUNTIME_DIR}/lockdown.json"
)

write_json_to_targets() {
    local payload="$1"
    shift
    local file
    for file in "$@"; do
        printf '%s\n' "${payload}" > "${file}"
    done
}

remove_targets() {
    local file
    for file in "$@"; do
        rm -f "${file}"
    done
}

write_mode() {
    local mode="$1"
    local now
    now="$(date +%s)"
    write_json_to_targets \
        "$(printf '{"mode":"%s","updated_at":%s}' "${mode}" "${now}")" \
        "${MODE_FILES[@]}"
}

write_lockdown() {
    local ttl="$1"
    local now until
    now="$(date +%s)"
    until=$(( now + ttl ))
    write_json_to_targets \
        "$(printf '{"enabled":true,"reason":"manual-mode","until":%s,"updated_at":%s}' "${until}" "${now}")" \
        "${LOCKDOWN_FILES[@]}"
}

clear_lockdown() {
    remove_targets "${LOCKDOWN_FILES[@]}"
}

clear_mode() {
    remove_targets "${MODE_FILES[@]}"
}

status() {
    local file

    echo "mode_targets:"
    for file in "${MODE_FILES[@]}"; do
        echo "  ${file}"
        if [[ -f "${file}" ]]; then
            cat "${file}"
        else
            echo '{"mode":"normal"}'
        fi
    done

    echo "---"
    echo "lockdown_targets:"
    for file in "${LOCKDOWN_FILES[@]}"; do
        echo "  ${file}"
        if [[ -f "${file}" ]]; then
            cat "${file}"
        else
            echo '{"enabled":false}'
        fi
    done
}

case "${MODE}" in
    normal)
        clear_mode
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

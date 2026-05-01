#!/usr/bin/env bash
set -euo pipefail

SHM_RUNTIME_DIR="${PTEROPROTECT_RUNTIME_DIR:-/dev/shm/pteroprotect}"
PANEL_RUNTIME_DIR="${PTEROPROTECT_PANEL_RUNTIME_DIR:-/pteroprotect/runtime}"
MODE="${1:-status}"
TTL="${2:-600}"

mkdir -p "${SHM_RUNTIME_DIR}" "${PANEL_RUNTIME_DIR}"
chmod 2775 "${SHM_RUNTIME_DIR}" "${PANEL_RUNTIME_DIR}" >/dev/null 2>&1 || true

MODE_FILES=(
    "${SHM_RUNTIME_DIR}/mode.flag"
    "${PANEL_RUNTIME_DIR}/mode.json"
)
LOCKDOWN_FILES=(
    "${SHM_RUNTIME_DIR}/strict_lockdown.flag"
    "${PANEL_RUNTIME_DIR}/lockdown.json"
)

write_file_safely() {
    local file="$1"
    local payload="$2"

    mkdir -p "$(dirname "${file}")" >/dev/null 2>&1 || true
    if printf '%s\n' "${payload}" > "${file}" 2>/dev/null; then
        chmod 664 "${file}" >/dev/null 2>&1 || true
        return 0
    fi

    if command -v sudo >/dev/null 2>&1; then
        if printf '%s\n' "${payload}" | sudo -n tee "${file}" >/dev/null 2>&1; then
            sudo -n chmod 664 "${file}" >/dev/null 2>&1 || true
            return 0
        fi
    fi

    echo "Mode change failed: cannot write ${file} (permission denied)" >&2
    return 1
}

remove_file_safely() {
    local file="$1"
    if rm -f "${file}" 2>/dev/null; then
        return 0
    fi

    if command -v sudo >/dev/null 2>&1; then
        sudo -n rm -f "${file}" >/dev/null 2>&1 && return 0
    fi

    echo "Mode change failed: cannot remove ${file} (permission denied)" >&2
    return 1
}

write_json_to_targets() {
    local payload="$1"
    shift
    local file
    for file in "$@"; do
        write_file_safely "${file}" "${payload}"
    done
}

remove_targets() {
    local file
    for file in "$@"; do
        if ! remove_file_safely "${file}"; then
            return 1
        fi
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
    local now
    now="$(date +%s)"
    write_json_to_targets \
        "$(printf '{"enabled":false,"updated_at":%s}' "${now}")" \
        "${LOCKDOWN_FILES[@]}"
}

clear_mode() {
    write_mode "normal"
}

status() {
    local file

    echo "mode_targets:"
    for file in "${MODE_FILES[@]}"; do
        echo "  ${file}"
        if [[ -f "${file}" ]]; then
            cat "${file}" 2>/dev/null || echo '{"mode":"normal"}'
        else
            echo '{"mode":"normal"}'
        fi
    done

    echo "---"
    echo "lockdown_targets:"
    for file in "${LOCKDOWN_FILES[@]}"; do
        echo "  ${file}"
        if [[ -f "${file}" ]]; then
            cat "${file}" 2>/dev/null || echo '{"enabled":false}'
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

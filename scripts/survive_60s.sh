#!/usr/bin/env bash
set -euo pipefail

TTL="${2:-60}"
ACTION="${1:-status}"
RUNTIME_DIR="/dev/shm/pteroprotect"
BROWNOUT_FLAG="${RUNTIME_DIR}/brownout.flag"
STATE_FILE="${RUNTIME_DIR}/brownout.state.json"
LOCK_PORTS="${PTEROPROTECT_EDGE_PORTS:-80,443}"
EDGE_CIDRS="${PTEROPROTECT_EDGE_CIDRS:-}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

usage() {
    cat <<'USAGE'
Usage:
  survive_60s.sh status
  survive_60s.sh on [ttl_seconds]
  survive_60s.sh off

What "on" does:
  - enables brownout flag (heavy API/chat/resources/websocket get 503/429)
  - switches WAF mode to emergency with TTL
  - applies edge-origin cloak (80/443 allow edge CIDR only)
  - reloads nginx safely
  - auto-disables after TTL in background
USAGE
}

mkdir -p "${RUNTIME_DIR}"

now_epoch() { date +%s; }

write_state() {
    local ttl="$1"
    local now until
    now="$(now_epoch)"
    until=$(( now + ttl ))
    printf '{"enabled":true,"until":%s,"updated_at":%s}\n' "${until}" "${now}" > "${STATE_FILE}"
}

status() {
    local now enabled_until enabled_state
    now="$(now_epoch)"
    enabled_until=0
    enabled_state=0
    if [[ -f "${STATE_FILE}" ]]; then
        enabled_until="$(awk -F'"until":' '{print $2}' "${STATE_FILE}" | awk -F',' '{print $1}' | tr -cd '0-9' || true)"
        [[ -n "${enabled_until}" ]] || enabled_until=0
    fi

    # Auto-heal stale runtime when TTL already elapsed.
    if [[ "${enabled_until}" -gt 0 && "${enabled_until}" -le "${now}" ]]; then
        rm -f "${BROWNOUT_FLAG}" "${STATE_FILE}"
        enabled_until=0
    fi

    echo "brownout_flag=${BROWNOUT_FLAG}"
    if [[ -f "${BROWNOUT_FLAG}" ]]; then
        enabled_state=1
    elif [[ "${enabled_until}" -gt "${now}" ]]; then
        enabled_state=1
    fi
    if [[ "${enabled_state}" -eq 1 ]]; then
        echo "brownout_enabled=1"
    else
        echo "brownout_enabled=0"
    fi
    echo "brownout_state=${STATE_FILE}"
    if [[ -f "${STATE_FILE}" ]]; then
        cat "${STATE_FILE}"
    else
        echo '{"enabled":false}'
    fi
}

enable_brownout() {
    local ttl="$1"
    [[ "${ttl}" =~ ^[0-9]+$ ]] || ttl=60
    if (( ttl < 30 )); then ttl=30; fi
    if (( ttl > 3600 )); then ttl=3600; fi

    touch "${BROWNOUT_FLAG}"
    write_state "${ttl}"

    if [[ -x "${SCRIPT_DIR}/pteroprotect-mode.sh" ]]; then
        bash "${SCRIPT_DIR}/pteroprotect-mode.sh" emergency "${ttl}" >/dev/null || true
    fi

    if [[ -x "${SCRIPT_DIR}/edge_origin_cloak.sh" ]]; then
        bash "${SCRIPT_DIR}/edge_origin_cloak.sh" apply "${LOCK_PORTS}" "${EDGE_CIDRS}" >/dev/null || true
    fi

    nginx -t >/dev/null
    systemctl reload nginx >/dev/null 2>&1 || nginx -s reload >/dev/null 2>&1 || true
    systemctl restart pteroprotect-ddoslog >/dev/null 2>&1 || true
    systemctl restart fail2ban >/dev/null 2>&1 || true

    # Auto-off in background.
    nohup bash -c "
        sleep ${ttl}
        if [[ -f '${BROWNOUT_FLAG}' ]]; then
            rm -f '${BROWNOUT_FLAG}' '${STATE_FILE}'
            if [[ -x '${SCRIPT_DIR}/pteroprotect-mode.sh' ]]; then
                bash '${SCRIPT_DIR}/pteroprotect-mode.sh' aggressive >/dev/null 2>&1 || true
            fi
            nginx -t >/dev/null 2>&1 && (systemctl reload nginx >/dev/null 2>&1 || nginx -s reload >/dev/null 2>&1 || true)
        fi
    " >/dev/null 2>&1 &

    echo "brownout_on=1 ttl=${ttl}"
    status
}

disable_brownout() {
    rm -f "${BROWNOUT_FLAG}" "${STATE_FILE}"
    if [[ -x "${SCRIPT_DIR}/pteroprotect-mode.sh" ]]; then
        bash "${SCRIPT_DIR}/pteroprotect-mode.sh" aggressive >/dev/null || true
    fi
    nginx -t >/dev/null
    systemctl reload nginx >/dev/null 2>&1 || nginx -s reload >/dev/null 2>&1 || true
    echo "brownout_on=0"
    status
}

case "${ACTION}" in
    status)
        status
        ;;
    on)
        enable_brownout "${TTL}"
        ;;
    off)
        disable_brownout
        ;;
    *)
        usage
        exit 1
        ;;
esac

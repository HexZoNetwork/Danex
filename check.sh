#!/usr/bin/env bash
set -euo pipefail

RUNTIME_DIR="/dev/shm/pteroprotect"
WAF_LOG="${RUNTIME_DIR}/waf.log"
DDOS_LATEST="${RUNTIME_DIR}/ddos_host.latest"
ACCESS_LOG="/var/log/nginx/pteroprotect.access.log"
SQLI_LOG="/var/log/nginx/pteroprotect.sqli.log"
HOST_IPS_CSV="$(hostname -I 2>/dev/null | tr ' ' '\n' | sed '/^$/d' | paste -sd, -)"

LIVE=0
INTERVAL=2
LIVE_RENDER_INIT=0
LIVE_LAST_LINES=0
LIVE_CAN_REWRITE=0
LIVE_ALT_ACTIVE=0
COMPACT=0
FORCE_FULL=0
PREV_BW_RX=0
PREV_BW_TX=0
PREV_BW_TS=0

usage() {
    cat <<USAGE
Usage: $0 [--live] [--interval SEC]

Options:
  --live            refresh output continuously
  --interval SEC    refresh interval for --live (default: 2)
  --compact         compact output (recommended for live mode)
  --full            force full output (disable compact auto-live)
  -h, --help        show this help
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --live)
            LIVE=1
            shift
            ;;
        --interval)
            [[ $# -ge 2 ]] || { echo "missing value for --interval" >&2; exit 1; }
            INTERVAL="$2"
            shift 2
            ;;
        --compact)
            COMPACT=1
            shift
            ;;
        --full)
            FORCE_FULL=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "unknown argument: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if ! [[ "${INTERVAL}" =~ ^[0-9]+$ ]] || (( INTERVAL < 1 )); then
    echo "--interval must be integer >= 1" >&2
    exit 1
fi

if [[ "${LIVE}" == "1" && "${FORCE_FULL}" != "1" ]]; then
    COMPACT=1
fi

section() {
    printf '\n== %s ==\n' "$1"
}

have_cmd() {
    command -v "$1" >/dev/null 2>&1
}

ip_family() {
    local ip="$1"
    ip="${ip#\[}"
    ip="${ip%\]}"
    ip="${ip%%,*}"
    if [[ "${ip}" == ::ffff:* ]]; then
        echo "ipv4"
    elif [[ "${ip}" == *:* ]]; then
        echo "ipv6"
    else
        echo "ipv4"
    fi
}

detect_live_rewrite_support() {
    if [[ ! -t 1 ]]; then
        LIVE_CAN_REWRITE=0
        return
    fi
    if [[ -z "${TERM:-}" || "${TERM:-}" == "dumb" ]]; then
        LIVE_CAN_REWRITE=0
        return
    fi
    LIVE_CAN_REWRITE=1
}

live_enter_screen() {
    if [[ "${LIVE_CAN_REWRITE}" == "1" && "${LIVE_ALT_ACTIVE}" == "0" ]]; then
        printf '\033[?1049h\033[?25l'
        LIVE_ALT_ACTIVE=1
    fi
}

live_leave_screen() {
    if [[ "${LIVE_ALT_ACTIVE}" == "1" ]]; then
        printf '\033[?25h\033[?1049l'
        LIVE_ALT_ACTIVE=0
    fi
}

human_rate() {
    local bps="${1:-0}"
    local units=("B/s" "KB/s" "MB/s" "GB/s" "TB/s")
    local i=0
    local value="$bps"
    while (( value >= 1024 && i < ${#units[@]} - 1 )); do
        value=$(( value / 1024 ))
        i=$(( i + 1 ))
    done
    printf '%s %s' "$value" "${units[$i]}"
}

read_total_bw_bytes() {
    awk '
        NR > 2 {
            iface=$1
            sub(/:$/, "", iface)
            if (iface == "" || iface == "lo") next
            rx += $2
            tx += $10
        }
        END {printf "%s %s\n", rx+0, tx+0}
    ' /proc/net/dev 2>/dev/null
}

print_service_status() {
    local svc="$1"
    if ! have_cmd systemctl; then
        printf '%-28s %s\n' "${svc}" "systemctl unavailable"
        return
    fi
    local state enabled
    state="$(systemctl is-active "${svc}" 2>/dev/null || true)"
    enabled="$(systemctl is-enabled "${svc}" 2>/dev/null || true)"
    [[ -n "${state}" ]] || state="unknown"
    [[ -n "${enabled}" ]] || enabled="unknown"
    printf '%-28s active=%-10s enabled=%s\n' "${svc}" "${state}" "${enabled}"
}

show_waf() {
    section "WAF"
    if [[ ! -f "${WAF_LOG}" ]]; then
        echo "waf log not found: ${WAF_LOG}"
        return
    fi

    local total deny signature lockdown emergency perip global
    total="$(wc -l < "${WAF_LOG}" 2>/dev/null || echo 0)"
    deny="$(grep -c 'action=deny' "${WAF_LOG}" 2>/dev/null || true)"
    signature="$(grep -c 'reason=signature' "${WAF_LOG}" 2>/dev/null || true)"
    lockdown="$(grep -c 'reason=lockdown-path' "${WAF_LOG}" 2>/dev/null || true)"
    emergency="$(grep -c 'reason=emergency-path' "${WAF_LOG}" 2>/dev/null || true)"
    perip="$(grep -c 'reason=per-ip' "${WAF_LOG}" 2>/dev/null || true)"
    global="$(grep -c 'reason=global' "${WAF_LOG}" 2>/dev/null || true)"

    echo "waf_lines=${total} deny=${deny} signature=${signature} lockdown=${lockdown} emergency=${emergency} per_ip=${perip} global=${global}"
    echo "-- latest deny --"
    grep 'action=deny' "${WAF_LOG}" 2>/dev/null | tail -n 8 || echo "none"
}

show_waf_compact() {
    section "WAF"
    if [[ ! -f "${WAF_LOG}" ]]; then
        echo "waf log not found: ${WAF_LOG}"
        return
    fi
    local total deny signature lockdown emergency perip global
    total="$(wc -l < "${WAF_LOG}" 2>/dev/null || echo 0)"
    deny="$(grep -c 'action=deny' "${WAF_LOG}" 2>/dev/null || true)"
    signature="$(grep -c 'reason=signature' "${WAF_LOG}" 2>/dev/null || true)"
    lockdown="$(grep -c 'reason=lockdown-path' "${WAF_LOG}" 2>/dev/null || true)"
    emergency="$(grep -c 'reason=emergency-path' "${WAF_LOG}" 2>/dev/null || true)"
    perip="$(grep -c 'reason=per-ip' "${WAF_LOG}" 2>/dev/null || true)"
    global="$(grep -c 'reason=global' "${WAF_LOG}" 2>/dev/null || true)"
    echo "lines=${total} deny=${deny} sig=${signature} lockdown=${lockdown} emergency=${emergency} per_ip=${perip} global=${global}"
}

show_incoming() {
    section "INCOME (incoming)"
    if ! have_cmd ss; then
        echo "ss command unavailable"
        return
    fi
    local ports_re=':(80|443|8080|2022)$'
    local established syn_recv time_wait
    established="$(ss -tn state established 2>/dev/null | awk 'NR>1 {print $3}' | grep -E "${ports_re}" | wc -l || true)"
    syn_recv="$(ss -tn state syn-recv 2>/dev/null | awk 'NR>1 {print $3}' | grep -E "${ports_re}" | wc -l || true)"
    time_wait="$(ss -tn state time-wait 2>/dev/null | awk 'NR>1 {print $3}' | grep -E "${ports_re}" | wc -l || true)"
    echo "established=${established} syn_recv=${syn_recv} time_wait=${time_wait}"
    echo "-- top remote incoming --"
    ss -tn state established 2>/dev/null | awk -v ports_re="${ports_re}" '
        NR>1 && $3 ~ ports_re {
            remote=$4
            if (remote ~ /^\[/) {sub(/^\[/, "", remote); sub(/\]:[0-9]+$/, "", remote)}
            else {sub(/:[0-9]+$/, "", remote)}
            print remote
        }' | sed '/^$/d' | sort | uniq -c | sort -nr | head -n 10 || echo "none"
    echo "-- incoming ip family --"
    ss -tn state established 2>/dev/null | awk -v ports_re="${ports_re}" '
        NR>1 && $3 ~ ports_re {
            remote=$4
            if (remote ~ /^\[/) {sub(/^\[/, "", remote); sub(/\]:[0-9]+$/, "", remote)}
            else {sub(/:[0-9]+$/, "", remote)}
            if (remote ~ /^::ffff:[0-9.]+$/ || remote ~ /^[0-9.]+$/) v4++
            else if (remote ~ /:/) v6++
        }
        END { printf "incoming_ipv4=%d incoming_ipv6=%d\n", v4+0, v6+0 }'
}

show_outgoing() {
    section "OUTCOME (outgoing)"
    if ! have_cmd ss; then
        echo "ss command unavailable"
        return
    fi

    local summary
    summary="$(ss -tn state established 2>/dev/null | awk -v ports_re=':(80|443|8080|2022)$' -v host_ips="${HOST_IPS_CSV}" '
        function strip_ip(value, ip) {
            ip = value
            gsub(/^\[/, "", ip)
            sub(/\]:[0-9]+$/, "", ip)
            sub(/:[0-9]+$/, "", ip)
            return ip
        }
        function is_private(ip) {
            if (ip == "127.0.0.1" || ip == "::1") return 1
            if (ip ~ /^10\./ || ip ~ /^192\.168\./) return 1
            if (ip ~ /^172\.(1[6-9]|2[0-9]|3[0-1])\./) return 1
            if (ip ~ /^169\.254\./) return 1
            if (ip ~ /^fc/ || ip ~ /^fd/ || ip ~ /^fe80:/) return 1
            return 0
        }
        function is_host(ip) {
            return index("," host_ips ",", "," ip ",") > 0
        }
        NR > 1 {
            if ($3 ~ ports_re) next
            remote_ip = strip_ip($4)
            total++
            if (remote_ip == "" || is_private(remote_ip) || is_host(remote_ip)) self_count++
            else public_count++
        }
        END {
            printf "outgoing_established=%d outgoing_public=%d outgoing_self=%d\n", total + 0, public_count + 0, self_count + 0
        }
    ')"
    echo "${summary}"
    echo "-- top remote outgoing --"
    ss -tn state established 2>/dev/null | awk -v ports_re=':(80|443|8080|2022)$' '
        NR>1 {
            local_addr=$3
            remote=$4
            if (local_addr ~ ports_re) next
            if (remote ~ /^\[/) {sub(/^\[/, "", remote); sub(/\]:[0-9]+$/, "", remote)}
            else {sub(/:[0-9]+$/, "", remote)}
            print remote
        }' | sed '/^$/d' | sort | uniq -c | sort -nr | head -n 10 || echo "none"
    echo "-- outgoing ip family --"
    ss -tn state established 2>/dev/null | awk -v ports_re=':(80|443|8080|2022)$' '
        NR>1 {
            local_addr=$3
            remote=$4
            if (local_addr ~ ports_re) next
            if (remote ~ /^\[/) {sub(/^\[/, "", remote); sub(/\]:[0-9]+$/, "", remote)}
            else {sub(/:[0-9]+$/, "", remote)}
            if (remote ~ /^::ffff:[0-9.]+$/ || remote ~ /^[0-9.]+$/) v4++
            else if (remote ~ /:/) v6++
        }
        END { printf "outgoing_ipv4=%d outgoing_ipv6=%d\n", v4+0, v6+0 }'
}

show_bandwidth() {
    section "BANDWIDTH"
    if [[ ! -r /proc/net/dev ]]; then
        echo "/proc/net/dev unavailable"
        return
    fi

    local rx tx now dt drx dtx rrx rtx
    read rx tx < <(read_total_bw_bytes)
    now="$(date +%s)"
    echo "total_rx_bytes=${rx} total_tx_bytes=${tx}"

    if (( PREV_BW_TS > 0 && now > PREV_BW_TS )); then
        dt=$(( now - PREV_BW_TS ))
        drx=$(( rx - PREV_BW_RX ))
        dtx=$(( tx - PREV_BW_TX ))
        (( drx < 0 )) && drx=0
        (( dtx < 0 )) && dtx=0
        rrx=$(( drx / dt ))
        rtx=$(( dtx / dt ))
        echo "rx_rate=$(human_rate "${rrx}") tx_rate=$(human_rate "${rtx}") sample_window=${dt}s"
    else
        echo "rx_rate=warming-up tx_rate=warming-up"
    fi

    PREV_BW_RX="${rx}"
    PREV_BW_TX="${tx}"
    PREV_BW_TS="${now}"
}

show_services_compact() {
    section "SERVICES"
    local p s d f
    p="$(systemctl is-active pteroprotect.service 2>/dev/null || true)"
    s="$(systemctl is-active pteroprotect-hostguard.service 2>/dev/null || true)"
    d="$(systemctl is-active pteroprotect-ddoslog.service 2>/dev/null || true)"
    f="$(systemctl is-active fail2ban.service 2>/dev/null || true)"
    [[ -n "${p}" ]] || p="unknown"
    [[ -n "${s}" ]] || s="unknown"
    [[ -n "${d}" ]] || d="unknown"
    [[ -n "${f}" ]] || f="unknown"
    echo "pteroprotect=${p} hostguard=${s} ddoslog=${d} fail2ban=${f}"
}

show_block_state() {
    section "BLOCK"
    if have_cmd ipset; then
        for set_name in \
            pteroprotect_block_v4 pteroprotect_block_v6 \
            pteroprotect_bw_probation_v4 pteroprotect_bw_bad_v4 pteroprotect_bw_worst_v4 \
            pteroprotect_bw_trusted_v4 pteroprotect_bw_vtrusted_v4 \
            pteroprotect_bw_probation_v6 pteroprotect_bw_bad_v6 pteroprotect_bw_worst_v6 \
            pteroprotect_bw_trusted_v6 pteroprotect_bw_vtrusted_v6; do
            if ipset list "${set_name}" >/dev/null 2>&1; then
                local count
                count="$(ipset list "${set_name}" 2>/dev/null | awk -F': ' '/Number of entries:/ {print $2; exit}')"
                [[ -n "${count}" ]] || count=0
                printf '%-32s entries=%s\n' "${set_name}" "${count}"
            fi
        done
    else
        echo "ipset command unavailable"
    fi

    if [[ -f "${DDOS_LATEST}" ]]; then
        echo "-- latest mitigation --"
        grep -E '\[mitigate\]|\[self-ddos-limit\]|\[ip-trust\]|\[mode\]' "${DDOS_LATEST}" 2>/dev/null | tail -n 20 || echo "none"
    fi
}

show_block_compact() {
    section "BLOCK"
    local sets count total
    total=0
    if have_cmd ipset; then
        sets="pteroprotect_block_v4 pteroprotect_block_v6 pteroprotect_bw_probation_v4 pteroprotect_bw_bad_v4 pteroprotect_bw_worst_v4 pteroprotect_bw_trusted_v4 pteroprotect_bw_vtrusted_v4 pteroprotect_bw_probation_v6 pteroprotect_bw_bad_v6 pteroprotect_bw_worst_v6 pteroprotect_bw_trusted_v6 pteroprotect_bw_vtrusted_v6"
        for set_name in ${sets}; do
            if ipset list "${set_name}" >/dev/null 2>&1; then
                count="$(ipset list "${set_name}" 2>/dev/null | awk -F': ' '/Number of entries:/ {print $2; exit}')"
                [[ -n "${count}" ]] || count=0
                total=$(( total + count ))
            fi
        done
        echo "total_ipset_entries=${total}"
    else
        echo "ipset command unavailable"
    fi
}

show_access() {
    section "ACCESS"
    if [[ ! -f "${ACCESS_LOG}" ]]; then
        echo "access log not found: ${ACCESS_LOG}"
        return
    fi
    echo "-- top IP --"
    tail -n 1500 "${ACCESS_LOG}" 2>/dev/null | awk '{print $1}' | sed 's/,.*//' | sed '/^$/d' | sort | uniq -c | sort -nr | head -n 10 || echo "none"
    echo "-- top IPv4 --"
    tail -n 1500 "${ACCESS_LOG}" 2>/dev/null | awk '{print $1}' | sed 's/,.*//' | sed '/^$/d' | awk '/^([0-9]{1,3}\.){3}[0-9]{1,3}$|^::ffff:[0-9.]+$/' | sort | uniq -c | sort -nr | head -n 5 || echo "none"
    echo "-- top IPv6 --"
    tail -n 1500 "${ACCESS_LOG}" 2>/dev/null | awk '{print $1}' | sed 's/,.*//' | sed '/^$/d' | awk '/:/' | grep -v '^::ffff:' | sort | uniq -c | sort -nr | head -n 5 || echo "none"
    echo "-- access ip family --"
    tail -n 1500 "${ACCESS_LOG}" 2>/dev/null | awk '
        {ip=$1; gsub(/,.*/, "", ip); gsub(/^\[/, "", ip); gsub(/\]$/, "", ip);
         if (ip ~ /^::ffff:[0-9.]+$/ || ip ~ /^([0-9]{1,3}\.){3}[0-9]{1,3}$/) v4++;
         else if (ip ~ /:/) v6++;
        }
        END { printf "access_ipv4=%d access_ipv6=%d\n", v4+0, v6+0 }'
    echo "-- top path --"
    tail -n 1500 "${ACCESS_LOG}" 2>/dev/null | awk 'NF >= 7 {print $7}' | sed '/^$/d' | sort | uniq -c | sort -nr | head -n 10 || echo "none"
    if [[ -f "${SQLI_LOG}" ]]; then
        local sqli_lines
        sqli_lines="$(wc -l < "${SQLI_LOG}" 2>/dev/null || echo 0)"
        echo "sqli_log_lines=${sqli_lines}"
    fi
}

show_access_compact() {
    section "ACCESS"
    if [[ ! -f "${ACCESS_LOG}" ]]; then
        echo "access log not found: ${ACCESS_LOG}"
        return
    fi
    echo "-- top IP --"
    tail -n 1000 "${ACCESS_LOG}" 2>/dev/null | awk '{print $1}' | sed 's/,.*//' | sed '/^$/d' | sort | uniq -c | sort -nr | head -n 3 || echo "none"
    tail -n 1000 "${ACCESS_LOG}" 2>/dev/null | awk '
        {ip=$1; gsub(/,.*/, "", ip); gsub(/^\[/, "", ip); gsub(/\]$/, "", ip);
         if (ip ~ /^::ffff:[0-9.]+$/ || ip ~ /^([0-9]{1,3}\.){3}[0-9]{1,3}$/) v4++;
         else if (ip ~ /:/) v6++;
        }
        END { printf "ipv4=%d ipv6=%d\n", v4+0, v6+0 }'
}

show_fail2ban() {
    section "FAIL2BAN"
    if ! have_cmd fail2ban-client; then
        echo "fail2ban-client unavailable"
        return
    fi

    fail2ban-client ping >/dev/null 2>&1 || {
        echo "fail2ban not running"
        return
    }

    echo "-- global status --"
    fail2ban-client status 2>/dev/null || true
    local jails jail
    jails="$(fail2ban-client status 2>/dev/null | awk -F': ' '/Jail list:/ {print $2}' | tr ',' ' ' || true)"
    for jail in ${jails}; do
        jail="$(echo "${jail}" | xargs)"
        [[ -n "${jail}" ]] || continue
        echo "-- jail ${jail} --"
        fail2ban-client status "${jail}" 2>/dev/null || true
    done
}

show_fail2ban_compact() {
    section "FAIL2BAN"
    if ! have_cmd fail2ban-client; then
        echo "fail2ban-client unavailable"
        return
    fi
    fail2ban-client ping >/dev/null 2>&1 || {
        echo "fail2ban=down"
        return
    }
    local jails
    jails="$(fail2ban-client status 2>/dev/null | sed -n 's/^.*Jail list:[[:space:]]*//p' | head -n 1 || true)"
    [[ -n "${jails}" ]] || jails="-"
    echo "running=up jails=${jails}"
}

render_report() {
    if [[ "${COMPACT}" == "1" ]]; then
        show_services_compact
    else
        section "SERVICES"
        print_service_status "pteroprotect.service"
        print_service_status "pteroprotect-hostguard.service"
        print_service_status "pteroprotect-ddoslog.service"
        print_service_status "fail2ban.service"
    fi

    show_bandwidth
    show_incoming
    show_outgoing
    if [[ "${COMPACT}" == "1" ]]; then
        show_waf_compact
        show_block_compact
        show_access_compact
        show_fail2ban_compact
    else
        show_waf
        show_block_state
        show_access
        show_fail2ban
    fi

    echo
    echo "check completed."
}

if [[ "${LIVE}" == "1" ]]; then
    detect_live_rewrite_support
    trap live_leave_screen EXIT INT TERM
    live_enter_screen
    while true; do
        report="$(
            echo "[live] refreshed: $(date -u +'%Y-%m-%d %H:%M:%S UTC') | interval=${INTERVAL}s | CTRL+C to stop"
            render_report
        )"

        if [[ "${LIVE_CAN_REWRITE}" == "1" ]]; then
            printf '\033[H\033[2J'
            printf '%s\n' "${report}"
            LIVE_LAST_LINES="$(printf '%s\n' "${report}" | wc -l | awk '{print $1}')"
            LIVE_RENDER_INIT=1
        else
            printf '%s\n' "${report}"
        fi

        sleep "${INTERVAL}"
    done
else
    render_report
fi

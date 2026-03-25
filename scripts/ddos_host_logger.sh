#!/usr/bin/env bash
set -euo pipefail

GUARD_HOME="${DANN_GUARD_HOME:-/pteroprotect}"
CONFIG_FILE="${GUARD_HOME}/config.json"
RUNTIME_DIR="/dev/shm/pteroprotect"
LOG_FILE="${RUNTIME_DIR}/ddos_host.log"
LATEST_FILE="${RUNTIME_DIR}/ddos_host.latest"
PORTS_REGEX=':(80|443|8080|2022)$'
IPSET4="pteroprotect_block_v4"
IPSET6="pteroprotect_block_v6"
BLOCK_ONLY_CHAIN4="PTEROPROTECT-DYNBLOCK"
BLOCK_ONLY_CHAIN6="PTEROPROTECT-DYNBLOCK-V6"
SELF_DDOS_RATE_IPSET4="pteroprotect_selfddos_rl_v4"
SELF_DDOS_RATE_IPSET6="pteroprotect_selfddos_rl_v6"
SELF_DDOS_RATE_CHAIN4="PTEROPROTECT-SELFDDOS-RL"
SELF_DDOS_RATE_CHAIN6="PTEROPROTECT-SELFDDOS-RL-V6"
SELF_DDOS_RATE_LIMIT_CHAIN_READY=0
BW_IPSET4_PROBATION="pteroprotect_bw_probation_v4"
BW_IPSET4_BAD="pteroprotect_bw_bad_v4"
BW_IPSET4_WORST="pteroprotect_bw_worst_v4"
BW_IPSET4_TRUSTED="pteroprotect_bw_trusted_v4"
BW_IPSET4_VTRUSTED="pteroprotect_bw_vtrusted_v4"
BW_IPSET6_PROBATION="pteroprotect_bw_probation_v6"
BW_IPSET6_BAD="pteroprotect_bw_bad_v6"
BW_IPSET6_WORST="pteroprotect_bw_worst_v6"
BW_IPSET6_TRUSTED="pteroprotect_bw_trusted_v6"
BW_IPSET6_VTRUSTED="pteroprotect_bw_vtrusted_v6"
HOST_IPS="$(hostname -I 2>/dev/null || true)"
WINGS_CONFIG="${PTEROPROTECT_WINGS_CONFIG:-/etc/pterodactyl/config.yml}"
PANEL_DIR="/var/www/pterodactyl"
PANEL_ENV_FILE="${PANEL_DIR}/.env"
PANEL_ACCESS_LOG="/var/log/nginx/pteroprotect.access.log"
TRUSTED_LOGIN_LOG="/dev/shm/pteroprotect/auth_success_ips.log"
BLOCK_HISTORY_FILE="${RUNTIME_DIR}/block_history.tsv"
TENANT_HISTORY_FILE="${RUNTIME_DIR}/tenant_quarantine.tsv"
LOCKDOWN_FLAG_FILE="${RUNTIME_DIR}/strict_lockdown.flag"
MODE_FLAG_FILE="${RUNTIME_DIR}/mode.flag"
NGINX_EMERGENCY_PROFILE_FILE="/etc/nginx/conf.d/pteroprotect_emergency_profile.conf"
MODE_STATE_CACHE="normal"
NGINX_PROFILE_STATE_CACHE="normal"
LAST_NGINX_RELOAD_TS=0
LAST_SERVICE_PULSE_COUNT=0
IP_TRUST_STATE_FILE="${GUARD_HOME}/runtime/ip_trust.tsv"
IP_TRUST_RESTORE_DONE=0

mkdir -p "${RUNTIME_DIR}"
touch "${LOG_FILE}"
touch "${BLOCK_HISTORY_FILE}"
touch "${TENANT_HISTORY_FILE}"

read_network_setting() {
    local key="$1"
    local default_value="$2"

    if [[ ! -f "${CONFIG_FILE}" ]]; then
        printf '%s' "${default_value}"
        return 0
    fi

    perl -MJSON::PP -e '
        my ($file, $key, $default_value) = @ARGV;
        open my $fh, "<", $file or die "open failed";
        local $/;
        my $raw = <$fh>;
        my $data = eval { decode_json($raw) };
        if (!$data || ref($data) ne "HASH" || ref($data->{network}) ne "HASH" || !exists $data->{network}{$key}) {
            print $default_value;
            exit 0;
        }

        my $value = $data->{network}{$key};
        if (ref($value) eq "ARRAY") {
            print join(",", @{$value});
        } elsif (!defined $value) {
            print $default_value;
        } else {
            print $value;
        }
    ' "${CONFIG_FILE}" "${key}" "${default_value}" 2>/dev/null || printf '%s' "${default_value}"
}

read_unblock_portal_port() {
    local value
    value="$(read_network_setting unblock_portal_port 18443)"
    if [[ "${value}" =~ ^[0-9]+$ ]] && (( value >= 1 && value <= 65535 )); then
        printf '%s' "${value}"
    else
        printf '18443'
    fi
}

trim_lines() {
    local file="$1"
    local max_lines="$2"
    local current
    current="$(wc -l < "${file}" 2>/dev/null || echo 0)"
    if [[ "${current}" -gt "${max_lines}" ]]; then
        tail -n "${max_lines}" "${file}" > "${file}.tmp" && mv "${file}.tmp" "${file}"
    fi
}

sanitize_ports_csv() {
    local raw="$1"
    local cleaned
    cleaned="$(printf '%s' "${raw}" | tr -cd '0-9,')"
    cleaned="${cleaned#,}"
    cleaned="${cleaned%,}"
    if [[ -z "${cleaned}" ]]; then
        cleaned="22,80,443,8080,2022"
    fi
    printf '%s' "${cleaned}"
}

build_ports_regex() {
    local csv
    csv="$(sanitize_ports_csv "$1")"
    printf ':(%s)$' "$(printf '%s' "${csv}" | sed 's/,/|/g')"
}

safe_cmd() {
    bash -lc "$1" 2>/dev/null || true
}

sanitize_shell_single_quoted() {
    local value="$1"
    value="${value//$'\r'/}"
    value="${value//$'\n'/}"
    value="${value//\'/}"
    printf '%s' "${value}"
}

mysql_escape_literal() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\'/\'\'}"
    printf '%s' "${value}"
}

read_panel_env() {
    local key="$1"
    local value
    value="$(awk -F= -v search_key="${key}" '$1 == search_key {sub(/^[^=]*=/, "", $0); print $0; exit}' "${PANEL_ENV_FILE}" 2>/dev/null || true)"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"
    printf '%s' "${value}"
}

mysql_exec() {
    local sql="$1"
    local host user password database port
    host="$(read_panel_env DB_HOST)"
    user="$(read_panel_env DB_USERNAME)"
    password="$(read_panel_env DB_PASSWORD)"
    database="$(read_panel_env DB_DATABASE)"
    port="$(read_panel_env DB_PORT)"

    [[ -n "${host}" && -n "${user}" && -n "${database}" ]] || return 1
    [[ -n "${port}" ]] || port="3306"

    mysql -N -B -h"${host}" -P"${port}" -u"${user}" "-p${password}" "${database}" -e "${sql}" 2>/dev/null
}

normalize_ip() {
    local value="$1"
    value="${value#\[}"
    value="${value%\]}"
    printf '%s' "${value}"
}

trim() {
    local value="$1"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    printf '%s' "${value}"
}

extract_host_from_value() {
    local raw
    raw="$(trim "$1")"
    raw="${raw%\"}"
    raw="${raw#\"}"
    raw="${raw%\'}"
    raw="${raw#\'}"
    raw="${raw#https://}"
    raw="${raw#http://}"
    raw="${raw%%/*}"
    raw="${raw%%:*}"
    printf '%s' "${raw}"
}

add_unique_word() {
    local candidate="$1"
    local current="$2"
    if [[ -z "${candidate}" ]]; then
        printf '%s' "${current}"
        return
    fi
    case " ${current} " in
        *" ${candidate} "*) printf '%s' "${current}" ;;
        *) printf '%s %s' "${current}" "${candidate}" ;;
    esac
}

normalize_bool() {
    local value
    value="$(trim "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')")"
    case "${value}" in
        1|true|yes|on) printf '1' ;;
        0|false|no|off|'') printf '0' ;;
        *) printf '0' ;;
    esac
}

normalize_int() {
    local value="$1"
    local fallback="$2"
    if [[ "${value}" =~ ^-?[0-9]+$ ]]; then
        printf '%s' "${value}"
    else
        printf '%s' "${fallback}"
    fi
}

clamp_min_int() {
    local value
    value="$(normalize_int "$1" "$2")"
    local min="$2"
    if (( value < min )); then
        printf '%s' "${min}"
    else
        printf '%s' "${value}"
    fi
}

append_csv_unique() {
    local csv="$1"
    local item="$2"
    item="$(normalize_ip "${item}")"
    [[ -z "${item}" ]] && {
        printf '%s' "${csv}"
        return
    }
    if [[ -z "${csv}" ]]; then
        printf '%s' "${item}"
        return
    fi
    case ",${csv}," in
        *,"${item}",*) printf '%s' "${csv}" ;;
        *) printf '%s,%s' "${csv}" "${item}" ;;
    esac
}

is_valid_ip() {
    local ip
    ip="$(normalize_ip "$1")"
    local o1 o2 o3 o4
    [[ -z "${ip}" ]] && return 1
    if [[ "${ip}" == *:* ]]; then
        [[ "${ip}" =~ ^[0-9a-fA-F:]+$ ]] || return 1
        return 0
    fi
    [[ "${ip}" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
    IFS='.' read -r o1 o2 o3 o4 <<< "${ip}"
    for octet in "${o1}" "${o2}" "${o3}" "${o4}"; do
        [[ "${octet}" =~ ^[0-9]+$ ]] || return 1
        (( octet >= 0 && octet <= 255 )) || return 1
    done
    return 0
}

is_private_ip() {
    local ip
    ip="$(normalize_ip "$1")"
    ip="$(printf '%s' "${ip}" | tr '[:upper:]' '[:lower:]')"

    [[ -z "${ip}" ]] && return 0
    [[ "${ip}" == "127.0.0.1" || "${ip}" == "::1" ]] && return 0
    [[ "${ip}" == 10.* || "${ip}" == 192.168.* ]] && return 0
    [[ "${ip}" =~ ^172\.(1[6-9]|2[0-9]|3[0-1])\. ]] && return 0
    [[ "${ip}" =~ ^169\.254\. ]] && return 0
    [[ "${ip}" =~ ^fc ]] && return 0
    [[ "${ip}" =~ ^fd ]] && return 0
    [[ "${ip}" =~ ^fe80: ]] && return 0

    return 1
}

is_whitelisted_ip() {
    local ip
    ip="$(normalize_ip "$1")"

    is_private_ip "${ip}" && return 0

    for host_ip in ${HOST_IPS}; do
        host_ip="$(normalize_ip "${host_ip}")"
        [[ -z "${host_ip}" ]] && continue
        [[ "${host_ip}" == "${ip}" ]] && return 0
    done

    if [[ -n "${WHITELIST_IPS:-}" ]]; then
        case ",${WHITELIST_IPS}," in
            *,"${ip}",*) return 0 ;;
        esac
    fi

    return 1
}

resolve_trusted_ips() {
    local trusted_hosts_raw trusted_host resolved infra_hosts value remote_host hosts panel_host resolver_ip
    trusted_hosts_raw="$(read_network_setting trusted_hosts "")"
    hosts=""

    if [[ -f "${WINGS_CONFIG}" ]]; then
        value="$(awk -F': ' '/^remote:/ {print $2; exit}' "${WINGS_CONFIG}" 2>/dev/null || true)"
        remote_host="$(extract_host_from_value "${value}")"
        hosts="$(add_unique_word "${remote_host}" "${hosts}")"
    fi

    for trusted_host in ${trusted_hosts_raw//,/ }; do
        hosts="$(add_unique_word "$(extract_host_from_value "${trusted_host}")" "${hosts}")"
    done

    panel_host="$(extract_host_from_value "$(read_panel_env APP_URL)")"
    hosts="$(add_unique_word "${panel_host}" "${hosts}")"

    for infra_hosts in ${hosts}; do
        [[ -z "${infra_hosts}" ]] && continue
        while read -r resolved; do
            resolved="$(normalize_ip "${resolved}")"
            [[ -z "${resolved}" ]] && continue
            printf '%s\n' "${resolved}"
        done < <(getent ahosts "${infra_hosts}" 2>/dev/null | awk '{print $1}' | sort -u)
    done

    while read -r resolver_ip; do
        resolver_ip="$(normalize_ip "${resolver_ip}")"
        [[ -z "${resolver_ip}" ]] && continue
        printf '%s\n' "${resolver_ip}"
    done < <(awk '/^nameserver[[:space:]]+/ {print $2}' /etc/resolv.conf 2>/dev/null | sed 's/%.*//' | sort -u)
}

resolve_recent_login_ips() {
    local now ttl
    now="$(date +%s)"
    ttl="$1"

    [[ -f "${TRUSTED_LOGIN_LOG}" ]] || return 0

    awk -v now="${now}" -v ttl="${ttl}" '
        NF >= 2 {
            ts = $1;
            ip = $2;
            if (ts ~ /^[0-9]+$/ && (now - ts) <= ttl) {
                seen[ip] = 1;
            }
        }
        END {
            for (ip in seen) {
                print ip;
            }
        }
    ' "${TRUSTED_LOGIN_LOG}" | sort -u
}

resolve_manual_essential_ips() {
    local raw entry resolved
    raw="$(read_network_setting essential_allowlist "")"
    for entry in ${raw//,/ }; do
        entry="$(normalize_ip "${entry}")"
        [[ -z "${entry}" ]] && continue

        if is_valid_ip "${entry}"; then
            printf '%s\n' "${entry}"
            continue
        fi

        while read -r resolved; do
            resolved="$(normalize_ip "${resolved}")"
            [[ -z "${resolved}" ]] && continue
            printf '%s\n' "${resolved}"
        done < <(getent ahosts "$(extract_host_from_value "${entry}")" 2>/dev/null | awk '{print $1}' | sort -u)
    done
}

resolve_host_ips() {
    local host
    host="$(extract_host_from_value "$1")"
    [[ -z "${host}" ]] && return 0
    getent ahosts "${host}" 2>/dev/null | awk '{print $1}' | sed '/^$/d' | sort -u
}

resolve_server_uuid_by_identifier() {
    local identifier="$1"
    local safe_identifier
    safe_identifier="$(mysql_escape_literal "${identifier}")"
    mysql_exec "SELECT uuid FROM servers WHERE uuidShort = '${safe_identifier}' OR uuid = '${safe_identifier}' LIMIT 1;" | head -n 1
}

resolve_container_ips_by_server_identifier() {
    local identifier="$1"
    local uuid container_id ip
    uuid="$(resolve_server_uuid_by_identifier "${identifier}")"

    if [[ -n "${uuid}" ]]; then
        while read -r container_id; do
            [[ -z "${container_id}" ]] && continue
            while read -r ip; do
                ip="$(normalize_ip "${ip}")"
                [[ -z "${ip}" ]] && continue
                is_valid_ip "${ip}" || continue
                printf '%s\n' "${ip}"
            done < <(docker inspect --format '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' "${container_id}" 2>/dev/null | tr ' ' '\n' | sed '/^$/d')
        done < <(docker ps --filter "label=service_uuid=${uuid}" --format '{{.ID}}' 2>/dev/null)
    fi

    while read -r container_id; do
        [[ -z "${container_id}" ]] && continue
        while read -r ip; do
            ip="$(normalize_ip "${ip}")"
            [[ -z "${ip}" ]] && continue
            is_valid_ip "${ip}" || continue
            printf '%s\n' "${ip}"
        done < <(docker inspect --format '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' "${container_id}" 2>/dev/null | tr ' ' '\n' | sed '/^$/d')
    done < <(docker ps --filter "name=${identifier}" --format '{{.ID}}' 2>/dev/null)
}

ensure_self_ddos_rate_limit_chains() {
    [[ "${SELF_DDOS_RATE_LIMIT_ENABLED:-0}" == "1" ]] || return 0
    [[ "${SELF_DDOS_RATE_LIMIT_CHAIN_READY:-0}" == "1" ]] && return 0
    command -v iptables >/dev/null 2>&1 || return 0
    command -v ipset >/dev/null 2>&1 || return 0

    ipset create "${SELF_DDOS_RATE_IPSET4}" hash:ip family inet timeout "${SELF_DDOS_RATE_LIMIT_TTL_SEC}" -exist >/dev/null 2>&1 || true

    iptables -N "${SELF_DDOS_RATE_CHAIN4}" >/dev/null 2>&1 || true
    iptables -F "${SELF_DDOS_RATE_CHAIN4}" >/dev/null 2>&1 || true
    if iptables -S DOCKER-USER >/dev/null 2>&1; then
        iptables -C DOCKER-USER -j "${SELF_DDOS_RATE_CHAIN4}" >/dev/null 2>&1 || iptables -I DOCKER-USER 1 -j "${SELF_DDOS_RATE_CHAIN4}"
    fi
    iptables -A "${SELF_DDOS_RATE_CHAIN4}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
    # Inbound flood protection to container IPs (self-ddos victim path): match destination container IP.
    iptables -A "${SELF_DDOS_RATE_CHAIN4}" -m set --match-set "${SELF_DDOS_RATE_IPSET4}" dst -p tcp -m conntrack --ctstate NEW -m hashlimit \
        --hashlimit-name pteroprotect_selfddos_tcp_in_v4 --hashlimit-above "${SELF_DDOS_RATE_LIMIT_RPS}/second" \
        --hashlimit-burst "${SELF_DDOS_RATE_LIMIT_BURST}" --hashlimit-mode dstip --hashlimit-dstmask 32 -j DROP
    iptables -A "${SELF_DDOS_RATE_CHAIN4}" -m set --match-set "${SELF_DDOS_RATE_IPSET4}" dst -p udp -m hashlimit \
        --hashlimit-name pteroprotect_selfddos_udp_in_v4 --hashlimit-above "${SELF_DDOS_RATE_LIMIT_RPS}/second" \
        --hashlimit-burst "${SELF_DDOS_RATE_LIMIT_BURST}" --hashlimit-mode dstip --hashlimit-dstmask 32 -j DROP
    # Outbound abuse protection from compromised container.
    iptables -A "${SELF_DDOS_RATE_CHAIN4}" -m set --match-set "${SELF_DDOS_RATE_IPSET4}" src -p tcp -m conntrack --ctstate NEW -m hashlimit \
        --hashlimit-name pteroprotect_selfddos_tcp_out_v4 --hashlimit-above "${SELF_DDOS_RATE_LIMIT_RPS}/second" \
        --hashlimit-burst "${SELF_DDOS_RATE_LIMIT_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
    iptables -A "${SELF_DDOS_RATE_CHAIN4}" -m set --match-set "${SELF_DDOS_RATE_IPSET4}" src -p udp -m hashlimit \
        --hashlimit-name pteroprotect_selfddos_udp_out_v4 --hashlimit-above "${SELF_DDOS_RATE_LIMIT_RPS}/second" \
        --hashlimit-burst "${SELF_DDOS_RATE_LIMIT_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
    iptables -A "${SELF_DDOS_RATE_CHAIN4}" -j RETURN

    if command -v ip6tables >/dev/null 2>&1; then
        ipset create "${SELF_DDOS_RATE_IPSET6}" hash:ip family inet6 timeout "${SELF_DDOS_RATE_LIMIT_TTL_SEC}" -exist >/dev/null 2>&1 || true

        ip6tables -N "${SELF_DDOS_RATE_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -F "${SELF_DDOS_RATE_CHAIN6}" >/dev/null 2>&1 || true
        if ip6tables -S DOCKER-USER >/dev/null 2>&1; then
            ip6tables -C DOCKER-USER -j "${SELF_DDOS_RATE_CHAIN6}" >/dev/null 2>&1 || ip6tables -I DOCKER-USER 1 -j "${SELF_DDOS_RATE_CHAIN6}"
        fi
        ip6tables -A "${SELF_DDOS_RATE_CHAIN6}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
        ip6tables -A "${SELF_DDOS_RATE_CHAIN6}" -m set --match-set "${SELF_DDOS_RATE_IPSET6}" dst -p tcp -m conntrack --ctstate NEW -m hashlimit \
            --hashlimit-name pteroprotect_selfddos_tcp_in_v6 --hashlimit-above "${SELF_DDOS_RATE_LIMIT_RPS}/second" \
            --hashlimit-burst "${SELF_DDOS_RATE_LIMIT_BURST}" --hashlimit-mode dstip --hashlimit-dstmask 128 -j DROP
        ip6tables -A "${SELF_DDOS_RATE_CHAIN6}" -m set --match-set "${SELF_DDOS_RATE_IPSET6}" dst -p udp -m hashlimit \
            --hashlimit-name pteroprotect_selfddos_udp_in_v6 --hashlimit-above "${SELF_DDOS_RATE_LIMIT_RPS}/second" \
            --hashlimit-burst "${SELF_DDOS_RATE_LIMIT_BURST}" --hashlimit-mode dstip --hashlimit-dstmask 128 -j DROP
        ip6tables -A "${SELF_DDOS_RATE_CHAIN6}" -m set --match-set "${SELF_DDOS_RATE_IPSET6}" src -p tcp -m conntrack --ctstate NEW -m hashlimit \
            --hashlimit-name pteroprotect_selfddos_tcp_out_v6 --hashlimit-above "${SELF_DDOS_RATE_LIMIT_RPS}/second" \
            --hashlimit-burst "${SELF_DDOS_RATE_LIMIT_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
        ip6tables -A "${SELF_DDOS_RATE_CHAIN6}" -m set --match-set "${SELF_DDOS_RATE_IPSET6}" src -p udp -m hashlimit \
            --hashlimit-name pteroprotect_selfddos_udp_out_v6 --hashlimit-above "${SELF_DDOS_RATE_LIMIT_RPS}/second" \
            --hashlimit-burst "${SELF_DDOS_RATE_LIMIT_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
        ip6tables -A "${SELF_DDOS_RATE_CHAIN6}" -j RETURN
    fi

    SELF_DDOS_RATE_LIMIT_CHAIN_READY=1
}

apply_self_ddos_rate_limit_for_server() {
    local identifier="$1"
    local request_count="$2"
    local ip

    [[ "${SELF_DDOS_RATE_LIMIT_ENABLED:-0}" == "1" ]] || return 0
    ensure_self_ddos_rate_limit_chains

    while read -r ip; do
        [[ -z "${ip}" ]] && continue
        is_valid_ip "${ip}" || continue

        if [[ "${ip}" == *:* ]]; then
            ipset add "${SELF_DDOS_RATE_IPSET6}" "${ip}" timeout "${SELF_DDOS_RATE_LIMIT_TTL_SEC}" -exist >/dev/null 2>&1 || true
        else
            ipset add "${SELF_DDOS_RATE_IPSET4}" "${ip}" timeout "${SELF_DDOS_RATE_LIMIT_TTL_SEC}" -exist >/dev/null 2>&1 || true
        fi
        printf '[self-ddos-limit] identifier=%s ip=%s rps=%s burst=%s ttl=%s requests=%s\n' \
            "${identifier}" "${ip}" "${SELF_DDOS_RATE_LIMIT_RPS}" "${SELF_DDOS_RATE_LIMIT_BURST}" "${SELF_DDOS_RATE_LIMIT_TTL_SEC}" "${request_count}" >> "${LOG_FILE}"
        printf '[self-ddos-limit] identifier=%s ip=%s rps=%s burst=%s ttl=%s requests=%s\n' \
            "${identifier}" "${ip}" "${SELF_DDOS_RATE_LIMIT_RPS}" "${SELF_DDOS_RATE_LIMIT_BURST}" "${SELF_DDOS_RATE_LIMIT_TTL_SEC}" "${request_count}" >> "${LATEST_FILE}"
    done < <(resolve_container_ips_by_server_identifier "${identifier}" | sort -u)
}

watch_self_ddos_flows() {
    local identifier="$1"
    local request_count="$2"
    local ip in_count out_count

    [[ "${SELF_DDOS_FLOW_WATCH_ENABLED:-1}" == "1" ]] || return 0
    command -v conntrack >/dev/null 2>&1 || return 0

    while read -r ip; do
        [[ -z "${ip}" ]] && continue
        in_count="$(conntrack -L -d "${ip}" 2>/dev/null | wc -l || echo 0)"
        out_count="$(conntrack -L -s "${ip}" 2>/dev/null | wc -l || echo 0)"
        [[ "${in_count}" =~ ^[0-9]+$ ]] || in_count=0
        [[ "${out_count}" =~ ^[0-9]+$ ]] || out_count=0
        printf '[flow-watch] identifier=%s ip=%s inbound=%s outbound=%s trigger_requests=%s\n' \
            "${identifier}" "${ip}" "${in_count}" "${out_count}" "${request_count}" >> "${LOG_FILE}"
        printf '[flow-watch] identifier=%s ip=%s inbound=%s outbound=%s trigger_requests=%s\n' \
            "${identifier}" "${ip}" "${in_count}" "${out_count}" "${request_count}" >> "${LATEST_FILE}"
    done < <(resolve_container_ips_by_server_identifier "${identifier}" | sort -u)
}

current_ssh_client_ips() {
    local ips=""
    if [[ -n "${SSH_CLIENT:-}" ]]; then
        ips="$(append_csv_unique "${ips}" "$(awk '{print $1}' <<< "${SSH_CLIENT}")")"
    fi
    if [[ -n "${SSH_CONNECTION:-}" ]]; then
        ips="$(append_csv_unique "${ips}" "$(awk '{print $1}' <<< "${SSH_CONNECTION}")")"
    fi
    while read -r rip; do
        rip="$(normalize_ip "${rip}")"
        [[ -n "${rip}" ]] || continue
        is_valid_ip "${rip}" || continue
        ips="$(append_csv_unique "${ips}" "${rip}")"
    done < <(ss -tn state established 2>/dev/null | awk '
        NR>1 {
            local=$3; remote=$4;
            if (local ~ /:(22|2022)$/) {
                if (remote ~ /^\[/) {sub(/^\[/, "", remote); sub(/\]:[0-9]+$/, "", remote)}
                else {sub(/:[0-9]+$/, "", remote)}
                print remote
            }
        }' | sed '/^$/d' | sort -u)
    printf '%s' "${ips}"
}

resolve_recent_websocket_ips() {
    local limit_lines="$1"
    local ws_path_re="${2:-^/api/client/servers/.+/websocket$}"
    [[ -f "${PANEL_ACCESS_LOG}" ]] || return 0
    tail -n "${limit_lines}" "${PANEL_ACCESS_LOG}" 2>/dev/null | awk -v ws_re="${ws_path_re}" '
        NF >= 9 {
            ip=$1; path=$7; status=$9;
            gsub(/,.*/, "", ip);
            gsub(/^\[/, "", ip);
            gsub(/\]$/, "", ip);
            if (path ~ ws_re && status ~ /^(101|200)$/) {
                count[ip]++
            }
        }
        END {
            for (ip in count) {
                if (count[ip] >= 3) print ip
            }
        }' | sed '/^$/d' | sort -u
}

is_recently_authenticated_ip() {
    local ip
    ip="$(normalize_ip "$1")"
    [[ -z "${ip}" ]] && return 1

    if [[ -n "${TRUSTED_LOGIN_IPS:-}" ]]; then
        case ",${TRUSTED_LOGIN_IPS}," in
            *,"${ip}",*) return 0 ;;
        esac
    fi

    return 1
}

is_ip_trust_protected_ip() {
    local ip row tier
    ip="$(normalize_ip "$1")"
    [[ -n "${ip}" ]] || return 1
    [[ "${IP_TRUST_ENABLED:-0}" == "1" ]] || return 1
    [[ -f "${IP_TRUST_STATE_FILE}" ]] || return 1

    row="$(awk -F '\t' -v ip="${ip}" '$1 == ip {print $0; exit}' "${IP_TRUST_STATE_FILE}" 2>/dev/null || true)"
    [[ -n "${row}" ]] || return 1
    tier="$(awk -F '\t' '{print $7}' <<< "${row}")"
    case "${tier}" in
        trusted|vtrusted) return 0 ;;
    esac
    return 1
}

ip_trust_threshold_multiplier() {
    local ip row tier
    ip="$(normalize_ip "$1")"
    [[ -n "${ip}" ]] || {
        printf '1'
        return 0
    }
    [[ "${IP_TRUST_ENABLED:-0}" == "1" ]] || {
        printf '1'
        return 0
    }
    [[ -f "${IP_TRUST_STATE_FILE}" ]] || {
        printf '1'
        return 0
    }

    row="$(awk -F '\t' -v ip="${ip}" '$1 == ip {print $0; exit}' "${IP_TRUST_STATE_FILE}" 2>/dev/null || true)"
    [[ -n "${row}" ]] || {
        printf '1'
        return 0
    }
    tier="$(awk -F '\t' '{print $7}' <<< "${row}")"
    case "${tier}" in
        vtrusted) printf '4' ;;
        trusted) printf '3' ;;
        probation) printf '1' ;;
        *) printf '1' ;;
    esac
}

ip_trust_compute_tier() {
    local obs="$1"
    local score="$2"
    local bad="$3"

    if (( obs < IP_TRUST_PROMOTION_OBS )); then
        if (( bad >= IP_TRUST_WORST_BAD || score <= IP_TRUST_SCORE_WORST )); then
            printf 'worst'
            return 0
        fi
        if (( bad >= IP_TRUST_BAD_BAD || score <= IP_TRUST_SCORE_BAD )); then
            printf 'bad'
            return 0
        fi
        printf 'probation'
        return 0
    fi

    if (( bad >= IP_TRUST_WORST_BAD || score <= IP_TRUST_SCORE_WORST )); then
        printf 'worst'
    elif (( bad >= IP_TRUST_BAD_BAD || score <= IP_TRUST_SCORE_BAD )); then
        printf 'bad'
    elif (( obs >= IP_TRUST_VTRUST_OBS && score >= IP_TRUST_SCORE_VTRUSTED )); then
        printf 'vtrusted'
    elif (( score >= IP_TRUST_SCORE_TRUSTED )); then
        printf 'trusted'
    else
        printf 'probation'
    fi
}

ip_trust_remove_all_tiers() {
    local ip="$1"
    command -v ipset >/dev/null 2>&1 || return 0
    if [[ "${ip}" == *:* ]]; then
        ipset del "${BW_IPSET6_PROBATION}" "${ip}" >/dev/null 2>&1 || true
        ipset del "${BW_IPSET6_BAD}" "${ip}" >/dev/null 2>&1 || true
        ipset del "${BW_IPSET6_WORST}" "${ip}" >/dev/null 2>&1 || true
        ipset del "${BW_IPSET6_TRUSTED}" "${ip}" >/dev/null 2>&1 || true
        ipset del "${BW_IPSET6_VTRUSTED}" "${ip}" >/dev/null 2>&1 || true
    else
        ipset del "${BW_IPSET4_PROBATION}" "${ip}" >/dev/null 2>&1 || true
        ipset del "${BW_IPSET4_BAD}" "${ip}" >/dev/null 2>&1 || true
        ipset del "${BW_IPSET4_WORST}" "${ip}" >/dev/null 2>&1 || true
        ipset del "${BW_IPSET4_TRUSTED}" "${ip}" >/dev/null 2>&1 || true
        ipset del "${BW_IPSET4_VTRUSTED}" "${ip}" >/dev/null 2>&1 || true
    fi
}

ip_trust_apply_tier() {
    local ip="$1"
    local tier="$2"
    command -v ipset >/dev/null 2>&1 || return 0
    ip_trust_remove_all_tiers "${ip}"

    if [[ "${ip}" == *:* ]]; then
        case "${tier}" in
            probation) ipset add "${BW_IPSET6_PROBATION}" "${ip}" timeout "${IP_TRUST_TIER_TTL_SEC}" -exist >/dev/null 2>&1 || true ;;
            bad) ipset add "${BW_IPSET6_BAD}" "${ip}" timeout "${IP_TRUST_TIER_TTL_SEC}" -exist >/dev/null 2>&1 || true ;;
            worst) ipset add "${BW_IPSET6_WORST}" "${ip}" timeout "${IP_TRUST_TIER_TTL_SEC}" -exist >/dev/null 2>&1 || true ;;
            trusted) ipset add "${BW_IPSET6_TRUSTED}" "${ip}" timeout "${IP_TRUST_TIER_TTL_SEC}" -exist >/dev/null 2>&1 || true ;;
            vtrusted) ipset add "${BW_IPSET6_VTRUSTED}" "${ip}" timeout "${IP_TRUST_TIER_TTL_SEC}" -exist >/dev/null 2>&1 || true ;;
        esac
    else
        case "${tier}" in
            probation) ipset add "${BW_IPSET4_PROBATION}" "${ip}" timeout "${IP_TRUST_TIER_TTL_SEC}" -exist >/dev/null 2>&1 || true ;;
            bad) ipset add "${BW_IPSET4_BAD}" "${ip}" timeout "${IP_TRUST_TIER_TTL_SEC}" -exist >/dev/null 2>&1 || true ;;
            worst) ipset add "${BW_IPSET4_WORST}" "${ip}" timeout "${IP_TRUST_TIER_TTL_SEC}" -exist >/dev/null 2>&1 || true ;;
            trusted) ipset add "${BW_IPSET4_TRUSTED}" "${ip}" timeout "${IP_TRUST_TIER_TTL_SEC}" -exist >/dev/null 2>&1 || true ;;
            vtrusted) ipset add "${BW_IPSET4_VTRUSTED}" "${ip}" timeout "${IP_TRUST_TIER_TTL_SEC}" -exist >/dev/null 2>&1 || true ;;
        esac
    fi
}

ip_trust_update() {
    local ip="$1"
    local good_inc="$2"
    local bad_inc="$3"
    local source="$4"
    local metric="$5"
    local row obs good bad score last_seen new_score new_tier old_tier tmp_file

    [[ "${IP_TRUST_ENABLED:-0}" == "1" ]] || return 0
    ip="$(normalize_ip "${ip}")"
    is_valid_ip "${ip}" || return 0
    is_whitelisted_ip "${ip}" && return 0

    mkdir -p "$(dirname "${IP_TRUST_STATE_FILE}")"
    touch "${IP_TRUST_STATE_FILE}"

    row="$(awk -F '\t' -v ip="${ip}" '$1 == ip {print $0; exit}' "${IP_TRUST_STATE_FILE}" 2>/dev/null || true)"
    obs=0
    good=0
    bad=0
    score=0
    old_tier="probation"
    if [[ -n "${row}" ]]; then
        IFS=$'\t' read -r _ip obs good bad score _last old_tier <<< "${row}"
    fi

    [[ "${obs}" =~ ^[0-9]+$ ]] || obs=0
    [[ "${good}" =~ ^[0-9]+$ ]] || good=0
    [[ "${bad}" =~ ^[0-9]+$ ]] || bad=0
    [[ "${score}" =~ ^-?[0-9]+$ ]] || score=0
    [[ -n "${old_tier}" ]] || old_tier="probation"

    obs=$(( obs + 1 ))
    good=$(( good + good_inc ))
    bad=$(( bad + bad_inc ))
    new_score=$(( score + good_inc - (bad_inc * 2) ))
    if (( new_score > IP_TRUST_SCORE_MAX )); then
        new_score="${IP_TRUST_SCORE_MAX}"
    elif (( new_score < IP_TRUST_SCORE_MIN )); then
        new_score="${IP_TRUST_SCORE_MIN}"
    fi
    last_seen="$(date +%s)"
    new_tier="$(ip_trust_compute_tier "${obs}" "${new_score}" "${bad}")"

    tmp_file="${IP_TRUST_STATE_FILE}.tmp"
    awk -F '\t' -v ip="${ip}" 'NF >= 1 && $1 != ip { print $0 }' "${IP_TRUST_STATE_FILE}" 2>/dev/null > "${tmp_file}" || true
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "${ip}" "${obs}" "${good}" "${bad}" "${new_score}" "${last_seen}" "${new_tier}" >> "${tmp_file}"
    mv "${tmp_file}" "${IP_TRUST_STATE_FILE}"

    ip_trust_apply_tier "${ip}" "${new_tier}"
    if [[ "${new_tier}" != "${old_tier}" ]]; then
        printf '[ip-trust] ip=%s tier=%s->%s score=%s obs=%s good=%s bad=%s source=%s metric=%s\n' \
            "${ip}" "${old_tier}" "${new_tier}" "${new_score}" "${obs}" "${good}" "${bad}" "${source}" "${metric}" >> "${LOG_FILE}"
        printf '[ip-trust] ip=%s tier=%s->%s score=%s obs=%s good=%s bad=%s source=%s metric=%s\n' \
            "${ip}" "${old_tier}" "${new_tier}" "${new_score}" "${obs}" "${good}" "${bad}" "${source}" "${metric}" >> "${LATEST_FILE}"
    fi
}

ip_trust_restore_once() {
    local ip tier
    [[ "${IP_TRUST_ENABLED:-0}" == "1" ]] || return 0
    [[ "${IP_TRUST_RESTORE_DONE}" == "1" ]] && return 0
    [[ -f "${IP_TRUST_STATE_FILE}" ]] || {
        IP_TRUST_RESTORE_DONE=1
        return 0
    }

    while IFS=$'\t' read -r ip _obs _good _bad _score _last tier; do
        [[ -n "${ip}" && -n "${tier}" ]] || continue
        is_valid_ip "${ip}" || continue
        ip_trust_apply_tier "${ip}" "${tier}"
    done < "${IP_TRUST_STATE_FILE}"

    IP_TRUST_RESTORE_DONE=1
}

update_block_history() {
    local ip="$1"
    local ts="$2"
    local count="$3"
    local tmp_file="${BLOCK_HISTORY_FILE}.tmp"

    awk -F '\t' -v keep_after="$(( ts - ESCALATION_WINDOW_SEC ))" -v ip="${ip}" '
        NF >= 3 && $1 != ip && $2 >= keep_after { print $0 }
    ' "${BLOCK_HISTORY_FILE}" 2>/dev/null > "${tmp_file}" || true

    printf '%s\t%s\t%s\n' "${ip}" "${ts}" "${count}" >> "${tmp_file}"
    mv "${tmp_file}" "${BLOCK_HISTORY_FILE}"
}

next_block_timeout() {
    local ip="$1"
    local base_ttl="$2"
    local now previous_ts previous_count new_count applied_ttl
    now="$(date +%s)"
    previous_ts=0
    previous_count=0

    if [[ -f "${BLOCK_HISTORY_FILE}" ]]; then
        while IFS=$'\t' read -r hist_ip hist_ts hist_count; do
            [[ "${hist_ip:-}" == "${ip}" ]] || continue
            previous_ts="${hist_ts:-0}"
            previous_count="${hist_count:-0}"
            break
        done < "${BLOCK_HISTORY_FILE}"
    fi

    if [[ "${previous_ts}" =~ ^[0-9]+$ ]] && (( now - previous_ts <= ESCALATION_WINDOW_SEC )); then
        new_count=$(( previous_count + 1 ))
    else
        new_count=1
    fi

    if (( new_count > MAX_ESCALATION_STEPS )); then
        new_count="${MAX_ESCALATION_STEPS}"
    fi

    applied_ttl="${base_ttl}"
    if (( new_count > 1 )); then
        applied_ttl=$(( base_ttl * (ESCALATION_MULTIPLIER ** (new_count - 1)) ))
    fi

    if (( applied_ttl > MAX_BLACKHOLE_TTL_SEC )); then
        applied_ttl="${MAX_BLACKHOLE_TTL_SEC}"
    fi

    update_block_history "${ip}" "${now}" "${new_count}"
    printf '%s' "${applied_ttl}"
}

add_ipset_block() {
    local ip="$1"
    local reason="$2"
    local timeout="$3"
    local applied_timeout

    ip="$(normalize_ip "${ip}")"
    [[ -z "${ip}" ]] && return 0
    is_valid_ip "${ip}" || return 0
    if is_recently_authenticated_ip "${ip}"; then
        printf '[mitigate] skip-block ip=%s reason=recent-auth candidate_reason=%s\n' "${ip}" "${reason}" >> "${LATEST_FILE}"
        ip_trust_update "${ip}" 1 0 "skip-block-recent-auth" "${reason}"
        return 0
    fi
    if is_ip_trust_protected_ip "${ip}"; then
        printf '[mitigate] skip-block ip=%s reason=ip-trust-protected candidate_reason=%s\n' "${ip}" "${reason}" >> "${LATEST_FILE}"
        return 0
    fi
    if is_whitelisted_ip "${ip}"; then
        printf '[mitigate] skip-block ip=%s reason=whitelisted candidate_reason=%s\n' "${ip}" "${reason}" >> "${LATEST_FILE}"
        return 0
    fi

    if [[ "${DYNAMIC_BLOCK_DRY_RUN:-0}" == "1" ]]; then
        printf '[mitigate] dry-run ip=%s ttl=%s reason=%s\n' "${ip}" "${timeout}" "${reason}" >> "${LOG_FILE}"
        printf '[mitigate] dry-run ip=%s ttl=%s reason=%s\n' "${ip}" "${timeout}" "${reason}" >> "${LATEST_FILE}"
        ip_trust_update "${ip}" 0 2 "dynamic-block-dryrun" "${reason}"
        return 0
    fi

    applied_timeout="$(next_block_timeout "${ip}" "${timeout}")"

    if [[ "${ip}" == *:* ]]; then
        command -v ipset >/dev/null 2>&1 || return 0
        ipset add "${IPSET6}" "${ip}" timeout "${applied_timeout}" -exist >/dev/null 2>&1 || true
    else
        command -v ipset >/dev/null 2>&1 || return 0
        ipset add "${IPSET4}" "${ip}" timeout "${applied_timeout}" -exist >/dev/null 2>&1 || true
    fi

    printf '[mitigate] blocked ip=%s ttl=%s reason=%s\n' "${ip}" "${applied_timeout}" "${reason}" >> "${LOG_FILE}"
    printf '[mitigate] blocked ip=%s ttl=%s reason=%s\n' "${ip}" "${applied_timeout}" "${reason}" >> "${LATEST_FILE}"
    ip_trust_update "${ip}" 0 3 "dynamic-block" "${reason}"
}

remove_ipset_block() {
    local ip="$1"
    ip="$(normalize_ip "${ip}")"
    is_valid_ip "${ip}" || return 0

    command -v ipset >/dev/null 2>&1 || return 0
    if [[ "${ip}" == *:* ]]; then
        ipset del "${IPSET6}" "${ip}" >/dev/null 2>&1 || true
    else
        ipset del "${IPSET4}" "${ip}" >/dev/null 2>&1 || true
    fi
}

ensure_whitelist_not_blocked() {
    local ip
    [[ "${SELF_UNBLOCK_ESSENTIALS:-1}" == "1" ]] || return 0
    [[ -n "${WHITELIST_IPS:-}" ]] || return 0

    for ip in ${WHITELIST_IPS//,/ }; do
        [[ -z "${ip}" ]] && continue
        remove_ipset_block "${ip}"
    done
}

prune_unblock_portal_accept_rule_v4() {
    local port="$1"
    while iptables -C INPUT -p tcp --dport "${port}" -j ACCEPT >/dev/null 2>&1; do
        iptables -D INPUT -p tcp --dport "${port}" -j ACCEPT >/dev/null 2>&1 || break
    done
}

ensure_unblock_portal_accept_rule_v4() {
    local port="$1"
    prune_unblock_portal_accept_rule_v4 "${port}"
    iptables -I INPUT 1 -p tcp --dport "${port}" -j ACCEPT
}

prune_unblock_portal_accept_rule_v6() {
    local port="$1"
    while ip6tables -C INPUT -p tcp --dport "${port}" -j ACCEPT >/dev/null 2>&1; do
        ip6tables -D INPUT -p tcp --dport "${port}" -j ACCEPT >/dev/null 2>&1 || break
    done
}

ensure_unblock_portal_accept_rule_v6() {
    local port="$1"
    command -v ip6tables >/dev/null 2>&1 || return 0
    prune_unblock_portal_accept_rule_v6 "${port}"
    ip6tables -I INPUT 1 -p tcp --dport "${port}" -j ACCEPT
}

ensure_fail2ban_unblock_bypass_v4() {
    local port="$1"
    local chain
    for chain in $(iptables -S 2>/dev/null | awk '/^-N f2b-/{print $2}'); do
        iptables -C "${chain}" -p tcp --dport "${port}" -j RETURN >/dev/null 2>&1 || \
            iptables -I "${chain}" 1 -p tcp --dport "${port}" -j RETURN
    done
}

ensure_lightweight_block_hooks() {
    local ip
    local unblock_port
    unblock_port="$(read_unblock_portal_port)"
    command -v iptables >/dev/null 2>&1 || return 0
    command -v ipset >/dev/null 2>&1 || return 0

    ensure_unblock_portal_accept_rule_v4 "${unblock_port}"
    ensure_fail2ban_unblock_bypass_v4 "${unblock_port}"

    ipset create "${IPSET4}" hash:ip family inet timeout "${BLOCK_TTL}" -exist >/dev/null 2>&1 || true
    iptables -N "${BLOCK_ONLY_CHAIN4}" >/dev/null 2>&1 || true
    iptables -F "${BLOCK_ONLY_CHAIN4}" >/dev/null 2>&1 || true

    iptables -A "${BLOCK_ONLY_CHAIN4}" -s 127.0.0.1/32 -j RETURN
    iptables -A "${BLOCK_ONLY_CHAIN4}" -s 10.0.0.0/8 -j RETURN
    iptables -A "${BLOCK_ONLY_CHAIN4}" -s 172.16.0.0/12 -j RETURN
    iptables -A "${BLOCK_ONLY_CHAIN4}" -s 192.168.0.0/16 -j RETURN
    iptables -A "${BLOCK_ONLY_CHAIN4}" -p tcp --dport "${unblock_port}" -j RETURN

    for ip in ${HOST_IPS}; do
        ip="$(normalize_ip "${ip}")"
        [[ -n "${ip}" ]] || continue
        is_valid_ip "${ip}" || continue
        [[ "${ip}" == *:* ]] && continue
        iptables -A "${BLOCK_ONLY_CHAIN4}" -s "${ip}/32" -j RETURN
    done

    for ip in ${WHITELIST_IPS//,/ }; do
        ip="$(normalize_ip "${ip}")"
        [[ -n "${ip}" ]] || continue
        is_valid_ip "${ip}" || continue
        [[ "${ip}" == *:* ]] && continue
        iptables -A "${BLOCK_ONLY_CHAIN4}" -s "${ip}/32" -j RETURN
    done

    iptables -A "${BLOCK_ONLY_CHAIN4}" -m set --match-set "${IPSET4}" src -j DROP
    if [[ "${INPUT_GUARD_ALL_TCP_ENABLED:-1}" == "1" ]]; then
        iptables -A "${BLOCK_ONLY_CHAIN4}" -p tcp -m connlimit \
            --connlimit-above "${INPUT_GUARD_ALL_TCP_CONN_LIMIT_PER_IP}" --connlimit-mask 32 -j DROP
        iptables -A "${BLOCK_ONLY_CHAIN4}" -p tcp -m conntrack --ctstate NEW -m hashlimit \
            --hashlimit-name pteroprotect_alltcp_new_v4 --hashlimit-above "${INPUT_GUARD_ALL_TCP_NEW_PER_IP_PER_SEC}/second" \
            --hashlimit-burst "${INPUT_GUARD_ALL_TCP_NEW_PER_IP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
    fi
    if [[ "${INPUT_GUARD_ALL_UDP_ENABLED:-1}" == "1" ]]; then
        iptables -A "${BLOCK_ONLY_CHAIN4}" -p udp -m hashlimit \
            --hashlimit-name pteroprotect_alludp_v4 --hashlimit-above "${INPUT_GUARD_ALL_UDP_PER_IP_PER_SEC}/second" \
            --hashlimit-burst "${INPUT_GUARD_ALL_UDP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
    fi
    if [[ "${EMERGENCY_INPUT_GUARD_ENABLED:-1}" == "1" ]]; then
        iptables -A "${BLOCK_ONLY_CHAIN4}" -p tcp -m multiport --dports 80,443 -m connlimit \
            --connlimit-above "${EMERGENCY_INPUT_CONN_LIMIT_PER_IP}" --connlimit-mask 32 -j DROP
        iptables -A "${BLOCK_ONLY_CHAIN4}" -p tcp -m multiport --dports 80,443 -m conntrack --ctstate NEW -m hashlimit \
            --hashlimit-name pteroprotect_emerg_new_v4 --hashlimit-above "${EMERGENCY_INPUT_NEW_PER_IP_PER_SEC}/second" \
            --hashlimit-burst "${EMERGENCY_INPUT_NEW_PER_IP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
    fi
    if [[ "${GLOBAL_NEW_GUARD_ENABLED:-1}" == "1" ]]; then
        iptables -A "${BLOCK_ONLY_CHAIN4}" -p tcp -m multiport --dports 80,443 -m conntrack --ctstate NEW -m hashlimit \
            --hashlimit-name pteroprotect_global_new_v4 --hashlimit-above "${GLOBAL_NEW_PER_SEC}/second" \
            --hashlimit-burst "${GLOBAL_NEW_BURST}" --hashlimit-mode dstport -j DROP
    fi
    if [[ "${SSH_GUARD_ENABLED:-1}" == "1" ]]; then
        iptables -A "${BLOCK_ONLY_CHAIN4}" -p tcp -m multiport --dports 22,2022 -m connlimit \
            --connlimit-above "${SSH_CONN_LIMIT_PER_IP}" --connlimit-mask 32 -j DROP
        iptables -A "${BLOCK_ONLY_CHAIN4}" -p tcp -m multiport --dports 22,2022 -m conntrack --ctstate NEW -m hashlimit \
            --hashlimit-name pteroprotect_ssh_new_v4 --hashlimit-above "${SSH_NEW_PER_IP_PER_MIN}/minute" \
            --hashlimit-burst "${SSH_NEW_PER_IP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
    fi
    iptables -A "${BLOCK_ONLY_CHAIN4}" -j RETURN
    while iptables -C INPUT -p tcp -m multiport --dports 80,443,8080,2022 -j "${BLOCK_ONLY_CHAIN4}" >/dev/null 2>&1; do
        iptables -D INPUT -p tcp -m multiport --dports 80,443,8080,2022 -j "${BLOCK_ONLY_CHAIN4}" || break
    done
    while iptables -C INPUT -p tcp -m multiport --dports 22,80,443,8080,2022 -j "${BLOCK_ONLY_CHAIN4}" >/dev/null 2>&1; do
        iptables -D INPUT -p tcp -m multiport --dports 22,80,443,8080,2022 -j "${BLOCK_ONLY_CHAIN4}" || break
    done
    while iptables -C INPUT -p tcp ! --dport "${unblock_port}" -j "${BLOCK_ONLY_CHAIN4}" >/dev/null 2>&1; do
        iptables -D INPUT -p tcp ! --dport "${unblock_port}" -j "${BLOCK_ONLY_CHAIN4}" || break
    done
    iptables -I INPUT 2 -p tcp ! --dport "${unblock_port}" -j "${BLOCK_ONLY_CHAIN4}"
    while iptables -C INPUT -p udp -j "${BLOCK_ONLY_CHAIN4}" >/dev/null 2>&1; do
        iptables -D INPUT -p udp -j "${BLOCK_ONLY_CHAIN4}" || break
    done
    iptables -I INPUT 3 -p udp -j "${BLOCK_ONLY_CHAIN4}"

    if command -v ip6tables >/dev/null 2>&1; then
        ensure_unblock_portal_accept_rule_v6 "${unblock_port}"
        ipset create "${IPSET6}" hash:ip family inet6 timeout "${BLOCK_TTL}" -exist >/dev/null 2>&1 || true
        ip6tables -N "${BLOCK_ONLY_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -F "${BLOCK_ONLY_CHAIN6}" >/dev/null 2>&1 || true

        ip6tables -A "${BLOCK_ONLY_CHAIN6}" -s ::1/128 -j RETURN
        ip6tables -A "${BLOCK_ONLY_CHAIN6}" -s fe80::/10 -j RETURN
        ip6tables -A "${BLOCK_ONLY_CHAIN6}" -s fc00::/7 -j RETURN
        ip6tables -A "${BLOCK_ONLY_CHAIN6}" -p tcp --dport "${unblock_port}" -j RETURN

        for ip in ${HOST_IPS}; do
            ip="$(normalize_ip "${ip}")"
            [[ -n "${ip}" ]] || continue
            is_valid_ip "${ip}" || continue
            [[ "${ip}" != *:* ]] && continue
            ip6tables -A "${BLOCK_ONLY_CHAIN6}" -s "${ip}/128" -j RETURN
        done

        for ip in ${WHITELIST_IPS//,/ }; do
            ip="$(normalize_ip "${ip}")"
            [[ -n "${ip}" ]] || continue
            is_valid_ip "${ip}" || continue
            [[ "${ip}" != *:* ]] && continue
            ip6tables -A "${BLOCK_ONLY_CHAIN6}" -s "${ip}/128" -j RETURN
        done

        ip6tables -A "${BLOCK_ONLY_CHAIN6}" -m set --match-set "${IPSET6}" src -j DROP
        if [[ "${INPUT_GUARD_ALL_TCP_ENABLED:-1}" == "1" ]]; then
            ip6tables -A "${BLOCK_ONLY_CHAIN6}" -p tcp -m connlimit \
                --connlimit-above "${INPUT_GUARD_ALL_TCP_CONN_LIMIT_PER_IP}" --connlimit-mask 128 -j DROP
            ip6tables -A "${BLOCK_ONLY_CHAIN6}" -p tcp -m conntrack --ctstate NEW -m hashlimit \
                --hashlimit-name pteroprotect_alltcp_new_v6 --hashlimit-above "${INPUT_GUARD_ALL_TCP_NEW_PER_IP_PER_SEC}/second" \
                --hashlimit-burst "${INPUT_GUARD_ALL_TCP_NEW_PER_IP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
        fi
        if [[ "${INPUT_GUARD_ALL_UDP_ENABLED:-1}" == "1" ]]; then
            ip6tables -A "${BLOCK_ONLY_CHAIN6}" -p udp -m hashlimit \
                --hashlimit-name pteroprotect_alludp_v6 --hashlimit-above "${INPUT_GUARD_ALL_UDP_PER_IP_PER_SEC}/second" \
                --hashlimit-burst "${INPUT_GUARD_ALL_UDP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
        fi
        if [[ "${EMERGENCY_INPUT_GUARD_ENABLED:-1}" == "1" ]]; then
            ip6tables -A "${BLOCK_ONLY_CHAIN6}" -p tcp -m multiport --dports 80,443 -m connlimit \
                --connlimit-above "${EMERGENCY_INPUT_CONN_LIMIT_PER_IP}" --connlimit-mask 128 -j DROP
            ip6tables -A "${BLOCK_ONLY_CHAIN6}" -p tcp -m multiport --dports 80,443 -m conntrack --ctstate NEW -m hashlimit \
                --hashlimit-name pteroprotect_emerg_new_v6 --hashlimit-above "${EMERGENCY_INPUT_NEW_PER_IP_PER_SEC}/second" \
                --hashlimit-burst "${EMERGENCY_INPUT_NEW_PER_IP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
        fi
        if [[ "${GLOBAL_NEW_GUARD_ENABLED:-1}" == "1" ]]; then
            ip6tables -A "${BLOCK_ONLY_CHAIN6}" -p tcp -m multiport --dports 80,443 -m conntrack --ctstate NEW -m hashlimit \
                --hashlimit-name pteroprotect_global_new_v6 --hashlimit-above "${GLOBAL_NEW_PER_SEC}/second" \
                --hashlimit-burst "${GLOBAL_NEW_BURST}" --hashlimit-mode dstport -j DROP
        fi
        if [[ "${SSH_GUARD_ENABLED:-1}" == "1" ]]; then
            ip6tables -A "${BLOCK_ONLY_CHAIN6}" -p tcp -m multiport --dports 22,2022 -m connlimit \
                --connlimit-above "${SSH_CONN_LIMIT_PER_IP}" --connlimit-mask 128 -j DROP
            ip6tables -A "${BLOCK_ONLY_CHAIN6}" -p tcp -m multiport --dports 22,2022 -m conntrack --ctstate NEW -m hashlimit \
                --hashlimit-name pteroprotect_ssh_new_v6 --hashlimit-above "${SSH_NEW_PER_IP_PER_MIN}/minute" \
                --hashlimit-burst "${SSH_NEW_PER_IP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
        fi
        ip6tables -A "${BLOCK_ONLY_CHAIN6}" -j RETURN
        while ip6tables -C INPUT -p tcp -m multiport --dports 80,443,8080,2022 -j "${BLOCK_ONLY_CHAIN6}" >/dev/null 2>&1; do
            ip6tables -D INPUT -p tcp -m multiport --dports 80,443,8080,2022 -j "${BLOCK_ONLY_CHAIN6}" || break
        done
        while ip6tables -C INPUT -p tcp -m multiport --dports 22,80,443,8080,2022 -j "${BLOCK_ONLY_CHAIN6}" >/dev/null 2>&1; do
            ip6tables -D INPUT -p tcp -m multiport --dports 22,80,443,8080,2022 -j "${BLOCK_ONLY_CHAIN6}" || break
        done
        while ip6tables -C INPUT -p tcp ! --dport "${unblock_port}" -j "${BLOCK_ONLY_CHAIN6}" >/dev/null 2>&1; do
            ip6tables -D INPUT -p tcp ! --dport "${unblock_port}" -j "${BLOCK_ONLY_CHAIN6}" || break
        done
        ip6tables -I INPUT 2 -p tcp ! --dport "${unblock_port}" -j "${BLOCK_ONLY_CHAIN6}"
        while ip6tables -C INPUT -p udp -j "${BLOCK_ONLY_CHAIN6}" >/dev/null 2>&1; do
            ip6tables -D INPUT -p udp -j "${BLOCK_ONLY_CHAIN6}" || break
        done
        ip6tables -I INPUT 3 -p udp -j "${BLOCK_ONLY_CHAIN6}"
    fi
}

extract_remote_ip_counts() {
    local state="$1"
    safe_cmd "ss -tn state ${state} | awk -v ports_re='${PORTS_REGEX}' 'NR>1 && \$3 ~ ports_re {remote=\$4; if (remote ~ /^\\[/) {sub(/^\\[/, \"\", remote); sub(/\\]:[0-9]+\$/, \"\", remote);} else {sub(/:[0-9]+\$/, \"\", remote);} print remote}' | sed '/^$/d' | sort | uniq -c | sort -nr"
}

extract_server_identifier_counts() {
    local log_tail_lines="$1"
    local ignore_re="$2"
    safe_cmd "tail -n ${log_tail_lines} ${PANEL_ACCESS_LOG} 2>/dev/null | awk -v ignore_re='${ignore_re}' 'NF >= 7 && \$7 !~ ignore_re { if (match(\$7, /^\\/api\\/client\\/servers\\/([^\\/\\?]+)/, parts)) print parts[1]; }' | sed '/^$/d' | sort | uniq -c | sort -nr"
}

extract_server_identifier_ip_stats() {
    local log_tail_lines="$1"
    local ignore_re="$2"
    safe_cmd "tail -n ${log_tail_lines} ${PANEL_ACCESS_LOG} 2>/dev/null | awk -v ignore_re='${ignore_re}' '
        NF >= 7 && \$7 !~ ignore_re {
            ip=\$1;
            gsub(/,.*/, \"\", ip);
            gsub(/^\\[/, \"\", ip);
            gsub(/\\]$/, \"\", ip);
            if (match(\$7, /^\\/api\\/client\\/servers\\/([^\\/\\?]+)/, parts)) {
                id=parts[1];
                cnt[id]++;
                key=id SUBSEP ip;
                if (!(key in seen)) {
                    seen[key]=1;
                    uniq[id]++;
                }
            }
        }
        END {
            for (id in cnt) {
                printf \"%s\\t%s\\t%s\\n\", id, cnt[id]+0, uniq[id]+0;
            }
        }' | sed '/^$/d' | sort"
}

extract_probe_ip_counts() {
    local log_tail_lines="$1"
    local probe_re="$2"
    safe_cmd "tail -n ${log_tail_lines} ${PANEL_ACCESS_LOG} 2>/dev/null | awk -v probe_re='${probe_re}' 'NF >= 7 && \$7 ~ probe_re {print \$1}' | sed 's/,.*//; s/\\[//g; s/\\]//g' | sed '/^$/d' | sort | uniq -c | sort -nr"
}

extract_sqli_probe_ip_counts() {
    local log_tail_lines="$1"
    safe_cmd "tail -n ${log_tail_lines} /var/log/nginx/pteroprotect.sqli.log 2>/dev/null | awk 'NF >= 1 {print \$1}' | sed 's/,.*//; s/\\[//g; s/\\]//g' | sed '/^$/d' | sort | uniq -c | sort -nr"
}

extract_limiter_ip_counts() {
    local log_tail_lines="$1"
    safe_cmd "tail -n ${log_tail_lines} /var/log/nginx/pterodactyl.app-error.log 2>/dev/null | awk '/limiting requests|limiting connections/ { if (match(\$0, /client: ([0-9A-Fa-f:.]+)/, m)) print m[1] }' | sed '/^$/d' | sort | uniq -c | sort -nr"
}

extract_bad_token_ip_counts() {
    local log_tail_lines="$1"
    local path_re="$2"
    local status_re="$3"
    safe_cmd "tail -n ${log_tail_lines} ${PANEL_ACCESS_LOG} 2>/dev/null | awk -v path_re='${path_re}' -v status_re='${status_re}' 'NF >= 9 && \$7 ~ path_re && \$9 ~ status_re {print \$1}' | sed 's/,.*//; s/\\[//g; s/\\]//g' | sed '/^$/d' | sort | uniq -c | sort -nr"
}

extract_behavior_ip_stats() {
    local log_tail_lines="$1"
    local ignore_re="$2"
    safe_cmd "tail -n ${log_tail_lines} ${PANEL_ACCESS_LOG} 2>/dev/null | awk -v ignore_re='${ignore_re}' '
        NF >= 7 && \$7 !~ ignore_re {
            ip=\$1; path=\$7;
            gsub(/,.*/, \"\", ip);
            gsub(/^\\[/, \"\", ip);
            gsub(/\\]$/, \"\", ip);
            count[ip]++;
            key=ip SUBSEP path;
            if (!(key in seen_path)) {
                seen_path[key]=1;
                uniq_path[ip]++;
            }
            if (prev_path[ip] != \"\" && path != prev_path[ip]) {
                transitions[ip]++;
            }
            if (path == prev_path[ip]) {
                streak[ip]++;
            } else {
                streak[ip]=1;
                prev_path[ip]=path;
            }
            if (streak[ip] > max_streak[ip]) max_streak[ip]=streak[ip];
        }
        END {
            for (ip in count) {
                printf \"%s\\t%s\\t%s\\t%s\\t%s\\n\", ip, count[ip]+0, uniq_path[ip]+0, transitions[ip]+0, max_streak[ip]+0;
            }
        }' | sed '/^$/d'"
}

extract_path_swarm_stats() {
    local log_tail_lines="$1"
    local ignore_re="$2"
    safe_cmd "tail -n ${log_tail_lines} ${PANEL_ACCESS_LOG} 2>/dev/null | awk -v ignore_re='${ignore_re}' '
        NF >= 7 && \$7 !~ ignore_re {
            ip=\$1; path=\$7;
            gsub(/,.*/, \"\", ip);
            gsub(/^\\[/, \"\", ip);
            gsub(/\\]$/, \"\", ip);
            gsub(/[?].*$/, \"\", path);
            gsub(/\\/api\\/client\\/servers\\/[^\\/]+\\//, \"/api/client/servers/:id/\", path);
            gsub(/\\/api\\/application\\/servers\\/[0-9]+/, \"/api/application/servers/:id\", path);
            count[path]++;
            key=path SUBSEP ip;
            if (!(key in seen)) {
                seen[key]=1;
                uniq[path]++;
            }
            ip_count[key]++;
        }
        END {
            for (path in count) {
                printf \"PATH\\t%s\\t%s\\t%s\\n\", path, count[path]+0, uniq[path]+0;
            }
            for (key in ip_count) {
                split(key, a, SUBSEP);
                printf \"IP\\t%s\\t%s\\t%s\\n\", a[1], a[2], ip_count[key]+0;
            }
        }' | sed '/^$/d'"
}

extract_service_pulse_count() {
    local log_tail_lines="$1"
    local service_re="$2"
    safe_cmd "tail -n ${log_tail_lines} ${PANEL_ACCESS_LOG} 2>/dev/null | awk -v service_re='${service_re}' 'NF >= 7 && \$7 ~ service_re {count++} END {print count+0}'"
}

update_tenant_history() {
    local owner_id="$1"
    local ts="$2"
    local count="$3"
    local tmp_file="${TENANT_HISTORY_FILE}.tmp"

    awk -F '\t' -v keep_after="$(( ts - OWNER_QUARANTINE_WINDOW_SEC ))" -v owner_id="${owner_id}" '
        NF >= 3 && $1 != owner_id && $2 >= keep_after { print $0 }
    ' "${TENANT_HISTORY_FILE}" 2>/dev/null > "${tmp_file}" || true

    printf '%s\t%s\t%s\n' "${owner_id}" "${ts}" "${count}" >> "${tmp_file}"
    mv "${tmp_file}" "${TENANT_HISTORY_FILE}"
}

next_owner_quarantine_count() {
    local owner_id="$1"
    local now previous_ts previous_count new_count
    now="$(date +%s)"
    previous_ts=0
    previous_count=0

    if [[ -f "${TENANT_HISTORY_FILE}" ]]; then
        while IFS=$'\t' read -r hist_owner hist_ts hist_count; do
            [[ "${hist_owner:-}" == "${owner_id}" ]] || continue
            previous_ts="${hist_ts:-0}"
            previous_count="${hist_count:-0}"
            break
        done < "${TENANT_HISTORY_FILE}"
    fi

    if [[ "${previous_ts}" =~ ^[0-9]+$ ]] && (( now - previous_ts <= OWNER_QUARANTINE_WINDOW_SEC )); then
        new_count=$(( previous_count + 1 ))
    else
        new_count=1
    fi

    update_tenant_history "${owner_id}" "${now}" "${new_count}"
    printf '%s' "${new_count}"
}

suspend_server_id() {
    local server_id="$1"
    [[ -n "${server_id}" ]] || return 1
    [[ -d "${PANEL_DIR}" && -f "${PANEL_DIR}/artisan" ]] || return 1
    bash -lc "cd '${PANEL_DIR}' && php artisan p:server:guard-suspension ${server_id} --action=suspend --no-interaction" >/dev/null 2>&1
}

quarantine_owner_servers() {
    local owner_id="$1"
    local reason="$2"
    local server_id

    while read -r server_id; do
        [[ -z "${server_id}" ]] && continue
        suspend_server_id "${server_id}" || true
        printf '[tenant-quarantine] owner=%s suspended_server=%s reason=%s\n' "${owner_id}" "${server_id}" "${reason}" >> "${LOG_FILE}"
    done < <(mysql_exec "SELECT id FROM servers WHERE owner_id = ${owner_id} AND (status IS NULL OR status != 'suspended');")
}

quarantine_server_identifier() {
    local identifier="$1"
    local request_count="$2"
    local row server_id owner_id server_uuid server_name server_status owner_hits
    local safe_identifier

    [[ "${SELF_DDOS_QUARANTINE_ENABLED}" == "1" ]] || return 0
    safe_identifier="$(mysql_escape_literal "${identifier}")"
    row="$(mysql_exec "SELECT id, owner_id, uuid, name, COALESCE(status,'') FROM servers WHERE uuidShort = '${safe_identifier}' OR uuid = '${safe_identifier}' LIMIT 1;" | head -n 1)"
    [[ -n "${row}" ]] || return 0

    IFS=$'\t' read -r server_id owner_id server_uuid server_name server_status <<< "${row}"
    [[ -n "${server_id}" && -n "${owner_id}" ]] || return 0

    if [[ "${server_status}" != "suspended" ]]; then
        suspend_server_id "${server_id}" || true
        printf '[tenant-quarantine] server=%s owner=%s identifier=%s requests=%s action=suspend\n' "${server_id}" "${owner_id}" "${identifier}" "${request_count}" >> "${LOG_FILE}"
        printf '[tenant-quarantine] server=%s owner=%s identifier=%s requests=%s action=suspend\n' "${server_id}" "${owner_id}" "${identifier}" "${request_count}" >> "${LATEST_FILE}"
    fi

    owner_hits="$(next_owner_quarantine_count "${owner_id}")"
    if (( owner_hits >= OWNER_QUARANTINE_THRESHOLD )); then
        quarantine_owner_servers "${owner_id}" "repeat-self-ddos:${identifier}:${request_count}"
    fi
}

detect_and_block_offenders() {
    local syn_global="$1"
    local syn_counts="$2"
    local est_counts="$3"
    local access_counts="$4"
    local block_ttl="$5"
    local global_threshold="$6"
    local syn_per_ip_threshold="$7"
    local established_per_ip_threshold="$8"
    local access_per_window_threshold="$9"
    local line count ip syn_threshold est_threshold access_threshold
    local candidate_file signals hard_syn hard_est hard_http trust_mul hard_syn_local hard_est_local hard_http_local
    local behavior_row b_req b_uniq b_tr b_streak human_nav bot_nav
    local syn_hits_file est_hits_file http_hits_file
    local top_est top_http overload_fast_enabled overload_fast_factor overload_fast_bot_factor overload_state
    local fast_syn_threshold fast_est_threshold fast_http_threshold

    if [[ "${DYNAMIC_BLOCK_ENABLED}" != "1" ]]; then
        return 0
    fi

    overload_fast_enabled="${OVERLOAD_FAST_BAN_ENABLED:-1}"
    overload_fast_factor="${OVERLOAD_FAST_BAN_FACTOR_PCT:-70}"
    overload_fast_bot_factor="${OVERLOAD_FAST_BAN_BOT_FACTOR_PCT:-50}"
    [[ "${overload_fast_factor}" =~ ^[0-9]+$ ]] || overload_fast_factor=70
    [[ "${overload_fast_bot_factor}" =~ ^[0-9]+$ ]] || overload_fast_bot_factor=50
    if (( overload_fast_factor < 20 )); then overload_fast_factor=20; fi
    if (( overload_fast_factor > 100 )); then overload_fast_factor=100; fi
    if (( overload_fast_bot_factor < 10 )); then overload_fast_bot_factor=10; fi
    if (( overload_fast_bot_factor > 100 )); then overload_fast_bot_factor=100; fi
    overload_state=0
    top_est="$(awk 'NF >= 1 {print $1; exit}' <<< "${est_counts}" 2>/dev/null || true)"
    top_http="$(awk 'NF >= 1 {print $1; exit}' <<< "${access_counts}" 2>/dev/null || true)"
    [[ "${top_est}" =~ ^[0-9]+$ ]] || top_est=0
    [[ "${top_http}" =~ ^[0-9]+$ ]] || top_http=0
    if [[ "${overload_fast_enabled}" == "1" ]]; then
        if (( syn_global >= global_threshold )) ||
           (( top_est >= established_per_ip_threshold * 2 )) ||
           (( top_http >= access_per_window_threshold * 2 )); then
            overload_state=1
        fi
    fi

    hard_syn=$(( syn_per_ip_threshold * CLEAR_THRESHOLD_HARD_MULTIPLIER ))
    hard_est=$(( established_per_ip_threshold * CLEAR_THRESHOLD_HARD_MULTIPLIER ))
    hard_http=$(( access_per_window_threshold * CLEAR_THRESHOLD_HARD_MULTIPLIER ))
    if (( hard_syn < syn_per_ip_threshold + 1 )); then hard_syn=$(( syn_per_ip_threshold + 1 )); fi
    if (( hard_est < established_per_ip_threshold + 1 )); then hard_est=$(( established_per_ip_threshold + 1 )); fi
    if (( hard_http < access_per_window_threshold + 1 )); then hard_http=$(( access_per_window_threshold + 1 )); fi

    candidate_file="$(mktemp)"
    syn_hits_file="$(mktemp)"
    est_hits_file="$(mktemp)"
    http_hits_file="$(mktemp)"

    while read -r line; do
        [[ -z "${line}" ]] && continue
        count="$(awk '{print $1}' <<< "${line}")"
        ip="$(awk '{print $2}' <<< "${line}")"
        [[ -z "${count}" || -z "${ip}" ]] && continue
        behavior_row="$(awk -F '\t' -v ip="${ip}" '$1 == ip {print $0; exit}' <<< "${BEHAVIOR_IP_STATS:-}" 2>/dev/null || true)"
        b_req=0; b_uniq=0; b_tr=0; b_streak=0; human_nav=0; bot_nav=0
        if [[ -n "${behavior_row}" ]]; then
            IFS=$'\t' read -r _ip b_req b_uniq b_tr b_streak <<< "${behavior_row}"
            [[ "${b_req}" =~ ^[0-9]+$ ]] || b_req=0
            [[ "${b_uniq}" =~ ^[0-9]+$ ]] || b_uniq=0
            [[ "${b_tr}" =~ ^[0-9]+$ ]] || b_tr=0
            [[ "${b_streak}" =~ ^[0-9]+$ ]] || b_streak=0
            if (( b_req >= 10 && b_uniq >= 4 && b_tr >= 3 && b_streak * 10 <= b_req * 8 )); then human_nav=1; fi
            if (( b_req >= 12 )) && (( b_uniq <= 1 || b_streak >= 18 )); then bot_nav=1; fi
        fi
        trust_mul="$(ip_trust_threshold_multiplier "${ip}")"
        [[ "${trust_mul}" =~ ^[0-9]+$ ]] || trust_mul=1
        syn_threshold="${syn_per_ip_threshold}"
        syn_threshold=$(( syn_threshold * trust_mul ))
        hard_syn_local=$(( hard_syn * trust_mul ))
        if (( human_nav == 1 )); then
            syn_threshold=$(( syn_threshold * 2 ))
            hard_syn_local=$(( hard_syn_local * 2 ))
        elif (( bot_nav == 1 )); then
            syn_threshold=$(( (syn_threshold + 1) / 2 ))
            hard_syn_local=$(( (hard_syn_local + 1) / 2 ))
        fi
        if is_recently_authenticated_ip "${ip}"; then
            syn_threshold=$(( syn_threshold * TRUSTED_LOGIN_THRESHOLD_MULTIPLIER ))
            hard_syn_local=$(( hard_syn_local * TRUSTED_LOGIN_THRESHOLD_MULTIPLIER ))
        fi
        fast_syn_threshold=$(( (syn_threshold * overload_fast_factor + 99) / 100 ))
        if (( fast_syn_threshold < 2 )); then fast_syn_threshold=2; fi
        if (( bot_nav == 1 )); then
            fast_syn_threshold=$(( (syn_threshold * overload_fast_bot_factor + 99) / 100 ))
            if (( fast_syn_threshold < 2 )); then fast_syn_threshold=2; fi
        fi
        if (( overload_state == 1 && count >= fast_syn_threshold )) && (( human_nav == 0 )) && (( trust_mul <= 1 || bot_nav == 1 )); then
            add_ipset_block "${ip}" "syn-recv-overload-fast:${count}/${syn_global}" "${block_ttl}"
        elif (( syn_global >= global_threshold && count >= hard_syn_local )); then
            add_ipset_block "${ip}" "syn-recv-hard:${count}/${syn_global}" "${block_ttl}"
        elif (( syn_global >= global_threshold && count >= syn_threshold )); then
            printf '%s\n' "${ip}" >> "${syn_hits_file}"
            printf '%s\tsyn-recv:%s/%s\n' "${ip}" "${count}" "${syn_global}" >> "${candidate_file}"
        fi
    done <<< "${syn_counts}"

    while read -r line; do
        [[ -z "${line}" ]] && continue
        count="$(awk '{print $1}' <<< "${line}")"
        ip="$(awk '{print $2}' <<< "${line}")"
        [[ -z "${count}" || -z "${ip}" ]] && continue
        behavior_row="$(awk -F '\t' -v ip="${ip}" '$1 == ip {print $0; exit}' <<< "${BEHAVIOR_IP_STATS:-}" 2>/dev/null || true)"
        b_req=0; b_uniq=0; b_tr=0; b_streak=0; human_nav=0; bot_nav=0
        if [[ -n "${behavior_row}" ]]; then
            IFS=$'\t' read -r _ip b_req b_uniq b_tr b_streak <<< "${behavior_row}"
            [[ "${b_req}" =~ ^[0-9]+$ ]] || b_req=0
            [[ "${b_uniq}" =~ ^[0-9]+$ ]] || b_uniq=0
            [[ "${b_tr}" =~ ^[0-9]+$ ]] || b_tr=0
            [[ "${b_streak}" =~ ^[0-9]+$ ]] || b_streak=0
            if (( b_req >= 10 && b_uniq >= 4 && b_tr >= 3 && b_streak * 10 <= b_req * 8 )); then human_nav=1; fi
            if (( b_req >= 12 )) && (( b_uniq <= 1 || b_streak >= 18 )); then bot_nav=1; fi
        fi
        trust_mul="$(ip_trust_threshold_multiplier "${ip}")"
        [[ "${trust_mul}" =~ ^[0-9]+$ ]] || trust_mul=1
        est_threshold="${established_per_ip_threshold}"
        est_threshold=$(( est_threshold * trust_mul ))
        hard_est_local=$(( hard_est * trust_mul ))
        if (( human_nav == 1 )); then
            est_threshold=$(( est_threshold * 2 ))
            hard_est_local=$(( hard_est_local * 2 ))
        elif (( bot_nav == 1 )); then
            est_threshold=$(( (est_threshold + 1) / 2 ))
            hard_est_local=$(( (hard_est_local + 1) / 2 ))
        fi
        if is_recently_authenticated_ip "${ip}"; then
            est_threshold=$(( est_threshold * TRUSTED_LOGIN_THRESHOLD_MULTIPLIER ))
            hard_est_local=$(( hard_est_local * TRUSTED_LOGIN_THRESHOLD_MULTIPLIER ))
        fi
        fast_est_threshold=$(( (est_threshold * overload_fast_factor + 99) / 100 ))
        if (( fast_est_threshold < 2 )); then fast_est_threshold=2; fi
        if (( bot_nav == 1 )); then
            fast_est_threshold=$(( (est_threshold * overload_fast_bot_factor + 99) / 100 ))
            if (( fast_est_threshold < 2 )); then fast_est_threshold=2; fi
        fi
        if (( overload_state == 1 && count >= fast_est_threshold )) && (( human_nav == 0 )) && (( trust_mul <= 1 || bot_nav == 1 )); then
            add_ipset_block "${ip}" "established-overload-fast:${count}" "${block_ttl}"
        elif (( count >= hard_est_local )); then
            add_ipset_block "${ip}" "established-hard:${count}" "${block_ttl}"
        elif (( count >= est_threshold )); then
            printf '%s\n' "${ip}" >> "${est_hits_file}"
            printf '%s\testablished:%s\n' "${ip}" "${count}" >> "${candidate_file}"
        fi
    done <<< "${est_counts}"

    while read -r line; do
        [[ -z "${line}" ]] && continue
        count="$(awk '{print $1}' <<< "${line}")"
        ip="$(awk '{print $2}' <<< "${line}")"
        [[ -z "${count}" || -z "${ip}" ]] && continue
        behavior_row="$(awk -F '\t' -v ip="${ip}" '$1 == ip {print $0; exit}' <<< "${BEHAVIOR_IP_STATS:-}" 2>/dev/null || true)"
        b_req=0; b_uniq=0; b_tr=0; b_streak=0; human_nav=0; bot_nav=0
        if [[ -n "${behavior_row}" ]]; then
            IFS=$'\t' read -r _ip b_req b_uniq b_tr b_streak <<< "${behavior_row}"
            [[ "${b_req}" =~ ^[0-9]+$ ]] || b_req=0
            [[ "${b_uniq}" =~ ^[0-9]+$ ]] || b_uniq=0
            [[ "${b_tr}" =~ ^[0-9]+$ ]] || b_tr=0
            [[ "${b_streak}" =~ ^[0-9]+$ ]] || b_streak=0
            if (( b_req >= 10 && b_uniq >= 4 && b_tr >= 3 && b_streak * 10 <= b_req * 8 )); then human_nav=1; fi
            if (( b_req >= 12 )) && (( b_uniq <= 1 || b_streak >= 18 )); then bot_nav=1; fi
        fi
        trust_mul="$(ip_trust_threshold_multiplier "${ip}")"
        [[ "${trust_mul}" =~ ^[0-9]+$ ]] || trust_mul=1
        access_threshold="${access_per_window_threshold}"
        access_threshold=$(( access_threshold * trust_mul ))
        hard_http_local=$(( hard_http * trust_mul ))
        if (( human_nav == 1 )); then
            access_threshold=$(( access_threshold * 2 ))
            hard_http_local=$(( hard_http_local * 2 ))
        elif (( bot_nav == 1 )); then
            access_threshold=$(( (access_threshold + 1) / 2 ))
            hard_http_local=$(( (hard_http_local + 1) / 2 ))
        fi
        if is_recently_authenticated_ip "${ip}"; then
            access_threshold=$(( access_threshold * TRUSTED_LOGIN_THRESHOLD_MULTIPLIER ))
            hard_http_local=$(( hard_http_local * TRUSTED_LOGIN_THRESHOLD_MULTIPLIER ))
        fi
        fast_http_threshold=$(( (access_threshold * overload_fast_factor + 99) / 100 ))
        if (( fast_http_threshold < 2 )); then fast_http_threshold=2; fi
        if (( bot_nav == 1 )); then
            fast_http_threshold=$(( (access_threshold * overload_fast_bot_factor + 99) / 100 ))
            if (( fast_http_threshold < 2 )); then fast_http_threshold=2; fi
        fi
        if (( overload_state == 1 && count >= fast_http_threshold )) && (( human_nav == 0 )) && (( trust_mul <= 1 || bot_nav == 1 )); then
            add_ipset_block "${ip}" "http-access-overload-fast:${count}" "${block_ttl}"
        elif (( count >= hard_http_local )); then
            add_ipset_block "${ip}" "http-access-hard:${count}" "${block_ttl}"
        elif (( count >= access_threshold )); then
            printf '%s\n' "${ip}" >> "${http_hits_file}"
            printf '%s\thttp-access:%s\n' "${ip}" "${count}" >> "${candidate_file}"
        fi
    done <<< "${access_counts}"

    while read -r ip; do
        [[ -n "${ip}" ]] || continue
        signals=0
        grep -Fxq "${ip}" "${syn_hits_file}" && signals=$(( signals + 1 ))
        grep -Fxq "${ip}" "${est_hits_file}" && signals=$(( signals + 1 ))
        grep -Fxq "${ip}" "${http_hits_file}" && signals=$(( signals + 1 ))
        if (( signals >= CLEAR_THRESHOLD_SIGNALS )); then
            add_ipset_block "${ip}" "clear-threshold:${signals}-signals" "${block_ttl}"
        fi
    done < <(cut -f1 "${candidate_file}" | sort -u)

    rm -f "${candidate_file}" "${syn_hits_file}" "${est_hits_file}" "${http_hits_file}"
}

detect_self_ddos_tenants() {
    local server_counts="$1"
    local server_ip_stats="$2"
    local line count identifier uniq threshold

    while read -r line; do
        [[ -z "${line}" ]] && continue
        count="$(awk '{print $1}' <<< "${line}")"
        identifier="$(awk '{print $2}' <<< "${line}")"
        [[ -z "${count}" || -z "${identifier}" ]] && continue
        uniq="$(awk -F '\t' -v id="${identifier}" '$1==id {print $3; exit}' <<< "${server_ip_stats}" 2>/dev/null || echo 0)"
        [[ "${uniq}" =~ ^[0-9]+$ ]] || uniq=0
        threshold="${SELF_DDOS_SERVER_REQ_THRESHOLD}"

        if (( uniq <= 2 )); then
            threshold=$(( threshold / 2 ))
            if (( threshold < 40 )); then threshold=40; fi
        elif (( uniq >= 10 )); then
            threshold=$(( threshold * 2 ))
        fi

        if (( count >= threshold )); then
            watch_self_ddos_flows "${identifier}" "${count}"
            apply_self_ddos_rate_limit_for_server "${identifier}" "${count}"
            if [[ "${SELF_DDOS_QUARANTINE_ENABLED}" == "1" ]]; then
                quarantine_server_identifier "${identifier}" "${count}"
            fi
        fi
    done <<< "${server_counts}"
}

detect_probe_scanners() {
    local probe_counts="$1"
    local line count ip threshold

    [[ "${SCANNER_BLOCK_ENABLED}" == "1" ]] || return 0

    threshold=$(( PROBE_REQ_THRESHOLD * 2 ))
    if (( threshold < PROBE_REQ_THRESHOLD + 1 )); then threshold=$(( PROBE_REQ_THRESHOLD + 1 )); fi

    while read -r line; do
        [[ -z "${line}" ]] && continue
        count="$(awk '{print $1}' <<< "${line}")"
        ip="$(awk '{print $2}' <<< "${line}")"
        [[ -z "${count}" || -z "${ip}" ]] && continue
        if is_recently_authenticated_ip "${ip}"; then
            continue
        fi
        if (( count >= threshold )); then
            add_ipset_block "${ip}" "probe-scan:${count}" "${BLOCK_TTL}"
        fi
    done <<< "${probe_counts}"
}

detect_sqli_probes() {
    local sqli_counts="$1"
    local line count ip threshold

    [[ "${SQLI_PROBE_BLOCK_ENABLED}" == "1" ]] || return 0
    threshold=$(( SQLI_PROBE_REQ_THRESHOLD * 2 ))
    if (( threshold < SQLI_PROBE_REQ_THRESHOLD + 1 )); then threshold=$(( SQLI_PROBE_REQ_THRESHOLD + 1 )); fi

    while read -r line; do
        [[ -z "${line}" ]] && continue
        count="$(awk '{print $1}' <<< "${line}")"
        ip="$(awk '{print $2}' <<< "${line}")"
        [[ -z "${count}" || -z "${ip}" ]] && continue
        if is_recently_authenticated_ip "${ip}"; then
            continue
        fi
        if (( count >= threshold )); then
            add_ipset_block "${ip}" "sqli-probe:${count}" "${BLOCK_TTL}"
        fi
    done <<< "${sqli_counts}"
}

detect_limiter_abusers() {
    local limiter_counts="$1"
    local line count ip threshold

    [[ "${LIMITER_BLOCK_ENABLED}" == "1" ]] || return 0
    threshold=$(( LIMITER_REQ_THRESHOLD * 4 ))
    if (( threshold < LIMITER_REQ_THRESHOLD + 1 )); then threshold=$(( LIMITER_REQ_THRESHOLD + 1 )); fi

    while read -r line; do
        [[ -z "${line}" ]] && continue
        count="$(awk '{print $1}' <<< "${line}")"
        ip="$(awk '{print $2}' <<< "${line}")"
        [[ -z "${count}" || -z "${ip}" ]] && continue
        if is_recently_authenticated_ip "${ip}"; then
            continue
        fi
        if (( count >= threshold )); then
            add_ipset_block "${ip}" "nginx-limiter:${count}" "${BLOCK_TTL}"
        fi
    done <<< "${limiter_counts}"
}

detect_bad_token_abusers() {
    local bad_token_counts="$1"
    local line count ip threshold

    [[ "${BAD_TOKEN_BLOCK_ENABLED}" == "1" ]] || return 0
    threshold="${BAD_TOKEN_FAIL_THRESHOLD}"
    if (( threshold < 1 )); then threshold=1; fi

    while read -r line; do
        [[ -z "${line}" ]] && continue
        count="$(awk '{print $1}' <<< "${line}")"
        ip="$(awk '{print $2}' <<< "${line}")"
        [[ -z "${count}" || -z "${ip}" ]] && continue
        if (( count >= threshold )); then
            add_ipset_block "${ip}" "bad-token:${count}" "${BLOCK_TTL}"
        fi
    done <<< "${bad_token_counts}"
}

detect_proxy_swarm_patterns() {
    local swarm_stats="$1"
    local path line kind p req uniq ip ip_req triggered
    local -A hot_path
    triggered=0

    [[ "${DYNAMIC_BLOCK_ENABLED}" == "1" ]] || {
        printf '0'
        return 0
    }

    while IFS=$'\t' read -r kind p req uniq; do
        [[ "${kind}" == "PATH" ]] || continue
        [[ -n "${p}" ]] || continue
        [[ "${req}" =~ ^[0-9]+$ ]] || continue
        [[ "${uniq}" =~ ^[0-9]+$ ]] || continue

        if (( req >= SWARM_REQ_THRESHOLD && uniq >= SWARM_UNIQUE_IP_THRESHOLD )); then
            hot_path["${p}"]=1
            triggered=$(( triggered + 1 ))
            printf '[swarm] path=%s req=%s uniq=%s action=trigger\n' "${p}" "${req}" "${uniq}" >> "${LOG_FILE}"
            printf '[swarm] path=%s req=%s uniq=%s action=trigger\n' "${p}" "${req}" "${uniq}" >> "${LATEST_FILE}"
        fi
    done <<< "${swarm_stats}"

    while IFS=$'\t' read -r kind p ip ip_req; do
        [[ "${kind}" == "IP" ]] || continue
        [[ -n "${p}" && -n "${ip}" ]] || continue
        [[ "${ip_req}" =~ ^[0-9]+$ ]] || continue
        [[ -n "${hot_path[${p}]:-}" ]] || continue

        if (( ip_req >= SWARM_PER_IP_REQ_THRESHOLD )); then
            add_ipset_block "${ip}" "proxy-swarm:${ip_req}:${p}" "${BLOCK_TTL}"
        fi
    done <<< "${swarm_stats}"

    printf '%s' "${triggered}"
}

update_ip_trust_from_rate_samples() {
    local syn_counts="$1"
    local est_counts="$2"
    local access_counts="$3"
    local probe_counts="$4"
    local sqli_counts="$5"
    local line count ip syn_warn est_warn access_warn

    [[ "${IP_TRUST_ENABLED:-0}" == "1" ]] || return 0

    syn_warn=$(( SYN_RECV_PER_IP_THRESHOLD * 2 ))
    est_warn=$(( ESTABLISHED_PER_IP_THRESHOLD * 2 ))
    access_warn=$(( HTTP_ACCESS_PER_WINDOW_THRESHOLD * 2 ))

    while read -r line; do
        [[ -z "${line}" ]] && continue
        count="$(awk '{print $1}' <<< "${line}")"
        ip="$(awk '{print $2}' <<< "${line}")"
        [[ "${count}" =~ ^[0-9]+$ ]] || continue
        [[ -n "${ip}" ]] || continue
        if (( count >= syn_warn )); then
            ip_trust_update "${ip}" 0 1 "syn-rate" "${count}"
        else
            ip_trust_update "${ip}" 1 0 "syn-rate" "${count}"
        fi
    done <<< "${syn_counts}"

    while read -r line; do
        [[ -z "${line}" ]] && continue
        count="$(awk '{print $1}' <<< "${line}")"
        ip="$(awk '{print $2}' <<< "${line}")"
        [[ "${count}" =~ ^[0-9]+$ ]] || continue
        [[ -n "${ip}" ]] || continue
        if (( count >= est_warn )); then
            ip_trust_update "${ip}" 0 1 "est-rate" "${count}"
        else
            ip_trust_update "${ip}" 1 0 "est-rate" "${count}"
        fi
    done <<< "${est_counts}"

    while read -r line; do
        [[ -z "${line}" ]] && continue
        count="$(awk '{print $1}' <<< "${line}")"
        ip="$(awk '{print $2}' <<< "${line}")"
        [[ "${count}" =~ ^[0-9]+$ ]] || continue
        [[ -n "${ip}" ]] || continue
        if (( count >= access_warn )); then
            ip_trust_update "${ip}" 0 1 "http-rate" "${count}"
        else
            ip_trust_update "${ip}" 1 0 "http-rate" "${count}"
        fi
    done <<< "${access_counts}"

    while read -r line; do
        [[ -z "${line}" ]] && continue
        count="$(awk '{print $1}' <<< "${line}")"
        ip="$(awk '{print $2}' <<< "${line}")"
        [[ "${count}" =~ ^[0-9]+$ ]] || continue
        [[ -n "${ip}" ]] || continue
        ip_trust_update "${ip}" 0 2 "probe-scan" "${count}"
    done <<< "${probe_counts}"

    while read -r line; do
        [[ -z "${line}" ]] && continue
        count="$(awk '{print $1}' <<< "${line}")"
        ip="$(awk '{print $2}' <<< "${line}")"
        [[ "${count}" =~ ^[0-9]+$ ]] || continue
        [[ -n "${ip}" ]] || continue
        ip_trust_update "${ip}" 0 2 "sqli-probe" "${count}"
    done <<< "${sqli_counts}"
}

update_ip_trust_from_behavior_samples() {
    local behavior_rows="$1"
    local row ip req uniq transitions max_streak

    [[ "${IP_TRUST_ENABLED:-0}" == "1" ]] || return 0

    while IFS=$'\t' read -r ip req uniq transitions max_streak; do
        [[ -n "${ip}" ]] || continue
        [[ "${req}" =~ ^[0-9]+$ ]] || continue
        [[ "${uniq}" =~ ^[0-9]+$ ]] || continue
        [[ "${transitions}" =~ ^[0-9]+$ ]] || continue
        [[ "${max_streak}" =~ ^[0-9]+$ ]] || continue
        (( req >= 10 )) || continue

        if (( req >= 20 && uniq >= 4 && transitions >= 3 && max_streak * 10 <= req * 8 )); then
            ip_trust_update "${ip}" 2 0 "behavior" "req=${req},uniq=${uniq},tr=${transitions},streak=${max_streak}"
        elif (( req >= 15 && uniq >= 3 && transitions >= 2 && max_streak * 10 <= req * 9 )); then
            ip_trust_update "${ip}" 1 0 "behavior" "req=${req},uniq=${uniq},tr=${transitions},streak=${max_streak}"
        fi

        if (( max_streak >= 30 )) || (( uniq <= 1 && req >= 20 )); then
            ip_trust_update "${ip}" 0 2 "behavior-anomaly" "req=${req},uniq=${uniq},tr=${transitions},streak=${max_streak}"
        elif (( transitions == 0 && req >= 12 )); then
            ip_trust_update "${ip}" 0 1 "behavior-anomaly" "req=${req},uniq=${uniq},tr=${transitions},streak=${max_streak}"
        fi
    done <<< "${behavior_rows}"
}

set_lockdown_state() {
    local enabled="$1"
    local reason="$2"
    local ttl="$3"
    local now until
    now="$(date +%s)"

    if [[ "${enabled}" == "1" ]]; then
        until=$(( now + ttl ))
        printf '{"enabled":true,"reason":"%s","until":%s,"updated_at":%s}\n' "${reason}" "${until}" "${now}" > "${LOCKDOWN_FLAG_FILE}"
        printf '[lockdown] enabled ttl=%s reason=%s\n' "${ttl}" "${reason}" >> "${LOG_FILE}"
        printf '[lockdown] enabled ttl=%s reason=%s\n' "${ttl}" "${reason}" >> "${LATEST_FILE}"
    else
        if [[ -f "${LOCKDOWN_FLAG_FILE}" ]]; then
            rm -f "${LOCKDOWN_FLAG_FILE}"
            printf '[lockdown] cleared\n' >> "${LOG_FILE}"
            printf '[lockdown] cleared\n' >> "${LATEST_FILE}"
        fi
    fi
}

set_mode_state() {
    local mode="$1"
    local reason="$2"
    local ttl="$3"
    local now until
    now="$(date +%s)"

    if [[ "${mode}" == "normal" ]]; then
        if [[ -f "${MODE_FLAG_FILE}" ]]; then
            rm -f "${MODE_FLAG_FILE}"
        fi
        if [[ "${MODE_STATE_CACHE}" != "normal" ]]; then
            printf '[mode] switched mode=normal reason=%s\n' "${reason}" >> "${LOG_FILE}"
            printf '[mode] switched mode=normal reason=%s\n' "${reason}" >> "${LATEST_FILE}"
        fi
        MODE_STATE_CACHE="normal"
        return 0
    fi

    until=$(( now + ttl ))
    printf '{"mode":"%s","reason":"%s","until":%s,"updated_at":%s}\n' "${mode}" "${reason}" "${until}" "${now}" > "${MODE_FLAG_FILE}"
    if [[ "${MODE_STATE_CACHE}" != "${mode}" ]]; then
        printf '[mode] switched mode=%s ttl=%s reason=%s\n' "${mode}" "${ttl}" "${reason}" >> "${LOG_FILE}"
        printf '[mode] switched mode=%s ttl=%s reason=%s\n' "${mode}" "${ttl}" "${reason}" >> "${LATEST_FILE}"
    fi
    MODE_STATE_CACHE="${mode}"
}

apply_emergency_nginx_profile() {
    local mode="$1"
    local now changed
    now="$(date +%s)"
    changed=0

    [[ "${EMERGENCY_NGINX_PROFILE_ENABLED:-0}" == "1" ]] || return 0
    command -v nginx >/dev/null 2>&1 || return 0

    if [[ "${mode}" == "emergency" ]]; then
        cat > "${NGINX_EMERGENCY_PROFILE_FILE}" <<EOF
# managed by pteroprotect ddos_host_logger.sh
keepalive_timeout 4s;
keepalive_requests 40;
client_header_timeout 3s;
send_timeout 8s;
EOF
        if [[ "${NGINX_PROFILE_STATE_CACHE}" != "emergency" ]]; then
            changed=1
            NGINX_PROFILE_STATE_CACHE="emergency"
        fi
    else
        if [[ -f "${NGINX_EMERGENCY_PROFILE_FILE}" ]]; then
            rm -f "${NGINX_EMERGENCY_PROFILE_FILE}"
            changed=1
        fi
        NGINX_PROFILE_STATE_CACHE="normal"
    fi

    (( changed == 1 )) || return 0
    if (( now - LAST_NGINX_RELOAD_TS < EMERGENCY_NGINX_RELOAD_MIN_INTERVAL_SEC )); then
        return 0
    fi

    if nginx -t >/dev/null 2>&1; then
        if systemctl reload nginx >/dev/null 2>&1; then
            LAST_NGINX_RELOAD_TS="${now}"
            printf '[mode] nginx_profile=%s reload=ok\n' "${mode}" >> "${LOG_FILE}"
            printf '[mode] nginx_profile=%s reload=ok\n' "${mode}" >> "${LATEST_FILE}"
        fi
    else
        printf '[mode] nginx_profile=%s reload=skip-invalid-config\n' "${mode}" >> "${LOG_FILE}"
        printf '[mode] nginx_profile=%s reload=skip-invalid-config\n' "${mode}" >> "${LATEST_FILE}"
    fi
}

while true; do
    TRAFFIC_PROFILE="$(trim "$(printf '%s' "$(read_network_setting traffic_profile mixed)" | tr '[:upper:]' '[:lower:]')")"
    MONITOR_TCP_PORTS="$(sanitize_ports_csv "$(read_network_setting monitor_tcp_ports '22,80,443,8080,2022')")"
    PORTS_REGEX="$(build_ports_regex "${MONITOR_TCP_PORTS}")"

    HOST_FIREWALL_ENABLED="$(normalize_bool "$(read_network_setting host_firewall_enabled 0)")"
    DYNAMIC_BLOCK_ENABLED="$(normalize_bool "$(read_network_setting dynamic_block_enabled 0)")"
    BLOCK_TTL="$(clamp_min_int "$(read_network_setting blackhole_ttl_sec 600)" 60)"
    SYN_RECV_GLOBAL_THRESHOLD="$(clamp_min_int "$(read_network_setting host_syn_recv_global_threshold 120)" 10)"
    SYN_RECV_PER_IP_THRESHOLD="$(clamp_min_int "$(read_network_setting host_syn_recv_per_ip 20)" 3)"
    ESTABLISHED_PER_IP_THRESHOLD="$(clamp_min_int "$(read_network_setting host_established_per_ip 80)" 5)"
    HTTP_ACCESS_PER_WINDOW_THRESHOLD="$(clamp_min_int "$(read_network_setting host_http_access_per_window 240)" 10)"
    LOG_TAIL_LINES="$(clamp_min_int "$(read_network_setting host_log_tail_lines 800)" 50)"
    SAMPLE_INTERVAL_SEC="$(clamp_min_int "$(read_network_setting host_sample_interval_sec 2)" 1)"
    TRUSTED_LOGIN_TTL_SEC="$(clamp_min_int "$(read_network_setting trusted_login_ttl_sec 2592000)" 60)"
    TRUSTED_LOGIN_THRESHOLD_MULTIPLIER="$(clamp_min_int "$(read_network_setting trusted_login_threshold_multiplier 4)" 1)"
    CLEAR_THRESHOLD_SIGNALS="$(clamp_min_int "$(read_network_setting host_clear_threshold_signals 2)" 1)"
    CLEAR_THRESHOLD_HARD_MULTIPLIER="$(clamp_min_int "$(read_network_setting host_clear_threshold_hard_multiplier 3)" 2)"
    DYNAMIC_BLOCK_DRY_RUN="$(normalize_bool "$(read_network_setting dynamic_block_dry_run 0)")"
    SELF_UNBLOCK_ESSENTIALS="$(normalize_bool "$(read_network_setting self_unblock_essentials 1)")"
    HTTP_IGNORE_PATH_REGEX="$(read_network_setting host_http_ignore_path_regex '^/api/client/servers/.+/websocket$|^/api/remote/')"
    HTTP_IGNORE_PATH_REGEX="$(sanitize_shell_single_quoted "${HTTP_IGNORE_PATH_REGEX}")"
    SELF_DDOS_QUARANTINE_ENABLED="$(normalize_bool "$(read_network_setting self_ddos_quarantine_enabled 0)")"
    SELF_DDOS_SERVER_REQ_THRESHOLD="$(clamp_min_int "$(read_network_setting self_ddos_server_req_threshold 300)" 20)"
    SELF_DDOS_RATE_LIMIT_ENABLED="$(normalize_bool "$(read_network_setting self_ddos_rate_limit_enabled 1)")"
    SELF_DDOS_RATE_LIMIT_RPS="$(clamp_min_int "$(read_network_setting self_ddos_rate_limit_rps 10)" 1)"
    SELF_DDOS_RATE_LIMIT_BURST="$(clamp_min_int "$(read_network_setting self_ddos_rate_limit_burst 20)" 1)"
    SELF_DDOS_RATE_LIMIT_TTL_SEC="$(clamp_min_int "$(read_network_setting self_ddos_rate_limit_ttl_sec 900)" 30)"
    SELF_DDOS_FLOW_WATCH_ENABLED="$(normalize_bool "$(read_network_setting self_ddos_flow_watch_enabled 1)")"
    SELF_DDOS_RATE_LIMIT_CHAIN_READY=0
    OWNER_QUARANTINE_THRESHOLD="$(clamp_min_int "$(read_network_setting owner_quarantine_threshold 5)" 1)"
    OWNER_QUARANTINE_WINDOW_SEC="$(clamp_min_int "$(read_network_setting owner_quarantine_window_sec 86400)" 300)"
    SCANNER_BLOCK_ENABLED="$(normalize_bool "$(read_network_setting scanner_block_enabled 1)")"
    PROBE_REQ_THRESHOLD="$(clamp_min_int "$(read_network_setting probe_req_threshold 16)" 2)"
    PROBE_PATH_REGEX="$(read_network_setting probe_path_regex '^/(wp-admin|wp-login\\.php|xmlrpc\\.php|boaform|cgi-bin|vendor/phpunit|\\.env|actuator|jmx-console|manager/html|login\\.do|console|solr/|owncloud/status\\.php|status\\.php|WebInterface|aspera/faspex|Telerik\\.Web\\.UI\\.WebResource\\.axd|sitecore|jasperserver|OA_HTML|identity|admin/|xampp|\\.git|server-status)')"
    PROBE_PATH_REGEX="$(sanitize_shell_single_quoted "${PROBE_PATH_REGEX}")"
    SQLI_PROBE_BLOCK_ENABLED="$(normalize_bool "$(read_network_setting sqli_probe_block_enabled 1)")"
    SQLI_PROBE_REQ_THRESHOLD="$(clamp_min_int "$(read_network_setting sqli_probe_req_threshold 4)" 2)"
    LIMITER_BLOCK_ENABLED="$(normalize_bool "$(read_network_setting limiter_block_enabled 1)")"
    LIMITER_REQ_THRESHOLD="$(clamp_min_int "$(read_network_setting limiter_req_threshold 20)" 3)"
    LIMITER_LOG_TAIL_LINES="$(clamp_min_int "$(read_network_setting limiter_log_tail_lines 1200)" 100)"
    BAD_TOKEN_BLOCK_ENABLED="$(normalize_bool "$(read_network_setting bad_token_block_enabled 1)")"
    BAD_TOKEN_FAIL_THRESHOLD="$(clamp_min_int "$(read_network_setting bad_token_fail_threshold 5)" 1)"
    BAD_TOKEN_LOG_TAIL_LINES="$(clamp_min_int "$(read_network_setting bad_token_log_tail_lines 2000)" 100)"
    BAD_TOKEN_PATH_REGEX="$(read_network_setting bad_token_path_regex '^/api/application/|^/api/client/|^/auth/login$')"
    BAD_TOKEN_STATUS_REGEX="$(read_network_setting bad_token_status_regex '^(401|403|419)$')"
    BAD_TOKEN_PATH_REGEX="$(sanitize_shell_single_quoted "${BAD_TOKEN_PATH_REGEX}")"
    BAD_TOKEN_STATUS_REGEX="$(sanitize_shell_single_quoted "${BAD_TOKEN_STATUS_REGEX}")"
    EMERGENCY_INPUT_GUARD_ENABLED="$(normalize_bool "$(read_network_setting emergency_input_guard_enabled 1)")"
    EMERGENCY_INPUT_CONN_LIMIT_PER_IP="$(clamp_min_int "$(read_network_setting emergency_input_conn_limit_per_ip 120)" 20)"
    EMERGENCY_INPUT_NEW_PER_IP_PER_SEC="$(clamp_min_int "$(read_network_setting emergency_input_new_per_ip_per_sec 80)" 5)"
    EMERGENCY_INPUT_NEW_PER_IP_BURST="$(clamp_min_int "$(read_network_setting emergency_input_new_per_ip_burst 120)" 10)"
    SSH_GUARD_ENABLED="$(normalize_bool "$(read_network_setting ssh_guard_enabled 1)")"
    SSH_CONN_LIMIT_PER_IP="$(clamp_min_int "$(read_network_setting ssh_conn_limit_per_ip 10)" 2)"
    SSH_NEW_PER_IP_PER_MIN="$(clamp_min_int "$(read_network_setting ssh_new_per_ip_per_min 20)" 2)"
    SSH_NEW_PER_IP_BURST="$(clamp_min_int "$(read_network_setting ssh_new_per_ip_burst 30)" 2)"
    INPUT_GUARD_ALL_TCP_ENABLED="$(normalize_bool "$(read_network_setting input_guard_all_tcp_enabled 1)")"
    INPUT_GUARD_ALL_TCP_CONN_LIMIT_PER_IP="$(clamp_min_int "$(read_network_setting input_guard_all_tcp_conn_limit_per_ip 40)" 5)"
    INPUT_GUARD_ALL_TCP_NEW_PER_IP_PER_SEC="$(clamp_min_int "$(read_network_setting input_guard_all_tcp_new_per_ip_per_sec 20)" 2)"
    INPUT_GUARD_ALL_TCP_NEW_PER_IP_BURST="$(clamp_min_int "$(read_network_setting input_guard_all_tcp_new_per_ip_burst 40)" 2)"
    INPUT_GUARD_ALL_UDP_ENABLED="$(normalize_bool "$(read_network_setting input_guard_all_udp_enabled 1)")"
    INPUT_GUARD_ALL_UDP_PER_IP_PER_SEC="$(clamp_min_int "$(read_network_setting input_guard_all_udp_per_ip_per_sec 150)" 10)"
    INPUT_GUARD_ALL_UDP_BURST="$(clamp_min_int "$(read_network_setting input_guard_all_udp_burst 300)" 20)"
    GLOBAL_NEW_GUARD_ENABLED="$(normalize_bool "$(read_network_setting global_new_guard_enabled 1)")"
    GLOBAL_NEW_PER_SEC="$(clamp_min_int "$(read_network_setting global_new_per_sec 700)" 50)"
    GLOBAL_NEW_BURST="$(clamp_min_int "$(read_network_setting global_new_burst 1400)" 100)"
    STRICT_LOCKDOWN_ENABLED="$(normalize_bool "$(read_network_setting strict_lockdown_enabled 0)")"
    STRICT_LOCKDOWN_TTL_SEC="$(clamp_min_int "$(read_network_setting strict_lockdown_ttl_sec 180)" 30)"
    STRICT_LOCKDOWN_ESTABLISHED_THRESHOLD="$(clamp_min_int "$(read_network_setting strict_lockdown_established_threshold 400)" 50)"
    STRICT_LOCKDOWN_SYN_RECV_THRESHOLD="$(clamp_min_int "$(read_network_setting strict_lockdown_syn_recv_threshold 160)" 20)"
    STRICT_LOCKDOWN_HTTP_ACCESS_THRESHOLD="$(clamp_min_int "$(read_network_setting strict_lockdown_http_access_threshold 320)" 20)"
    AUTO_MODE_ENABLED="$(normalize_bool "$(read_network_setting auto_mode_enabled 1)")"
    MODE_AGGRESSIVE_ESTABLISHED_THRESHOLD="$(clamp_min_int "$(read_network_setting mode_aggressive_established_threshold 220)" 20)"
    MODE_AGGRESSIVE_SYN_RECV_THRESHOLD="$(clamp_min_int "$(read_network_setting mode_aggressive_syn_recv_threshold 120)" 10)"
    MODE_AGGRESSIVE_HTTP_ACCESS_THRESHOLD="$(clamp_min_int "$(read_network_setting mode_aggressive_http_access_threshold 240)" 20)"
    MODE_AGGRESSIVE_TTL_SEC="$(clamp_min_int "$(read_network_setting mode_aggressive_ttl_sec 180)" 30)"
    MODE_EMERGENCY_ESTABLISHED_THRESHOLD="$(clamp_min_int "$(read_network_setting mode_emergency_established_threshold 400)" 20)"
    MODE_EMERGENCY_SYN_RECV_THRESHOLD="$(clamp_min_int "$(read_network_setting mode_emergency_syn_recv_threshold 180)" 10)"
    MODE_EMERGENCY_HTTP_ACCESS_THRESHOLD="$(clamp_min_int "$(read_network_setting mode_emergency_http_access_threshold 360)" 20)"
    MODE_EMERGENCY_TTL_SEC="$(clamp_min_int "$(read_network_setting mode_emergency_ttl_sec 180)" 30)"
    OVERLOAD_FAST_BAN_ENABLED="$(normalize_bool "$(read_network_setting overload_fast_ban_enabled 1)")"
    OVERLOAD_FAST_BAN_FACTOR_PCT="$(clamp_min_int "$(read_network_setting overload_fast_ban_factor_pct 70)" 20)"
    OVERLOAD_FAST_BAN_BOT_FACTOR_PCT="$(clamp_min_int "$(read_network_setting overload_fast_ban_bot_factor_pct 50)" 10)"
    NORMAL_PROFILE_FORCE_PROTECTION="$(normalize_bool "$(read_network_setting normal_profile_force_protection 1)")"
    NORMAL_PROFILE_MAX_BLOCK_TTL_SEC="$(clamp_min_int "$(read_network_setting normal_profile_max_block_ttl_sec 1800)" 60)"
    NORMAL_PROFILE_MAX_SYN_RECV_GLOBAL="$(clamp_min_int "$(read_network_setting normal_profile_max_syn_recv_global 260)" 10)"
    NORMAL_PROFILE_MAX_SYN_RECV_PER_IP="$(clamp_min_int "$(read_network_setting normal_profile_max_syn_recv_per_ip 40)" 3)"
    NORMAL_PROFILE_MAX_ESTABLISHED_PER_IP="$(clamp_min_int "$(read_network_setting normal_profile_max_established_per_ip 120)" 5)"
    NORMAL_PROFILE_MAX_HTTP_ACCESS_PER_WINDOW="$(clamp_min_int "$(read_network_setting normal_profile_max_http_access_per_window 64)" 10)"
    EMERGENCY_NGINX_PROFILE_ENABLED="$(normalize_bool "$(read_network_setting emergency_nginx_profile_enabled 1)")"
    EMERGENCY_NGINX_RELOAD_MIN_INTERVAL_SEC="$(clamp_min_int "$(read_network_setting emergency_nginx_reload_min_interval_sec 60)" 10)"
    SERVICE_ACTIVITY_PATH_REGEX="$(read_network_setting service_activity_path_regex '^/api/remote/|^/api/client/servers/.+/websocket$')"
    SERVICE_ACTIVITY_PATH_REGEX="$(sanitize_shell_single_quoted "${SERVICE_ACTIVITY_PATH_REGEX}")"
    SERVICE_ACTIVITY_AGGRESSIVE_THRESHOLD="$(clamp_min_int "$(read_network_setting service_activity_aggressive_threshold 120)" 10)"
    SERVICE_ACTIVITY_EMERGENCY_THRESHOLD="$(clamp_min_int "$(read_network_setting service_activity_emergency_threshold 240)" 20)"
    SERVICE_ACTIVITY_DELTA_AGGRESSIVE="$(clamp_min_int "$(read_network_setting service_activity_delta_aggressive 60)" 5)"
    SERVICE_ACTIVITY_DELTA_EMERGENCY="$(clamp_min_int "$(read_network_setting service_activity_delta_emergency 120)" 10)"
    SWARM_REQ_THRESHOLD="$(clamp_min_int "$(read_network_setting swarm_req_threshold 240)" 40)"
    SWARM_UNIQUE_IP_THRESHOLD="$(clamp_min_int "$(read_network_setting swarm_unique_ip_threshold 24)" 4)"
    SWARM_PER_IP_REQ_THRESHOLD="$(clamp_min_int "$(read_network_setting swarm_per_ip_req_threshold 24)" 4)"
    IP_TRUST_ENABLED="$(normalize_bool "$(read_network_setting ip_trust_enabled 1)")"
    IP_TRUST_PROMOTION_OBS="$(clamp_min_int "$(read_network_setting ip_trust_promotion_observations 80)" 10)"
    IP_TRUST_VTRUST_OBS="$(clamp_min_int "$(read_network_setting ip_trust_vtrusted_observations 240)" 20)"
    IP_TRUST_SCORE_TRUSTED="$(clamp_min_int "$(read_network_setting ip_trust_score_trusted 40)" 1)"
    IP_TRUST_SCORE_VTRUSTED="$(clamp_min_int "$(read_network_setting ip_trust_score_vtrusted 180)" 1)"
    IP_TRUST_SCORE_MIN="$(normalize_int "$(read_network_setting ip_trust_score_min -200)" -200)"
    IP_TRUST_SCORE_MAX="$(clamp_min_int "$(read_network_setting ip_trust_score_max 400)" 10)"
    IP_TRUST_SCORE_BAD="$(normalize_int "$(read_network_setting ip_trust_score_bad -20)" -20)"
    IP_TRUST_SCORE_WORST="$(normalize_int "$(read_network_setting ip_trust_score_worst -50)" -50)"
    IP_TRUST_BAD_BAD="$(clamp_min_int "$(read_network_setting ip_trust_bad_threshold 8)" 1)"
    IP_TRUST_WORST_BAD="$(clamp_min_int "$(read_network_setting ip_trust_worst_threshold 16)" 1)"
    IP_TRUST_TIER_TTL_SEC="$(clamp_min_int "$(read_network_setting ip_trust_tier_ttl_sec 86400)" 60)"
    if (( IP_TRUST_SCORE_MIN > IP_TRUST_SCORE_MAX )); then
        IP_TRUST_SCORE_MIN=-200
        IP_TRUST_SCORE_MAX=400
    fi
    ESCALATION_WINDOW_SEC="$(clamp_min_int "$(read_network_setting block_escalation_window_sec 86400)" 300)"
    ESCALATION_MULTIPLIER="$(clamp_min_int "$(read_network_setting block_escalation_multiplier 4)" 1)"
    MAX_ESCALATION_STEPS="$(clamp_min_int "$(read_network_setting block_escalation_max_steps 3)" 1)"
    MAX_BLACKHOLE_TTL_SEC="$(clamp_min_int "$(read_network_setting max_blackhole_ttl_sec 86400)" 60)"

    case "${TRAFFIC_PROFILE}" in
        api|api-heavy|api_hosting)
            # API-heavy host: keep protection on, but avoid false aggressive mode from legitimate API/ws pulses.
            HTTP_ACCESS_PER_WINDOW_THRESHOLD=$(( HTTP_ACCESS_PER_WINDOW_THRESHOLD * 3 ))
            MODE_AGGRESSIVE_HTTP_ACCESS_THRESHOLD=$(( MODE_AGGRESSIVE_HTTP_ACCESS_THRESHOLD * 4 ))
            MODE_EMERGENCY_HTTP_ACCESS_THRESHOLD=$(( MODE_EMERGENCY_HTTP_ACCESS_THRESHOLD * 4 ))
            SERVICE_ACTIVITY_AGGRESSIVE_THRESHOLD=$(( SERVICE_ACTIVITY_AGGRESSIVE_THRESHOLD * 6 ))
            SERVICE_ACTIVITY_EMERGENCY_THRESHOLD=$(( SERVICE_ACTIVITY_EMERGENCY_THRESHOLD * 6 ))
            SERVICE_ACTIVITY_DELTA_AGGRESSIVE=$(( SERVICE_ACTIVITY_DELTA_AGGRESSIVE * 6 ))
            SERVICE_ACTIVITY_DELTA_EMERGENCY=$(( SERVICE_ACTIVITY_DELTA_EMERGENCY * 6 ))
            ;;
        small|small-web|smallweb|website-small)
            # Small website: react quickly to spikes but keep normal users from easy false-positive.
            HTTP_ACCESS_PER_WINDOW_THRESHOLD=$(( HTTP_ACCESS_PER_WINDOW_THRESHOLD * 2 ))
            MODE_AGGRESSIVE_SYN_RECV_THRESHOLD=$(( MODE_AGGRESSIVE_SYN_RECV_THRESHOLD * 8 / 10 ))
            MODE_EMERGENCY_SYN_RECV_THRESHOLD=$(( MODE_EMERGENCY_SYN_RECV_THRESHOLD * 8 / 10 ))
            MODE_AGGRESSIVE_ESTABLISHED_THRESHOLD=$(( MODE_AGGRESSIVE_ESTABLISHED_THRESHOLD * 8 / 10 ))
            MODE_EMERGENCY_ESTABLISHED_THRESHOLD=$(( MODE_EMERGENCY_ESTABLISHED_THRESHOLD * 8 / 10 ))
            ;;
        *)
            ;;
    esac

    # Baseline guard: even in lowest profile ("normal"), core protection stays on.
    if [[ "${NORMAL_PROFILE_FORCE_PROTECTION}" == "1" ]]; then
        HOST_FIREWALL_ENABLED=1
        DYNAMIC_BLOCK_ENABLED=1
        SCANNER_BLOCK_ENABLED=1
        SQLI_PROBE_BLOCK_ENABLED=1
        LIMITER_BLOCK_ENABLED=1
        BAD_TOKEN_BLOCK_ENABLED=1
        SELF_DDOS_RATE_LIMIT_ENABLED=1
        SELF_DDOS_FLOW_WATCH_ENABLED=1
        SSH_GUARD_ENABLED=1
        INPUT_GUARD_ALL_TCP_ENABLED=1
        INPUT_GUARD_ALL_UDP_ENABLED=1
        GLOBAL_NEW_GUARD_ENABLED=1

        if (( BLOCK_TTL > NORMAL_PROFILE_MAX_BLOCK_TTL_SEC )); then
            BLOCK_TTL="${NORMAL_PROFILE_MAX_BLOCK_TTL_SEC}"
        fi
        if (( SYN_RECV_GLOBAL_THRESHOLD > NORMAL_PROFILE_MAX_SYN_RECV_GLOBAL )); then
            SYN_RECV_GLOBAL_THRESHOLD="${NORMAL_PROFILE_MAX_SYN_RECV_GLOBAL}"
        fi
        if (( SYN_RECV_PER_IP_THRESHOLD > NORMAL_PROFILE_MAX_SYN_RECV_PER_IP )); then
            SYN_RECV_PER_IP_THRESHOLD="${NORMAL_PROFILE_MAX_SYN_RECV_PER_IP}"
        fi
        if (( ESTABLISHED_PER_IP_THRESHOLD > NORMAL_PROFILE_MAX_ESTABLISHED_PER_IP )); then
            ESTABLISHED_PER_IP_THRESHOLD="${NORMAL_PROFILE_MAX_ESTABLISHED_PER_IP}"
        fi
        if (( HTTP_ACCESS_PER_WINDOW_THRESHOLD > NORMAL_PROFILE_MAX_HTTP_ACCESS_PER_WINDOW )); then
            HTTP_ACCESS_PER_WINDOW_THRESHOLD="${NORMAL_PROFILE_MAX_HTTP_ACCESS_PER_WINDOW}"
        fi
    fi

    WHITELIST_IPS="$(resolve_trusted_ips | sed '/^$/d' | sort -u | paste -sd, -)"

    DB_HOST_IPS="$(resolve_host_ips "$(read_panel_env DB_HOST)" | paste -sd, -)"
    if [[ -n "${DB_HOST_IPS}" ]]; then
        for dbip in ${DB_HOST_IPS//,/ }; do
            WHITELIST_IPS="$(append_csv_unique "${WHITELIST_IPS}" "${dbip}")"
        done
    fi

    SSH_TRUSTED_IPS="$(current_ssh_client_ips)"
    if [[ -n "${SSH_TRUSTED_IPS}" ]]; then
        for sship in ${SSH_TRUSTED_IPS//,/ }; do
            WHITELIST_IPS="$(append_csv_unique "${WHITELIST_IPS}" "${sship}")"
        done
    fi

    MANUAL_ESSENTIAL_IPS="$(resolve_manual_essential_ips | paste -sd, -)"
    if [[ -n "${MANUAL_ESSENTIAL_IPS}" ]]; then
        for eip in ${MANUAL_ESSENTIAL_IPS//,/ }; do
            WHITELIST_IPS="$(append_csv_unique "${WHITELIST_IPS}" "${eip}")"
        done
    fi

    TRUSTED_LOGIN_IPS="$(resolve_recent_login_ips "${TRUSTED_LOGIN_TTL_SEC}" | paste -sd, -)"
    WEBSOCKET_TRUSTED_IPS="$(resolve_recent_websocket_ips "${LOG_TAIL_LINES}" '^/api/client/servers/.+/websocket$' | paste -sd, -)"
    if [[ -n "${WEBSOCKET_TRUSTED_IPS}" ]]; then
        for wsip in ${WEBSOCKET_TRUSTED_IPS//,/ }; do
            WHITELIST_IPS="$(append_csv_unique "${WHITELIST_IPS}" "${wsip}")"
        done
    fi
    ensure_whitelist_not_blocked
    ensure_lightweight_block_hooks
    ip_trust_restore_once

    timestamp="$(date -u '+%Y-%m-%d %H:%M:%S UTC')"

    established="$(safe_cmd "ss -tn state established | awk 'NR>1 {print \$3}' | grep -E '${PORTS_REGEX}' | wc -l")"
    syn_recv="$(safe_cmd "ss -tn state syn-recv | awk 'NR>1 {print \$3}' | grep -E '${PORTS_REGEX}' | wc -l")"
    time_wait="$(safe_cmd "ss -tn state time-wait | awk 'NR>1 {print \$3}' | grep -E '${PORTS_REGEX}' | wc -l")"

    syn_ip_counts="$(extract_remote_ip_counts syn-recv)"
    established_ip_counts="$(extract_remote_ip_counts established)"
    top_established="$(sed -n '1,10p' <<< "${established_ip_counts}")"
    top_syn="$(sed -n '1,10p' <<< "${syn_ip_counts}")"
    access_ip_counts="$(safe_cmd "tail -n ${LOG_TAIL_LINES} ${PANEL_ACCESS_LOG} 2>/dev/null | awk -v ignore_re='${HTTP_IGNORE_PATH_REGEX}' 'NF >= 7 && \$7 !~ ignore_re {print \$1}' | sed 's/,.*//; s/\\[//g; s/\\]//g' | sed '/^$/d' | sort | uniq -c | sort -nr")"
    server_identifier_counts="$(extract_server_identifier_counts "${LOG_TAIL_LINES}" "${HTTP_IGNORE_PATH_REGEX}")"
    server_identifier_ip_stats="$(extract_server_identifier_ip_stats "${LOG_TAIL_LINES}" "${HTTP_IGNORE_PATH_REGEX}")"
    probe_ip_counts="$(extract_probe_ip_counts "${LOG_TAIL_LINES}" "${PROBE_PATH_REGEX}")"
    sqli_probe_ip_counts="$(extract_sqli_probe_ip_counts "${LOG_TAIL_LINES}")"
    limiter_ip_counts="$(extract_limiter_ip_counts "${LIMITER_LOG_TAIL_LINES}")"
    bad_token_ip_counts="$(extract_bad_token_ip_counts "${BAD_TOKEN_LOG_TAIL_LINES}" "${BAD_TOKEN_PATH_REGEX}" "${BAD_TOKEN_STATUS_REGEX}")"
    behavior_ip_stats="$(extract_behavior_ip_stats "${LOG_TAIL_LINES}" "${HTTP_IGNORE_PATH_REGEX}")"
    BEHAVIOR_IP_STATS="${behavior_ip_stats}"
    path_swarm_stats="$(extract_path_swarm_stats "${LOG_TAIL_LINES}" "${HTTP_IGNORE_PATH_REGEX}")"
    top_http_access="$(sed -n '1,10p' <<< "${access_ip_counts}")"
    top_server_identifiers="$(sed -n '1,10p' <<< "${server_identifier_counts}")"
    top_probe_ips="$(sed -n '1,10p' <<< "${probe_ip_counts}")"
    top_sqli_probe_ips="$(sed -n '1,10p' <<< "${sqli_probe_ip_counts}")"
    top_limiter_ips="$(sed -n '1,10p' <<< "${limiter_ip_counts}")"
    top_bad_token_ips="$(sed -n '1,10p' <<< "${bad_token_ip_counts}")"
    service_pulse_count="$(extract_service_pulse_count "${LOG_TAIL_LINES}" "${SERVICE_ACTIVITY_PATH_REGEX}")"
    [[ "${service_pulse_count}" =~ ^[0-9]+$ ]] || service_pulse_count=0
    service_pulse_delta=$(( service_pulse_count - LAST_SERVICE_PULSE_COUNT ))
    if (( service_pulse_delta < 0 )); then
        service_pulse_delta=0
    fi
    LAST_SERVICE_PULSE_COUNT="${service_pulse_count}"
    limiter_hits="$(safe_cmd "tail -n 400 /var/log/nginx/pterodactyl.app-error.log | grep -E 'pteroprotect_req|pteroprotect_conn' | tail -n 40")"
    chain_dump="$(safe_cmd "iptables -L PTEROPROTECT-HOST -n -v --line-numbers")"
    abuse_chain_dump="$(safe_cmd "iptables -L PTEROPROTECT-HOST-ABUSE -n -v --line-numbers")"
    chain6_dump="$(safe_cmd "ip6tables -L PTEROPROTECT-HOST-V6 -n -v --line-numbers")"
    abuse_chain6_dump="$(safe_cmd "ip6tables -L PTEROPROTECT-HOST-V6-ABUSE -n -v --line-numbers")"
    ipset_v4_dump="$(safe_cmd "ipset list pteroprotect_block_v4")"
    ipset_v6_dump="$(safe_cmd "ipset list pteroprotect_block_v6")"
    conntrack_summary="$(safe_cmd "conntrack -S | tail -n 20")"

    detect_and_block_offenders "${syn_recv}" "${syn_ip_counts}" "${established_ip_counts}" "${access_ip_counts}" \
        "${BLOCK_TTL}" "${SYN_RECV_GLOBAL_THRESHOLD}" "${SYN_RECV_PER_IP_THRESHOLD}" \
        "${ESTABLISHED_PER_IP_THRESHOLD}" "${HTTP_ACCESS_PER_WINDOW_THRESHOLD}"
    detect_self_ddos_tenants "${server_identifier_counts}" "${server_identifier_ip_stats}"
    detect_probe_scanners "${probe_ip_counts}"
    detect_sqli_probes "${sqli_probe_ip_counts}"
    detect_limiter_abusers "${limiter_ip_counts}"
    detect_bad_token_abusers "${bad_token_ip_counts}"
    swarm_hits="$(detect_proxy_swarm_patterns "${path_swarm_stats}")"
    [[ "${swarm_hits}" =~ ^[0-9]+$ ]] || swarm_hits=0
    update_ip_trust_from_rate_samples "${syn_ip_counts}" "${established_ip_counts}" "${access_ip_counts}" "${probe_ip_counts}" "${sqli_probe_ip_counts}"
    update_ip_trust_from_behavior_samples "${behavior_ip_stats}"

    top_http_count="$(awk 'NF >= 1 {print $1; exit}' <<< "${access_ip_counts}" 2>/dev/null || true)"
    [[ "${top_http_count}" =~ ^[0-9]+$ ]] || top_http_count=0

    desired_mode="normal"
    desired_reason="steady-state"
    desired_ttl=0
    if [[ "${AUTO_MODE_ENABLED}" == "1" ]]; then
        emergency_load_signal=0
        emergency_delay_signal=0
        if (( established >= MODE_EMERGENCY_ESTABLISHED_THRESHOLD )) || (( syn_recv >= MODE_EMERGENCY_SYN_RECV_THRESHOLD )) || (( top_http_count >= MODE_EMERGENCY_HTTP_ACCESS_THRESHOLD )); then
            emergency_load_signal=1
        fi
        if (( service_pulse_count >= SERVICE_ACTIVITY_EMERGENCY_THRESHOLD )) || (( service_pulse_delta >= SERVICE_ACTIVITY_DELTA_EMERGENCY )); then
            emergency_delay_signal=1
        fi

        if (( emergency_load_signal == 1 )) && (( emergency_delay_signal == 1 )); then
            desired_mode="emergency"
            desired_reason="established=${established},syn_recv=${syn_recv},top_http=${top_http_count},service_pulse=${service_pulse_count},service_delta=${service_pulse_delta}"
            desired_ttl="${MODE_EMERGENCY_TTL_SEC}"
        elif (( established >= MODE_AGGRESSIVE_ESTABLISHED_THRESHOLD )) || (( syn_recv >= MODE_AGGRESSIVE_SYN_RECV_THRESHOLD )) || (( top_http_count >= MODE_AGGRESSIVE_HTTP_ACCESS_THRESHOLD )) || (( service_pulse_count >= SERVICE_ACTIVITY_AGGRESSIVE_THRESHOLD )) || (( service_pulse_delta >= SERVICE_ACTIVITY_DELTA_AGGRESSIVE )) || (( swarm_hits >= 1 )); then
            desired_mode="aggressive"
            desired_reason="established=${established},syn_recv=${syn_recv},top_http=${top_http_count},service_pulse=${service_pulse_count},service_delta=${service_pulse_delta},swarm=${swarm_hits}"
            desired_ttl="${MODE_AGGRESSIVE_TTL_SEC}"
        fi
    fi
    set_mode_state "${desired_mode}" "${desired_reason}" "${desired_ttl}"
    apply_emergency_nginx_profile "${desired_mode}"

    if [[ "${STRICT_LOCKDOWN_ENABLED}" == "1" ]] && {
        (( established >= STRICT_LOCKDOWN_ESTABLISHED_THRESHOLD )) ||
        (( syn_recv >= STRICT_LOCKDOWN_SYN_RECV_THRESHOLD )) ||
        (( top_http_count >= STRICT_LOCKDOWN_HTTP_ACCESS_THRESHOLD ));
    }; then
        set_lockdown_state "1" "established=${established},syn_recv=${syn_recv},top_http=${top_http_count}" "${STRICT_LOCKDOWN_TTL_SEC}"
    else
        set_lockdown_state "0" "" "0"
    fi

    {
        echo "=== ${timestamp} ==="
        echo "summary established=${established} syn_recv=${syn_recv} time_wait=${time_wait}"
        echo "mitigation dynamic_enabled=${DYNAMIC_BLOCK_ENABLED} block_ttl=${BLOCK_TTL} syn_global_threshold=${SYN_RECV_GLOBAL_THRESHOLD} syn_per_ip_threshold=${SYN_RECV_PER_IP_THRESHOLD} est_per_ip_threshold=${ESTABLISHED_PER_IP_THRESHOLD} http_access_threshold=${HTTP_ACCESS_PER_WINDOW_THRESHOLD} trusted_login_ttl=${TRUSTED_LOGIN_TTL_SEC} trusted_login_multiplier=${TRUSTED_LOGIN_THRESHOLD_MULTIPLIER} clear_threshold_signals=${CLEAR_THRESHOLD_SIGNALS} clear_threshold_hard_multiplier=${CLEAR_THRESHOLD_HARD_MULTIPLIER} escalation_window=${ESCALATION_WINDOW_SEC} escalation_multiplier=${ESCALATION_MULTIPLIER} escalation_max_steps=${MAX_ESCALATION_STEPS} max_block_ttl=${MAX_BLACKHOLE_TTL_SEC}"
        echo "--- top_established ---"
        echo "${top_established:-none}"
        echo "--- top_syn_recv ---"
        echo "${top_syn:-none}"
        echo "--- top_http_access ---"
        echo "${top_http_access:-none}"
        echo "--- top_server_identifiers ---"
        echo "${top_server_identifiers:-none}"
        echo "--- top_probe_ips ---"
        echo "${top_probe_ips:-none}"
        echo "--- top_sqli_probe_ips ---"
        echo "${top_sqli_probe_ips:-none}"
        echo "--- top_nginx_limiter_ips ---"
        echo "${top_limiter_ips:-none}"
        echo "--- top_bad_token_ips ---"
        echo "${top_bad_token_ips:-none}"
        echo "--- service_pulse ---"
        echo "count=${service_pulse_count} delta=${service_pulse_delta} regex=${SERVICE_ACTIVITY_PATH_REGEX} swarm_hits=${swarm_hits:-0}"
        echo "--- nginx_limiter_hits ---"
        echo "${limiter_hits:-none}"
        echo "--- iptables_pteroprotect_host ---"
        echo "${chain_dump:-none}"
        echo "--- iptables_pteroprotect_host_abuse ---"
        echo "${abuse_chain_dump:-none}"
        echo "--- ip6tables_pteroprotect_host ---"
        echo "${chain6_dump:-none}"
        echo "--- ip6tables_pteroprotect_host_abuse ---"
        echo "${abuse_chain6_dump:-none}"
        echo "--- ipset_block_v4 ---"
        echo "${ipset_v4_dump:-none}"
        echo "--- ipset_block_v6 ---"
        echo "${ipset_v6_dump:-none}"
        echo "--- conntrack_summary ---"
        echo "${conntrack_summary:-none}"
        echo
    } >> "${LOG_FILE}"

    {
        echo "=== ${timestamp} ==="
        echo "summary established=${established} syn_recv=${syn_recv} time_wait=${time_wait}"
        echo "mitigation dynamic_enabled=${DYNAMIC_BLOCK_ENABLED} block_ttl=${BLOCK_TTL} syn_global_threshold=${SYN_RECV_GLOBAL_THRESHOLD} syn_per_ip_threshold=${SYN_RECV_PER_IP_THRESHOLD} est_per_ip_threshold=${ESTABLISHED_PER_IP_THRESHOLD} http_access_threshold=${HTTP_ACCESS_PER_WINDOW_THRESHOLD} trusted_login_ttl=${TRUSTED_LOGIN_TTL_SEC} trusted_login_multiplier=${TRUSTED_LOGIN_THRESHOLD_MULTIPLIER} clear_threshold_signals=${CLEAR_THRESHOLD_SIGNALS} clear_threshold_hard_multiplier=${CLEAR_THRESHOLD_HARD_MULTIPLIER} escalation_window=${ESCALATION_WINDOW_SEC} escalation_multiplier=${ESCALATION_MULTIPLIER} escalation_max_steps=${MAX_ESCALATION_STEPS} max_block_ttl=${MAX_BLACKHOLE_TTL_SEC}"
        echo "--- top_established ---"
        echo "${top_established:-none}"
        echo "--- top_syn_recv ---"
        echo "${top_syn:-none}"
        echo "--- top_http_access ---"
        echo "${top_http_access:-none}"
        echo "--- top_server_identifiers ---"
        echo "${top_server_identifiers:-none}"
        echo "--- top_probe_ips ---"
        echo "${top_probe_ips:-none}"
        echo "--- top_sqli_probe_ips ---"
        echo "${top_sqli_probe_ips:-none}"
        echo "--- top_nginx_limiter_ips ---"
        echo "${top_limiter_ips:-none}"
        echo "--- top_bad_token_ips ---"
        echo "${top_bad_token_ips:-none}"
        echo "--- service_pulse ---"
        echo "count=${service_pulse_count} delta=${service_pulse_delta} regex=${SERVICE_ACTIVITY_PATH_REGEX}"
    } > "${LATEST_FILE}"

    trim_lines "${LOG_FILE}" 4000
    sleep "${SAMPLE_INTERVAL_SEC}"
done

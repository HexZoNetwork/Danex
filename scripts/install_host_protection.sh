#!/usr/bin/env bash
set -euo pipefail

CHAIN="PTEROPROTECT-HOST"
ABUSE_CHAIN="PTEROPROTECT-HOST-ABUSE"
BW_CHAIN="PTEROPROTECT-HOST-BW"
RAW_CHAIN="PTEROPROTECT-HOST-RAW"
SYNPROXY_CHAIN="PTEROPROTECT-HOST-SYNPROXY"
CHAIN6="PTEROPROTECT-HOST-V6"
ABUSE_CHAIN6="PTEROPROTECT-HOST-V6-ABUSE"
BW_CHAIN6="PTEROPROTECT-HOST-V6-BW"
RAW_CHAIN6="PTEROPROTECT-HOST-V6-RAW"
SYNPROXY_CHAIN6="PTEROPROTECT-V6-SYNPROXY"
DOCKER_CHAIN="PTEROPROTECT-DOCKER"
WINGS_GUARD_CHAIN4="PTEROPROTECT-WINGS"
WINGS_GUARD_CHAIN6="PTEROPROTECT-WINGS-V6"
INFRA_GUARD_CHAIN4="PTEROPROTECT-INFRA"
INFRA_GUARD_CHAIN6="PTEROPROTECT-INFRA-V6"
IPSET4="pteroprotect_block_v4"
IPSET6="pteroprotect_block_v6"
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
BLACKHOLE_TTL="${PTEROPROTECT_BLACKHOLE_TTL:-600}"
HOST_IPS="$(hostname -I 2>/dev/null || true)"
IPV6_ENABLED="${PTEROPROTECT_IPV6_ENABLED:-1}"
PUBLIC_TCP_PORTS="${PTEROPROTECT_PUBLIC_TCP_PORTS:-80,443}"
EGRESS_GUARD_ENABLED="${PTEROPROTECT_EGRESS_GUARD_ENABLED:-1}"
DOCKER_STRICT_ISOLATION_ENABLED="${PTEROPROTECT_DOCKER_STRICT_ISOLATION_ENABLED:-1}"
EGRESS_TCP_BLOCK_PORTS="${PTEROPROTECT_EGRESS_TCP_BLOCK_PORTS:-25,465,587,2525,23,2323,4444,5555,6667,6697,11211}"
EGRESS_UDP_BLOCK_PORTS="${PTEROPROTECT_EGRESS_UDP_BLOCK_PORTS:-19,123,161,1900,11211}"
NEW_CONN_RATE="${PTEROPROTECT_NEW_CONN_RATE:-12}"
NEW_CONN_BURST="${PTEROPROTECT_NEW_CONN_BURST:-20}"
CONNLIMIT_PER_IP="${PTEROPROTECT_CONNLIMIT_PER_IP:-30}"
UDP_GUARD_ENABLED="${PTEROPROTECT_UDP_GUARD_ENABLED:-1}"
UDP_PER_IP_RATE="${PTEROPROTECT_UDP_PER_IP_RATE:-150}"
UDP_BURST="${PTEROPROTECT_UDP_BURST:-300}"
RECENT_HITCOUNT="${PTEROPROTECT_RECENT_HITCOUNT:-60}"
RECENT_WINDOW="${PTEROPROTECT_RECENT_WINDOW:-5}"
IP_TRUST_BW_ENABLED="${PTEROPROTECT_IP_TRUST_BW_ENABLED:-1}"
IP_TRUST_BW_PROBATION_KBPS="${PTEROPROTECT_IP_TRUST_BW_PROBATION_KBPS:-2500}"
IP_TRUST_BW_BAD_KBPS="${PTEROPROTECT_IP_TRUST_BW_BAD_KBPS:-1000}"
IP_TRUST_BW_WORST_KBPS="${PTEROPROTECT_IP_TRUST_BW_WORST_KBPS:-100}"
IP_TRUST_BW_TRUSTED_KBPS="${PTEROPROTECT_IP_TRUST_BW_TRUSTED_KBPS:-40000}"
IP_TRUST_BW_VTRUSTED_KBPS="${PTEROPROTECT_IP_TRUST_BW_VTRUSTED_KBPS:-500000}"
IP_TRUST_BW_BURST_KB="${PTEROPROTECT_IP_TRUST_BW_BURST_KB:-512}"
SYNPROXY_ENABLED="${PTEROPROTECT_SYNPROXY_ENABLED:-1}"
SYNPROXY_MSS="${PTEROPROTECT_SYNPROXY_MSS:-1460}"
SYNPROXY_WSCALE="${PTEROPROTECT_SYNPROXY_WSCALE:-7}"
INFRA_HOSTS_RAW="${PTEROPROTECT_INFRA_HOSTS:-}"
WINGS_CONFIG="${PTEROPROTECT_WINGS_CONFIG:-/etc/pterodactyl/config.yml}"
WINGS_DOCKER_NETWORK_NAME="${PTEROPROTECT_WINGS_DOCKER_NETWORK_NAME:-}"
GUARD_HOME="${DANN_GUARD_HOME:-/pteroprotect}"
CONFIG_PATH="${GUARD_HOME}/config.json"
UNBLOCK_PORTAL_PORT="${PTEROPROTECT_UNBLOCK_PORTAL_PORT:-}"
WINGS_API_PORT="${PTEROPROTECT_WINGS_API_PORT:-}"
WINGS_SFTP_PORT="${PTEROPROTECT_WINGS_SFTP_PORT:-}"
WINGS_GUARD_CONNLIMIT_PER_IP="${PTEROPROTECT_WINGS_GUARD_CONNLIMIT_PER_IP:-32}"
WINGS_GUARD_NEW_CONN_RATE="${PTEROPROTECT_WINGS_GUARD_NEW_CONN_RATE:-10}"
WINGS_GUARD_NEW_CONN_BURST="${PTEROPROTECT_WINGS_GUARD_NEW_CONN_BURST:-20}"
SSH_GUARD_PORTS="${PTEROPROTECT_SSH_GUARD_PORTS:-22,2022}"
INFRA_GUARD_PORTS="${PTEROPROTECT_INFRA_GUARD_PORTS:-22,2022,8080,3306,5432,6379}"
PROTECTED_TCP_PORTS=""
INFRA_CONNLIMIT_PER_IP="${PTEROPROTECT_INFRA_CONNLIMIT_PER_IP:-12}"
INFRA_NEW_CONN_RATE="${PTEROPROTECT_INFRA_NEW_CONN_RATE:-8}"
INFRA_NEW_CONN_BURST="${PTEROPROTECT_INFRA_NEW_CONN_BURST:-16}"
INFRA_GLOBAL_NEW_PER_SEC="${PTEROPROTECT_INFRA_GLOBAL_NEW_PER_SEC:-120}"
INFRA_GLOBAL_NEW_BURST="${PTEROPROTECT_INFRA_GLOBAL_NEW_BURST:-240}"
SSH_CONNLIMIT_PER_IP="${PTEROPROTECT_SSH_CONNLIMIT_PER_IP:-10}"
SSH_NEW_PER_IP_PER_MIN="${PTEROPROTECT_SSH_NEW_PER_IP_PER_MIN:-20}"
SSH_NEW_PER_IP_BURST="${PTEROPROTECT_SSH_NEW_PER_IP_BURST:-30}"
SSH_GLOBAL_NEW_PER_SEC="${PTEROPROTECT_SSH_GLOBAL_NEW_PER_SEC:-90}"
SSH_GLOBAL_NEW_BURST="${PTEROPROTECT_SSH_GLOBAL_NEW_BURST:-220}"
TCP_GLOBAL_NEW_PER_SEC="${PTEROPROTECT_TCP_GLOBAL_NEW_PER_SEC:-1200}"
TCP_GLOBAL_NEW_BURST="${PTEROPROTECT_TCP_GLOBAL_NEW_BURST:-2400}"
IPSET_RUNTIME_OK=1

have_cmd() {
    if [[ "$1" == "ipset" && "${IPSET_RUNTIME_OK}" != "1" ]]; then
        return 1
    fi
    command -v "$1" >/dev/null 2>&1
}

init_ipset_runtime() {
    command -v ipset >/dev/null 2>&1 || {
        IPSET_RUNTIME_OK=0
        return 0
    }

    if ! ipset create "${IPSET4}" hash:ip family inet timeout "${BLACKHOLE_TTL}" -exist >/dev/null 2>&1; then
        # Try to bring up common kernel modules before giving up on ipset backend.
        modprobe ip_set >/dev/null 2>&1 || true
        modprobe ip_set_hash_ip >/dev/null 2>&1 || true
        modprobe xt_set >/dev/null 2>&1 || true

        if ! ipset create "${IPSET4}" hash:ip family inet timeout "${BLACKHOLE_TTL}" -exist >/dev/null 2>&1; then
            echo "[host_protection] warning: ipset backend unavailable; continuing with non-ipset firewall mode." >&2
            IPSET_RUNTIME_OK=0
            return 0
        fi
    fi
    if ! ipset list "${IPSET4}" >/dev/null 2>&1; then
        echo "[host_protection] warning: ipset set ${IPSET4} missing after create; disabling ipset integration." >&2
        IPSET_RUNTIME_OK=0
        return 0
    fi

    if [[ "${IPV6_ENABLED}" == "1" ]]; then
        ipset create "${IPSET6}" hash:ip family inet6 timeout "${BLACKHOLE_TTL}" -exist >/dev/null 2>&1 || true
    fi
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

sanitize_ports() {
    local raw="$1"
    local sanitized
    sanitized="$(printf '%s' "${raw}" | tr -cd '0-9,')"
    sanitized="${sanitized#,}"
    sanitized="${sanitized%,}"
    if [[ -z "${sanitized}" ]]; then
        sanitized="80,443"
    fi
    printf '%s' "${sanitized}"
}

merge_ports() {
    local left="$1"
    local right="$2"
    local merged=""
    local p
    left="$(sanitize_ports "${left}")"
    right="$(sanitize_ports "${right}")"
    IFS=',' read -r -a __left <<< "${left}"
    IFS=',' read -r -a __right <<< "${right}"
    for p in "${__left[@]}" "${__right[@]}"; do
        [[ -z "${p}" ]] && continue
        [[ "${p}" =~ ^[0-9]+$ ]] || continue
        if [[ -z "${merged}" ]]; then
            merged="${p}"
        elif [[ ",${merged}," != *",${p},"* ]]; then
            merged="${merged},${p}"
        fi
    done
    if [[ -z "${merged}" ]]; then
        merged="80,443,22,2022"
    fi
    printf '%s' "${merged}"
}

read_unblock_portal_port() {
    local port="${UNBLOCK_PORTAL_PORT}"
    if [[ -z "${port}" && -f "${CONFIG_PATH}" ]] && have_cmd python3; then
        port="$(python3 - <<'PY' "${CONFIG_PATH}" 2>/dev/null || true
import json,sys
p=sys.argv[1]
try:
    with open(p,'r',encoding='utf-8') as f:
        d=json.load(f)
    v=(d.get('network') or {}).get('unblock_portal_port',18443)
    print(v)
except Exception:
    pass
PY
)"
    fi
    if [[ ! "${port}" =~ ^[0-9]+$ ]]; then
        port="18443"
    fi
    printf '%s' "${port}"
}

read_wings_api_port() {
    local port="${WINGS_API_PORT}"
    if [[ -z "${port}" && -f "${WINGS_CONFIG}" ]]; then
        port="$(awk -F': ' '/^[[:space:]]*port:[[:space:]]*[0-9]+/ {print $2; exit}' "${WINGS_CONFIG}" 2>/dev/null || true)"
    fi
    if [[ ! "${port}" =~ ^[0-9]+$ ]]; then
        port="8080"
    fi
    printf '%s' "${port}"
}

read_wings_sftp_port() {
    local port="${WINGS_SFTP_PORT}"
    if [[ -z "${port}" && -f "${WINGS_CONFIG}" ]]; then
        port="$(awk '
            BEGIN{insftp=0}
            /^[[:space:]]*sftp:[[:space:]]*$/ {insftp=1; next}
            insftp && /^[^[:space:]]/ {insftp=0}
            insftp && /^[[:space:]]*bind_port:[[:space:]]*[0-9]+/ {
                sub(/^[[:space:]]*bind_port:[[:space:]]*/, "", $0)
                print $0
                exit
            }' "${WINGS_CONFIG}" 2>/dev/null || true)"
    fi
    if [[ ! "${port}" =~ ^[0-9]+$ ]]; then
        port="2022"
    fi
    printf '%s' "${port}"
}

read_wings_docker_network_name() {
    local name="${WINGS_DOCKER_NETWORK_NAME}"
    if [[ -z "${name}" && -f "${WINGS_CONFIG}" ]]; then
        name="$(awk '
            BEGIN{indocker=0;innet=0}
            /^[[:space:]]*docker:[[:space:]]*$/ {indocker=1; innet=0; next}
            indocker && /^[^[:space:]]/ {indocker=0; innet=0}
            indocker && /^[[:space:]]{2}network:[[:space:]]*$/ {innet=1; next}
            innet && /^[[:space:]]{4}name:[[:space:]]*/ {
                sub(/^[[:space:]]{4}name:[[:space:]]*/, "", $0)
                gsub(/["'\'']/, "", $0)
                print $0
                exit
            }' "${WINGS_CONFIG}" 2>/dev/null || true)"
    fi
    if [[ -z "${name}" ]]; then
        name="pterodactyl_nw"
    fi
    printf '%s' "${name}"
}

docker_network_subnets_v4() {
    local name="$1"
    command -v docker >/dev/null 2>&1 || return 0
    docker network inspect "${name}" --format '{{range .IPAM.Config}}{{if .Subnet}}{{println .Subnet}}{{end}}{{end}}' 2>/dev/null \
        | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/[0-9]+$' | sort -u
}

build_wings_guard_ports() {
    local api_port="$1"
    local sftp_port="$2"
    local ports=""
    if [[ "${api_port}" =~ ^[0-9]+$ ]]; then
        ports="${api_port}"
    fi
    if [[ "${sftp_port}" =~ ^[0-9]+$ ]]; then
        if [[ -z "${ports}" ]]; then
            ports="${sftp_port}"
        elif [[ ",${ports}," != *",${sftp_port},"* ]]; then
            ports="${ports},${sftp_port}"
        fi
    fi
    printf '%s' "${ports}"
}

read_network_int_from_config() {
    local key="$1"
    local fallback="$2"
    local value="${fallback}"
    if [[ -f "${CONFIG_PATH}" ]] && have_cmd python3; then
        value="$(python3 - <<'PY' "${CONFIG_PATH}" "${key}" "${fallback}" 2>/dev/null || true
import json,sys
path,key,fallback=sys.argv[1],sys.argv[2],sys.argv[3]
try:
    with open(path,'r',encoding='utf-8') as f:
        d=json.load(f)
    v=(d.get('network') or {}).get(key, fallback)
    print(v)
except Exception:
    print(fallback)
PY
)"
    fi
    [[ "${value}" =~ ^[0-9]+$ ]] || value="${fallback}"
    printf '%s' "${value}"
}

read_network_string_from_config() {
    local key="$1"
    local fallback="$2"
    local value="${fallback}"
    if [[ -f "${CONFIG_PATH}" ]] && have_cmd python3; then
        value="$(python3 - <<'PY' "${CONFIG_PATH}" "${key}" "${fallback}" 2>/dev/null || true
import json,sys
path,key,fallback=sys.argv[1],sys.argv[2],sys.argv[3]
try:
    with open(path,'r',encoding='utf-8') as f:
        d=json.load(f)
    v=(d.get('network') or {}).get(key, fallback)
    if isinstance(v, list):
        print(",".join(str(x) for x in v))
    else:
        print(v)
except Exception:
    print(fallback)
PY
)"
    fi
    printf '%s' "${value}"
}

effective_burst_kb() {
    local rate_kbps="$1"
    local burst_kb="$2"
    local min_burst_kb
    if [[ ! "${rate_kbps}" =~ ^[0-9]+$ ]]; then
        rate_kbps=1
    fi
    if [[ ! "${burst_kb}" =~ ^[0-9]+$ ]]; then
        burst_kb=1
    fi
    min_burst_kb=$(( rate_kbps * 2 ))
    if (( min_burst_kb < 1 )); then
        min_burst_kb=1
    fi
    if (( burst_kb < min_burst_kb )); then
        burst_kb="${min_burst_kb}"
    fi
    printf '%s' "${burst_kb}"
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

resolve_host_v4() {
    local host="$1"
    getent ahostsv4 "${host}" 2>/dev/null | awk '{print $1}' | sort -u
}

resolve_host_v6() {
    local host="$1"
    getent ahostsv6 "${host}" 2>/dev/null | awk '{print $1}' | sort -u
}

resolve_dns_nameserver_v4() {
    awk '/^nameserver[[:space:]]+/ {print $2}' /etc/resolv.conf 2>/dev/null | sed 's/%.*//' | grep -E '^[0-9.]+$' | sort -u
}

resolve_dns_nameserver_v6() {
    awk '/^nameserver[[:space:]]+/ {print $2}' /etc/resolv.conf 2>/dev/null | sed 's/%.*//' | grep -E ':' | sort -u
}

resolve_active_ssh_client_v4() {
    ss -tn state established 2>/dev/null | awk '
        NR>1 {
            local_addr=$4
            remote=$5
            if (local_addr ~ /:(22|2022)$/) {
                sub(/:[0-9]+$/, "", remote)
                if (remote ~ /^[0-9.]+$/) print remote
            }
        }' | sort -u
}

resolve_active_ssh_client_v6() {
    ss -tn state established 2>/dev/null | awk '
        NR>1 {
            local_addr=$4
            remote=$5
            if (local_addr ~ /:(22|2022)$/ && remote ~ /^\[/) {
                sub(/^\[/, "", remote)
                sub(/\]:[0-9]+$/, "", remote)
                if (remote ~ /:/) print remote
            }
        }' | sort -u
}

discover_infra_hosts() {
    local hosts=""
    local remote_host=""
    local value=""

    if [[ -f "${WINGS_CONFIG}" ]]; then
        value="$(awk -F': ' '/^remote:/ {print $2; exit}' "${WINGS_CONFIG}" 2>/dev/null || true)"
        remote_host="$(extract_host_from_value "${value}")"
        hosts="$(add_unique_word "${remote_host}" "${hosts}")"
    fi

    for value in ${INFRA_HOSTS_RAW//,/ }; do
        hosts="$(add_unique_word "$(extract_host_from_value "${value}")" "${hosts}")"
    done

    for value in ${hosts}; do
        [[ "${value}" =~ ^[A-Za-z0-9._:-]+$ ]] || continue
        printf '%s\n' "${value}"
    done
}

add_host_v4_whitelist() {
    local chain="$1"
    local host_ip
    for host_ip in ${HOST_IPS}; do
        if [[ -n "${host_ip}" && "${host_ip}" != *:* ]]; then
            iptables -A "${chain}" -s "${host_ip}/32" -j RETURN
        fi
    done
}

add_resolved_v4_whitelist() {
    local chain="$1"
    local host="$2"
    local ip
    while read -r ip; do
        [[ -z "${ip}" ]] && continue
        iptables -A "${chain}" -s "${ip}/32" -j RETURN
    done < <(resolve_host_v4 "${host}")
}

add_resolved_v6_whitelist() {
    local chain="$1"
    local host="$2"
    local ip
    while read -r ip; do
        [[ -z "${ip}" ]] && continue
        ip6tables -A "${chain}" -s "${ip}/128" -j RETURN
    done < <(resolve_host_v6 "${host}")
}

add_dns_v4_whitelist() {
    local chain="$1"
    local ip
    while read -r ip; do
        [[ -z "${ip}" ]] && continue
        iptables -A "${chain}" -s "${ip}/32" -j RETURN
    done < <(resolve_dns_nameserver_v4)
}

add_dns_v6_whitelist() {
    local chain="$1"
    local ip
    while read -r ip; do
        [[ -z "${ip}" ]] && continue
        ip6tables -A "${chain}" -s "${ip}/128" -j RETURN
    done < <(resolve_dns_nameserver_v6)
}

add_ssh_v4_whitelist() {
    local chain="$1"
    local ip
    while read -r ip; do
        [[ -z "${ip}" ]] && continue
        iptables -A "${chain}" -s "${ip}/32" -j RETURN
    done < <(resolve_active_ssh_client_v4)
}

add_ssh_v6_whitelist() {
    local chain="$1"
    local ip
    while read -r ip; do
        [[ -z "${ip}" ]] && continue
        ip6tables -A "${chain}" -s "${ip}/128" -j RETURN
    done < <(resolve_active_ssh_client_v6)
}

add_host_v6_whitelist() {
    local chain="$1"
    local host_ip
    for host_ip in ${HOST_IPS}; do
        if [[ -n "${host_ip}" && "${host_ip}" == *:* ]]; then
            ip6tables -A "${chain}" -s "${host_ip}/128" -j RETURN
        fi
    done
}

prune_input_jump_rules_v4() {
    local ports="${PROTECTED_TCP_PORTS:-${PUBLIC_TCP_PORTS}}"
    while iptables -C INPUT -p tcp -m multiport --dports 80,443,8080,2022 -j "${CHAIN}" >/dev/null 2>&1; do
        iptables -D INPUT -p tcp -m multiport --dports 80,443,8080,2022 -j "${CHAIN}" >/dev/null 2>&1 || break
    done
    while iptables -C INPUT -p tcp -m multiport --dports "${ports}" -j "${CHAIN}" >/dev/null 2>&1; do
        iptables -D INPUT -p tcp -m multiport --dports "${ports}" -j "${CHAIN}" >/dev/null 2>&1 || break
    done
    while iptables -C INPUT -p udp -j "${CHAIN}" >/dev/null 2>&1; do
        iptables -D INPUT -p udp -j "${CHAIN}" >/dev/null 2>&1 || break
    done
}

prune_wings_guard_jump_rules_v4() {
    local ports="$1"
    [[ -n "${ports}" ]] || return 0
    while iptables -C INPUT -p tcp -m multiport --dports "${ports}" -j "${WINGS_GUARD_CHAIN4}" >/dev/null 2>&1; do
        iptables -D INPUT -p tcp -m multiport --dports "${ports}" -j "${WINGS_GUARD_CHAIN4}" >/dev/null 2>&1 || break
    done
}

prune_infra_guard_jump_rules_v4() {
    local ports="$1"
    [[ -n "${ports}" ]] || return 0
    while iptables -C INPUT -p tcp -m multiport --dports "${ports}" -j "${INFRA_GUARD_CHAIN4}" >/dev/null 2>&1; do
        iptables -D INPUT -p tcp -m multiport --dports "${ports}" -j "${INFRA_GUARD_CHAIN4}" >/dev/null 2>&1 || break
    done
}

prune_bw_jump_rules_v4() {
    while iptables -C INPUT -p tcp -m multiport --dports "${PUBLIC_TCP_PORTS}" -j "${BW_CHAIN}" >/dev/null 2>&1; do
        iptables -D INPUT -p tcp -m multiport --dports "${PUBLIC_TCP_PORTS}" -j "${BW_CHAIN}" >/dev/null 2>&1 || break
    done
}

prune_synproxy_jump_rules_v4() {
    local ports="${PROTECTED_TCP_PORTS:-${PUBLIC_TCP_PORTS}}"
    while iptables -C INPUT -p tcp -m multiport --dports "${ports}" -m tcp --syn -j "${SYNPROXY_CHAIN}" >/dev/null 2>&1; do
        iptables -D INPUT -p tcp -m multiport --dports "${ports}" -m tcp --syn -j "${SYNPROXY_CHAIN}" >/dev/null 2>&1 || break
    done
    while iptables -t raw -C PREROUTING -p tcp -m multiport --dports "${ports}" -j "${RAW_CHAIN}" >/dev/null 2>&1; do
        iptables -t raw -D PREROUTING -p tcp -m multiport --dports "${ports}" -j "${RAW_CHAIN}" >/dev/null 2>&1 || break
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

ensure_local_wings_access_rules_v4() {
    local port="$1"
    local host_ip
    while iptables -C INPUT -p tcp --dport "${port}" -s 127.0.0.1/32 -j ACCEPT >/dev/null 2>&1; do
        iptables -D INPUT -p tcp --dport "${port}" -s 127.0.0.1/32 -j ACCEPT >/dev/null 2>&1 || break
    done
    iptables -I INPUT 1 -p tcp --dport "${port}" -s 127.0.0.1/32 -j ACCEPT

    for host_ip in ${HOST_IPS}; do
        [[ -n "${host_ip}" && "${host_ip}" != *:* ]] || continue
        while iptables -C INPUT -p tcp --dport "${port}" -s "${host_ip}/32" -j ACCEPT >/dev/null 2>&1; do
            iptables -D INPUT -p tcp --dport "${port}" -s "${host_ip}/32" -j ACCEPT >/dev/null 2>&1 || break
        done
        iptables -I INPUT 1 -p tcp --dport "${port}" -s "${host_ip}/32" -j ACCEPT
    done
}

ensure_loopback_web_access_rules_v4() {
    while iptables -C INPUT -i lo -j ACCEPT >/dev/null 2>&1; do
        iptables -D INPUT -i lo -j ACCEPT >/dev/null 2>&1 || break
    done
    iptables -I INPUT 1 -i lo -j ACCEPT

    while iptables -C INPUT -s 127.0.0.1/32 -p tcp -m multiport --dports 80,443 -j ACCEPT >/dev/null 2>&1; do
        iptables -D INPUT -s 127.0.0.1/32 -p tcp -m multiport --dports 80,443 -j ACCEPT >/dev/null 2>&1 || break
    done
    iptables -I INPUT 2 -s 127.0.0.1/32 -p tcp -m multiport --dports 80,443 -j ACCEPT
}

ensure_local_wings_access_rules_v6() {
    local port="$1"
    local host_ip
    have_cmd ip6tables || return 0
    while ip6tables -C INPUT -p tcp --dport "${port}" -s ::1/128 -j ACCEPT >/dev/null 2>&1; do
        ip6tables -D INPUT -p tcp --dport "${port}" -s ::1/128 -j ACCEPT >/dev/null 2>&1 || break
    done
    ip6tables -I INPUT 1 -p tcp --dport "${port}" -s ::1/128 -j ACCEPT

    for host_ip in ${HOST_IPS}; do
        [[ -n "${host_ip}" && "${host_ip}" == *:* ]] || continue
        while ip6tables -C INPUT -p tcp --dport "${port}" -s "${host_ip}/128" -j ACCEPT >/dev/null 2>&1; do
            ip6tables -D INPUT -p tcp --dport "${port}" -s "${host_ip}/128" -j ACCEPT >/dev/null 2>&1 || break
        done
        ip6tables -I INPUT 1 -p tcp --dport "${port}" -s "${host_ip}/128" -j ACCEPT
    done
}

ensure_loopback_web_access_rules_v6() {
    have_cmd ip6tables || return 0
    while ip6tables -C INPUT -i lo -j ACCEPT >/dev/null 2>&1; do
        ip6tables -D INPUT -i lo -j ACCEPT >/dev/null 2>&1 || break
    done
    ip6tables -I INPUT 1 -i lo -j ACCEPT

    while ip6tables -C INPUT -s ::1/128 -p tcp -m multiport --dports 80,443 -j ACCEPT >/dev/null 2>&1; do
        ip6tables -D INPUT -s ::1/128 -p tcp -m multiport --dports 80,443 -j ACCEPT >/dev/null 2>&1 || break
    done
    ip6tables -I INPUT 2 -s ::1/128 -p tcp -m multiport --dports 80,443 -j ACCEPT
}

prune_input_jump_rules_v6() {
    local ports="${PROTECTED_TCP_PORTS:-${PUBLIC_TCP_PORTS}}"
    while ip6tables -C INPUT -p tcp -m multiport --dports 80,443,8080,2022 -j "${CHAIN6}" >/dev/null 2>&1; do
        ip6tables -D INPUT -p tcp -m multiport --dports 80,443,8080,2022 -j "${CHAIN6}" >/dev/null 2>&1 || break
    done
    while ip6tables -C INPUT -p tcp -m multiport --dports "${ports}" -j "${CHAIN6}" >/dev/null 2>&1; do
        ip6tables -D INPUT -p tcp -m multiport --dports "${ports}" -j "${CHAIN6}" >/dev/null 2>&1 || break
    done
    while ip6tables -C INPUT -p udp -j "${CHAIN6}" >/dev/null 2>&1; do
        ip6tables -D INPUT -p udp -j "${CHAIN6}" >/dev/null 2>&1 || break
    done
}

prune_wings_guard_jump_rules_v6() {
    local ports="$1"
    [[ -n "${ports}" ]] || return 0
    while ip6tables -C INPUT -p tcp -m multiport --dports "${ports}" -j "${WINGS_GUARD_CHAIN6}" >/dev/null 2>&1; do
        ip6tables -D INPUT -p tcp -m multiport --dports "${ports}" -j "${WINGS_GUARD_CHAIN6}" >/dev/null 2>&1 || break
    done
}

prune_infra_guard_jump_rules_v6() {
    local ports="$1"
    [[ -n "${ports}" ]] || return 0
    while ip6tables -C INPUT -p tcp -m multiport --dports "${ports}" -j "${INFRA_GUARD_CHAIN6}" >/dev/null 2>&1; do
        ip6tables -D INPUT -p tcp -m multiport --dports "${ports}" -j "${INFRA_GUARD_CHAIN6}" >/dev/null 2>&1 || break
    done
}

prune_bw_jump_rules_v6() {
    while ip6tables -C INPUT -p tcp -m multiport --dports "${PUBLIC_TCP_PORTS}" -j "${BW_CHAIN6}" >/dev/null 2>&1; do
        ip6tables -D INPUT -p tcp -m multiport --dports "${PUBLIC_TCP_PORTS}" -j "${BW_CHAIN6}" >/dev/null 2>&1 || break
    done
}

prune_synproxy_jump_rules_v6() {
    local ports="${PROTECTED_TCP_PORTS:-${PUBLIC_TCP_PORTS}}"
    while ip6tables -C INPUT -p tcp -m multiport --dports "${ports}" -m tcp --syn -j "${SYNPROXY_CHAIN6}" >/dev/null 2>&1; do
        ip6tables -D INPUT -p tcp -m multiport --dports "${ports}" -m tcp --syn -j "${SYNPROXY_CHAIN6}" >/dev/null 2>&1 || break
    done
    while ip6tables -t raw -C PREROUTING -p tcp -m multiport --dports "${ports}" -j "${RAW_CHAIN6}" >/dev/null 2>&1; do
        ip6tables -t raw -D PREROUTING -p tcp -m multiport --dports "${ports}" -j "${RAW_CHAIN6}" >/dev/null 2>&1 || break
    done
}

supports_synproxy_v4() {
    iptables -j SYNPROXY -h >/dev/null 2>&1
}

supports_synproxy_v6() {
    ip6tables -j SYNPROXY -h >/dev/null 2>&1
}

cleanup_host_protection() {
    prune_input_jump_rules_v4
    prune_wings_guard_jump_rules_v4 "8080,2022"
    prune_infra_guard_jump_rules_v4 "22,2022,8080,3306,5432,6379"
    prune_bw_jump_rules_v4
    prune_synproxy_jump_rules_v4
    while iptables -C INPUT -p icmp --icmp-type echo-request -j DROP >/dev/null 2>&1; do
        iptables -D INPUT -p icmp --icmp-type echo-request -j DROP >/dev/null 2>&1 || break
    done
    while iptables -C DOCKER-USER -j "${DOCKER_CHAIN}" >/dev/null 2>&1; do
        iptables -D DOCKER-USER -j "${DOCKER_CHAIN}" >/dev/null 2>&1 || break
    done
    iptables -F "${DOCKER_CHAIN}" >/dev/null 2>&1 || true
    iptables -X "${DOCKER_CHAIN}" >/dev/null 2>&1 || true
    iptables -F "${WINGS_GUARD_CHAIN4}" >/dev/null 2>&1 || true
    iptables -X "${WINGS_GUARD_CHAIN4}" >/dev/null 2>&1 || true
    iptables -F "${INFRA_GUARD_CHAIN4}" >/dev/null 2>&1 || true
    iptables -X "${INFRA_GUARD_CHAIN4}" >/dev/null 2>&1 || true
    iptables -F "${ABUSE_CHAIN}" >/dev/null 2>&1 || true
    iptables -X "${ABUSE_CHAIN}" >/dev/null 2>&1 || true
    iptables -F "${BW_CHAIN}" >/dev/null 2>&1 || true
    iptables -X "${BW_CHAIN}" >/dev/null 2>&1 || true
    iptables -F "${CHAIN}" >/dev/null 2>&1 || true
    iptables -X "${CHAIN}" >/dev/null 2>&1 || true
    iptables -F "${SYNPROXY_CHAIN}" >/dev/null 2>&1 || true
    iptables -X "${SYNPROXY_CHAIN}" >/dev/null 2>&1 || true
    iptables -t raw -F "${RAW_CHAIN}" >/dev/null 2>&1 || true
    iptables -t raw -X "${RAW_CHAIN}" >/dev/null 2>&1 || true
    if have_cmd ipset; then
        ipset destroy "${IPSET4}" >/dev/null 2>&1 || true
        ipset destroy "${BW_IPSET4_PROBATION}" >/dev/null 2>&1 || true
        ipset destroy "${BW_IPSET4_BAD}" >/dev/null 2>&1 || true
        ipset destroy "${BW_IPSET4_WORST}" >/dev/null 2>&1 || true
        ipset destroy "${BW_IPSET4_TRUSTED}" >/dev/null 2>&1 || true
        ipset destroy "${BW_IPSET4_VTRUSTED}" >/dev/null 2>&1 || true
    fi

    if have_cmd ip6tables; then
        prune_input_jump_rules_v6
        prune_wings_guard_jump_rules_v6 "8080,2022"
        prune_infra_guard_jump_rules_v6 "22,2022,8080,3306,5432,6379"
        prune_bw_jump_rules_v6
        prune_synproxy_jump_rules_v6
        while ip6tables -C INPUT -p ipv6-icmp --icmpv6-type echo-request -j DROP >/dev/null 2>&1; do
            ip6tables -D INPUT -p ipv6-icmp --icmpv6-type echo-request -j DROP >/dev/null 2>&1 || break
        done
        ip6tables -F "${ABUSE_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -X "${ABUSE_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -F "${WINGS_GUARD_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -X "${WINGS_GUARD_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -F "${INFRA_GUARD_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -X "${INFRA_GUARD_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -F "${BW_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -X "${BW_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -F "${CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -X "${CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -F "${SYNPROXY_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -X "${SYNPROXY_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -t raw -F "${RAW_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -t raw -X "${RAW_CHAIN6}" >/dev/null 2>&1 || true
    fi
    if have_cmd ipset; then
        ipset destroy "${IPSET6}" >/dev/null 2>&1 || true
        ipset destroy "${BW_IPSET6_PROBATION}" >/dev/null 2>&1 || true
        ipset destroy "${BW_IPSET6_BAD}" >/dev/null 2>&1 || true
        ipset destroy "${BW_IPSET6_WORST}" >/dev/null 2>&1 || true
        ipset destroy "${BW_IPSET6_TRUSTED}" >/dev/null 2>&1 || true
        ipset destroy "${BW_IPSET6_VTRUSTED}" >/dev/null 2>&1 || true
    fi
}

if [[ "${PTEROPROTECT_FIREWALL_DISABLE:-0}" == "1" ]]; then
    cleanup_host_protection
    exit 0
fi

iptables -N "${CHAIN}" >/dev/null 2>&1 || true
iptables -N "${ABUSE_CHAIN}" >/dev/null 2>&1 || true
iptables -N "${WINGS_GUARD_CHAIN4}" >/dev/null 2>&1 || true
iptables -N "${INFRA_GUARD_CHAIN4}" >/dev/null 2>&1 || true
iptables -N "${DOCKER_CHAIN}" >/dev/null 2>&1 || true
iptables -F "${CHAIN}" >/dev/null 2>&1 || true
iptables -F "${ABUSE_CHAIN}" >/dev/null 2>&1 || true
iptables -F "${WINGS_GUARD_CHAIN4}" >/dev/null 2>&1 || true
iptables -F "${INFRA_GUARD_CHAIN4}" >/dev/null 2>&1 || true
iptables -F "${DOCKER_CHAIN}" >/dev/null 2>&1 || true

while iptables -C INPUT -p icmp --icmp-type echo-request -j DROP >/dev/null 2>&1; do
    iptables -D INPUT -p icmp --icmp-type echo-request -j DROP >/dev/null 2>&1 || break
done
iptables -I INPUT -p icmp --icmp-type echo-request -j DROP

PUBLIC_TCP_PORTS="$(sanitize_ports "${PUBLIC_TCP_PORTS}")"
EGRESS_TCP_BLOCK_PORTS="$(sanitize_ports "${EGRESS_TCP_BLOCK_PORTS}")"
EGRESS_UDP_BLOCK_PORTS="$(sanitize_ports "${EGRESS_UDP_BLOCK_PORTS}")"
SSH_GUARD_PORTS="$(sanitize_ports "${SSH_GUARD_PORTS}")"
if [[ -z "${PTEROPROTECT_INFRA_GUARD_PORTS:-}" ]]; then
    INFRA_GUARD_PORTS="$(read_network_string_from_config "infra_guard_ports" "${INFRA_GUARD_PORTS}")"
fi
INFRA_GUARD_PORTS="$(sanitize_ports "${INFRA_GUARD_PORTS}")"
PROTECTED_TCP_PORTS="$(merge_ports "${PUBLIC_TCP_PORTS}" "${SSH_GUARD_PORTS}")"
PROTECTED_TCP_PORTS="$(merge_ports "${PROTECTED_TCP_PORTS}" "${INFRA_GUARD_PORTS}")"
UNBLOCK_PORTAL_PORT="$(read_unblock_portal_port)"
WINGS_API_PORT="$(read_wings_api_port)"
WINGS_SFTP_PORT="$(read_wings_sftp_port)"
WINGS_GUARD_PORTS="$(build_wings_guard_ports "${WINGS_API_PORT}" "${WINGS_SFTP_PORT}")"

# Pull host-abuse TCP limits from config.json by default so runtime matches panel settings.
if [[ -z "${PTEROPROTECT_NEW_CONN_RATE:-}" ]]; then
    NEW_CONN_RATE="$(read_network_int_from_config "host_new_conn_per_ip" "${NEW_CONN_RATE}")"
fi
if [[ -z "${PTEROPROTECT_NEW_CONN_BURST:-}" ]]; then
    NEW_CONN_BURST="$(read_network_int_from_config "host_new_conn_burst" "${NEW_CONN_BURST}")"
fi
if [[ -z "${PTEROPROTECT_CONNLIMIT_PER_IP:-}" ]]; then
    CONNLIMIT_PER_IP="$(read_network_int_from_config "host_connlimit_per_ip" "${CONNLIMIT_PER_IP}")"
fi

# Mobile/CGNAT friendly floors to avoid false timeout drops.
if (( NEW_CONN_RATE < 20 )); then NEW_CONN_RATE=20; fi
if (( NEW_CONN_BURST < 40 )); then NEW_CONN_BURST=40; fi
if (( CONNLIMIT_PER_IP < 80 )); then CONNLIMIT_PER_IP=80; fi
if [[ -z "${PTEROPROTECT_WINGS_GUARD_CONNLIMIT_PER_IP:-}" ]]; then
    WINGS_GUARD_CONNLIMIT_PER_IP="$(read_network_int_from_config "wings_guard_connlimit_per_ip" "${WINGS_GUARD_CONNLIMIT_PER_IP}")"
fi
if [[ -z "${PTEROPROTECT_WINGS_GUARD_NEW_CONN_RATE:-}" ]]; then
    WINGS_GUARD_NEW_CONN_RATE="$(read_network_int_from_config "wings_guard_new_conn_per_ip" "${WINGS_GUARD_NEW_CONN_RATE}")"
fi
if [[ -z "${PTEROPROTECT_WINGS_GUARD_NEW_CONN_BURST:-}" ]]; then
    WINGS_GUARD_NEW_CONN_BURST="$(read_network_int_from_config "wings_guard_new_conn_burst" "${WINGS_GUARD_NEW_CONN_BURST}")"
fi
if [[ -z "${PTEROPROTECT_SSH_CONNLIMIT_PER_IP:-}" ]]; then
    SSH_CONNLIMIT_PER_IP="$(read_network_int_from_config "ssh_conn_limit_per_ip" "${SSH_CONNLIMIT_PER_IP}")"
fi
if [[ -z "${PTEROPROTECT_SSH_NEW_PER_IP_PER_MIN:-}" ]]; then
    SSH_NEW_PER_IP_PER_MIN="$(read_network_int_from_config "ssh_new_per_ip_per_min" "${SSH_NEW_PER_IP_PER_MIN}")"
fi
if [[ -z "${PTEROPROTECT_SSH_NEW_PER_IP_BURST:-}" ]]; then
    SSH_NEW_PER_IP_BURST="$(read_network_int_from_config "ssh_new_per_ip_burst" "${SSH_NEW_PER_IP_BURST}")"
fi
if [[ -z "${PTEROPROTECT_SSH_GLOBAL_NEW_PER_SEC:-}" ]]; then
    SSH_GLOBAL_NEW_PER_SEC="$(read_network_int_from_config "ssh_global_new_per_sec" "${SSH_GLOBAL_NEW_PER_SEC}")"
fi
if [[ -z "${PTEROPROTECT_SSH_GLOBAL_NEW_BURST:-}" ]]; then
    SSH_GLOBAL_NEW_BURST="$(read_network_int_from_config "ssh_global_new_burst" "${SSH_GLOBAL_NEW_BURST}")"
fi
if [[ -z "${PTEROPROTECT_TCP_GLOBAL_NEW_PER_SEC:-}" ]]; then
    TCP_GLOBAL_NEW_PER_SEC="$(read_network_int_from_config "host_global_new_per_sec" "${TCP_GLOBAL_NEW_PER_SEC}")"
fi
if [[ -z "${PTEROPROTECT_TCP_GLOBAL_NEW_BURST:-}" ]]; then
    TCP_GLOBAL_NEW_BURST="$(read_network_int_from_config "host_global_new_burst" "${TCP_GLOBAL_NEW_BURST}")"
fi
if [[ -z "${PTEROPROTECT_INFRA_CONNLIMIT_PER_IP:-}" ]]; then
    INFRA_CONNLIMIT_PER_IP="$(read_network_int_from_config "infra_guard_connlimit_per_ip" "${INFRA_CONNLIMIT_PER_IP}")"
fi
if [[ -z "${PTEROPROTECT_INFRA_NEW_CONN_RATE:-}" ]]; then
    INFRA_NEW_CONN_RATE="$(read_network_int_from_config "infra_guard_new_conn_per_ip" "${INFRA_NEW_CONN_RATE}")"
fi
if [[ -z "${PTEROPROTECT_INFRA_NEW_CONN_BURST:-}" ]]; then
    INFRA_NEW_CONN_BURST="$(read_network_int_from_config "infra_guard_new_conn_burst" "${INFRA_NEW_CONN_BURST}")"
fi
if [[ -z "${PTEROPROTECT_INFRA_GLOBAL_NEW_PER_SEC:-}" ]]; then
    INFRA_GLOBAL_NEW_PER_SEC="$(read_network_int_from_config "infra_guard_global_new_per_sec" "${INFRA_GLOBAL_NEW_PER_SEC}")"
fi
if [[ -z "${PTEROPROTECT_INFRA_GLOBAL_NEW_BURST:-}" ]]; then
    INFRA_GLOBAL_NEW_BURST="$(read_network_int_from_config "infra_guard_global_new_burst" "${INFRA_GLOBAL_NEW_BURST}")"
fi
if (( WINGS_GUARD_CONNLIMIT_PER_IP < 8 )); then WINGS_GUARD_CONNLIMIT_PER_IP=8; fi
if (( WINGS_GUARD_CONNLIMIT_PER_IP > 256 )); then WINGS_GUARD_CONNLIMIT_PER_IP=256; fi
if (( WINGS_GUARD_NEW_CONN_RATE < 2 )); then WINGS_GUARD_NEW_CONN_RATE=2; fi
if (( WINGS_GUARD_NEW_CONN_RATE > 200 )); then WINGS_GUARD_NEW_CONN_RATE=200; fi
if (( WINGS_GUARD_NEW_CONN_BURST < 4 )); then WINGS_GUARD_NEW_CONN_BURST=4; fi
if (( WINGS_GUARD_NEW_CONN_BURST > 500 )); then WINGS_GUARD_NEW_CONN_BURST=500; fi
if (( SSH_CONNLIMIT_PER_IP < 4 )); then SSH_CONNLIMIT_PER_IP=4; fi
if (( SSH_CONNLIMIT_PER_IP > 128 )); then SSH_CONNLIMIT_PER_IP=128; fi
if (( SSH_NEW_PER_IP_PER_MIN < 6 )); then SSH_NEW_PER_IP_PER_MIN=6; fi
if (( SSH_NEW_PER_IP_PER_MIN > 600 )); then SSH_NEW_PER_IP_PER_MIN=600; fi
if (( SSH_NEW_PER_IP_BURST < 6 )); then SSH_NEW_PER_IP_BURST=6; fi
if (( SSH_NEW_PER_IP_BURST > 1200 )); then SSH_NEW_PER_IP_BURST=1200; fi
if (( SSH_GLOBAL_NEW_PER_SEC < 10 )); then SSH_GLOBAL_NEW_PER_SEC=10; fi
if (( SSH_GLOBAL_NEW_PER_SEC > 5000 )); then SSH_GLOBAL_NEW_PER_SEC=5000; fi
if (( SSH_GLOBAL_NEW_BURST < 20 )); then SSH_GLOBAL_NEW_BURST=20; fi
if (( SSH_GLOBAL_NEW_BURST > 20000 )); then SSH_GLOBAL_NEW_BURST=20000; fi
if (( TCP_GLOBAL_NEW_PER_SEC < 100 )); then TCP_GLOBAL_NEW_PER_SEC=100; fi
if (( TCP_GLOBAL_NEW_PER_SEC > 20000 )); then TCP_GLOBAL_NEW_PER_SEC=20000; fi
if (( TCP_GLOBAL_NEW_BURST < 200 )); then TCP_GLOBAL_NEW_BURST=200; fi
if (( TCP_GLOBAL_NEW_BURST > 40000 )); then TCP_GLOBAL_NEW_BURST=40000; fi
if (( INFRA_CONNLIMIT_PER_IP < 4 )); then INFRA_CONNLIMIT_PER_IP=4; fi
if (( INFRA_CONNLIMIT_PER_IP > 128 )); then INFRA_CONNLIMIT_PER_IP=128; fi
if (( INFRA_NEW_CONN_RATE < 2 )); then INFRA_NEW_CONN_RATE=2; fi
if (( INFRA_NEW_CONN_RATE > 200 )); then INFRA_NEW_CONN_RATE=200; fi
if (( INFRA_NEW_CONN_BURST < 4 )); then INFRA_NEW_CONN_BURST=4; fi
if (( INFRA_NEW_CONN_BURST > 600 )); then INFRA_NEW_CONN_BURST=600; fi
if (( INFRA_GLOBAL_NEW_PER_SEC < 10 )); then INFRA_GLOBAL_NEW_PER_SEC=10; fi
if (( INFRA_GLOBAL_NEW_PER_SEC > 5000 )); then INFRA_GLOBAL_NEW_PER_SEC=5000; fi
if (( INFRA_GLOBAL_NEW_BURST < 20 )); then INFRA_GLOBAL_NEW_BURST=20; fi
if (( INFRA_GLOBAL_NEW_BURST > 20000 )); then INFRA_GLOBAL_NEW_BURST=20000; fi

prune_input_jump_rules_v4
prune_wings_guard_jump_rules_v4 "${WINGS_GUARD_PORTS}"
prune_infra_guard_jump_rules_v4 "${INFRA_GUARD_PORTS}"
prune_bw_jump_rules_v4
prune_synproxy_jump_rules_v4
ensure_unblock_portal_accept_rule_v4 "${UNBLOCK_PORTAL_PORT}"
ensure_loopback_web_access_rules_v4
ensure_local_wings_access_rules_v4 "${WINGS_API_PORT}"
init_ipset_runtime

if [[ -n "${WINGS_GUARD_PORTS}" ]]; then
    iptables -C INPUT -p tcp -m multiport --dports "${WINGS_GUARD_PORTS}" -j "${WINGS_GUARD_CHAIN4}" >/dev/null 2>&1 || \
        iptables -I INPUT -p tcp -m multiport --dports "${WINGS_GUARD_PORTS}" -j "${WINGS_GUARD_CHAIN4}"
    iptables -A "${WINGS_GUARD_CHAIN4}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
    iptables -A "${WINGS_GUARD_CHAIN4}" -s 127.0.0.1/32 -j RETURN
    iptables -A "${WINGS_GUARD_CHAIN4}" -s 10.0.0.0/8 -j RETURN
    iptables -A "${WINGS_GUARD_CHAIN4}" -s 172.16.0.0/12 -j RETURN
    iptables -A "${WINGS_GUARD_CHAIN4}" -s 192.168.0.0/16 -j RETURN
    if have_cmd ipset; then
        iptables -A "${WINGS_GUARD_CHAIN4}" -m set --match-set "${IPSET4}" src -j DROP
        iptables -A "${WINGS_GUARD_CHAIN4}" -p tcp --syn -m connlimit --connlimit-above "${WINGS_GUARD_CONNLIMIT_PER_IP}" --connlimit-mask 32 -j SET --add-set "${IPSET4}" src
    fi
    iptables -A "${WINGS_GUARD_CHAIN4}" -p tcp --syn -m connlimit --connlimit-above "${WINGS_GUARD_CONNLIMIT_PER_IP}" --connlimit-mask 32 -j DROP
    if have_cmd ipset; then
        iptables -A "${WINGS_GUARD_CHAIN4}" -p tcp --syn -m hashlimit --hashlimit-name pteroprotect_wings_new_v4 \
            --hashlimit-above "${WINGS_GUARD_NEW_CONN_RATE}"/second --hashlimit-burst "${WINGS_GUARD_NEW_CONN_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j SET --add-set "${IPSET4}" src
    fi
    iptables -A "${WINGS_GUARD_CHAIN4}" -p tcp --syn -m hashlimit --hashlimit-name pteroprotect_wings_new_v4 \
        --hashlimit-above "${WINGS_GUARD_NEW_CONN_RATE}"/second --hashlimit-burst "${WINGS_GUARD_NEW_CONN_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
    iptables -A "${WINGS_GUARD_CHAIN4}" -j RETURN
fi

if [[ -n "${INFRA_GUARD_PORTS}" ]]; then
    iptables -C INPUT -p tcp -m multiport --dports "${INFRA_GUARD_PORTS}" -j "${INFRA_GUARD_CHAIN4}" >/dev/null 2>&1 || \
        iptables -I INPUT -p tcp -m multiport --dports "${INFRA_GUARD_PORTS}" -j "${INFRA_GUARD_CHAIN4}"
    iptables -A "${INFRA_GUARD_CHAIN4}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
    iptables -A "${INFRA_GUARD_CHAIN4}" -s 127.0.0.1/32 -j RETURN
    iptables -A "${INFRA_GUARD_CHAIN4}" -s 10.0.0.0/8 -j RETURN
    iptables -A "${INFRA_GUARD_CHAIN4}" -s 172.16.0.0/12 -j RETURN
    iptables -A "${INFRA_GUARD_CHAIN4}" -s 192.168.0.0/16 -j RETURN
    if have_cmd ipset; then
        iptables -A "${INFRA_GUARD_CHAIN4}" -m set --match-set "${IPSET4}" src -j DROP
        iptables -A "${INFRA_GUARD_CHAIN4}" -p tcp --syn -m connlimit --connlimit-above "${INFRA_CONNLIMIT_PER_IP}" --connlimit-mask 32 -j SET --add-set "${IPSET4}" src
    fi
    iptables -A "${INFRA_GUARD_CHAIN4}" -p tcp --syn -m connlimit --connlimit-above "${INFRA_CONNLIMIT_PER_IP}" --connlimit-mask 32 -j DROP
    if have_cmd ipset; then
        iptables -A "${INFRA_GUARD_CHAIN4}" -p tcp --syn -m hashlimit --hashlimit-name pteroprotect_infra_new_v4 \
            --hashlimit-above "${INFRA_NEW_CONN_RATE}"/second --hashlimit-burst "${INFRA_NEW_CONN_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j SET --add-set "${IPSET4}" src
    fi
    iptables -A "${INFRA_GUARD_CHAIN4}" -p tcp --syn -m hashlimit --hashlimit-name pteroprotect_infra_new_v4 \
        --hashlimit-above "${INFRA_NEW_CONN_RATE}"/second --hashlimit-burst "${INFRA_NEW_CONN_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
    iptables -A "${INFRA_GUARD_CHAIN4}" -p tcp -m conntrack --ctstate NEW -m hashlimit \
        --hashlimit-name pteroprotect_infra_new_global_v4 --hashlimit-above "${INFRA_GLOBAL_NEW_PER_SEC}"/second \
        --hashlimit-burst "${INFRA_GLOBAL_NEW_BURST}" --hashlimit-mode dstport -j DROP
    iptables -A "${INFRA_GUARD_CHAIN4}" -j RETURN
fi

if [[ "${IP_TRUST_BW_ENABLED}" == "1" ]] && have_cmd ipset; then
    BW_BURST_PROBATION_KB="$(effective_burst_kb "${IP_TRUST_BW_PROBATION_KBPS}" "${IP_TRUST_BW_BURST_KB}")"
    BW_BURST_BAD_KB="$(effective_burst_kb "${IP_TRUST_BW_BAD_KBPS}" "${IP_TRUST_BW_BURST_KB}")"
    BW_BURST_WORST_KB="$(effective_burst_kb "${IP_TRUST_BW_WORST_KBPS}" "${IP_TRUST_BW_BURST_KB}")"
    BW_BURST_TRUSTED_KB="$(effective_burst_kb "${IP_TRUST_BW_TRUSTED_KBPS}" "${IP_TRUST_BW_BURST_KB}")"
    BW_BURST_VTRUSTED_KB="$(effective_burst_kb "${IP_TRUST_BW_VTRUSTED_KBPS}" "${IP_TRUST_BW_BURST_KB}")"

    iptables -N "${BW_CHAIN}" >/dev/null 2>&1 || true
    iptables -F "${BW_CHAIN}" >/dev/null 2>&1 || true
    iptables -C INPUT -p tcp -m multiport --dports "${PUBLIC_TCP_PORTS}" -j "${BW_CHAIN}" >/dev/null 2>&1 || \
        iptables -I INPUT -p tcp -m multiport --dports "${PUBLIC_TCP_PORTS}" -j "${BW_CHAIN}"

    ipset create "${BW_IPSET4_PROBATION}" hash:ip family inet -exist >/dev/null 2>&1 || true
    ipset create "${BW_IPSET4_BAD}" hash:ip family inet -exist >/dev/null 2>&1 || true
    ipset create "${BW_IPSET4_WORST}" hash:ip family inet -exist >/dev/null 2>&1 || true
    ipset create "${BW_IPSET4_TRUSTED}" hash:ip family inet -exist >/dev/null 2>&1 || true
    ipset create "${BW_IPSET4_VTRUSTED}" hash:ip family inet -exist >/dev/null 2>&1 || true

    iptables -A "${BW_CHAIN}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
    iptables -A "${BW_CHAIN}" -s 127.0.0.1/32 -j RETURN
    iptables -A "${BW_CHAIN}" -s 10.0.0.0/8 -j RETURN
    iptables -A "${BW_CHAIN}" -s 172.16.0.0/12 -j RETURN
    iptables -A "${BW_CHAIN}" -s 192.168.0.0/16 -j RETURN

    iptables -A "${BW_CHAIN}" -m set --match-set "${BW_IPSET4_VTRUSTED}" src -m hashlimit --hashlimit-name pteroprotect_bw_vtrusted_v4 \
        --hashlimit-above "${IP_TRUST_BW_VTRUSTED_KBPS}kb/sec" --hashlimit-burst "${BW_BURST_VTRUSTED_KB}kb" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
    iptables -A "${BW_CHAIN}" -m set --match-set "${BW_IPSET4_VTRUSTED}" src -j RETURN
    iptables -A "${BW_CHAIN}" -m set --match-set "${BW_IPSET4_TRUSTED}" src -m hashlimit --hashlimit-name pteroprotect_bw_trusted_v4 \
        --hashlimit-above "${IP_TRUST_BW_TRUSTED_KBPS}kb/sec" --hashlimit-burst "${BW_BURST_TRUSTED_KB}kb" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
    iptables -A "${BW_CHAIN}" -m set --match-set "${BW_IPSET4_TRUSTED}" src -j RETURN
    iptables -A "${BW_CHAIN}" -m set --match-set "${BW_IPSET4_PROBATION}" src -m hashlimit --hashlimit-name pteroprotect_bw_probation_v4 \
        --hashlimit-above "${IP_TRUST_BW_PROBATION_KBPS}kb/sec" --hashlimit-burst "${BW_BURST_PROBATION_KB}kb" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
    iptables -A "${BW_CHAIN}" -m set --match-set "${BW_IPSET4_PROBATION}" src -j RETURN
    iptables -A "${BW_CHAIN}" -m set --match-set "${BW_IPSET4_BAD}" src -m hashlimit --hashlimit-name pteroprotect_bw_bad_v4 \
        --hashlimit-above "${IP_TRUST_BW_BAD_KBPS}kb/sec" --hashlimit-burst "${BW_BURST_BAD_KB}kb" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
    iptables -A "${BW_CHAIN}" -m set --match-set "${BW_IPSET4_BAD}" src -j RETURN
    iptables -A "${BW_CHAIN}" -m set --match-set "${BW_IPSET4_WORST}" src -m hashlimit --hashlimit-name pteroprotect_bw_worst_v4 \
        --hashlimit-above "${IP_TRUST_BW_WORST_KBPS}kb/sec" --hashlimit-burst "${BW_BURST_WORST_KB}kb" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
    iptables -A "${BW_CHAIN}" -m set --match-set "${BW_IPSET4_WORST}" src -j RETURN
    iptables -A "${BW_CHAIN}" -m hashlimit --hashlimit-name pteroprotect_bw_default_v4 \
        --hashlimit-above "${IP_TRUST_BW_PROBATION_KBPS}kb/sec" --hashlimit-burst "${BW_BURST_PROBATION_KB}kb" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
    iptables -A "${BW_CHAIN}" -j RETURN
fi

iptables -C INPUT -p tcp -m multiport --dports "${PROTECTED_TCP_PORTS}" -j "${CHAIN}" >/dev/null 2>&1 || \
    iptables -I INPUT -p tcp -m multiport --dports "${PROTECTED_TCP_PORTS}" -j "${CHAIN}"
if [[ "${UDP_GUARD_ENABLED}" == "1" ]]; then
    iptables -C INPUT -p udp -j "${CHAIN}" >/dev/null 2>&1 || \
        iptables -I INPUT -p udp -j "${CHAIN}"
fi

if [[ "${SYNPROXY_ENABLED}" == "1" ]] && supports_synproxy_v4; then
    iptables -N "${SYNPROXY_CHAIN}" >/dev/null 2>&1 || true
    iptables -F "${SYNPROXY_CHAIN}" >/dev/null 2>&1 || true
    iptables -t raw -N "${RAW_CHAIN}" >/dev/null 2>&1 || true
    iptables -t raw -F "${RAW_CHAIN}" >/dev/null 2>&1 || true

    iptables -t raw -C PREROUTING -p tcp -m multiport --dports "${PROTECTED_TCP_PORTS}" -j "${RAW_CHAIN}" >/dev/null 2>&1 || \
        iptables -t raw -I PREROUTING -p tcp -m multiport --dports "${PROTECTED_TCP_PORTS}" -j "${RAW_CHAIN}"

    iptables -C INPUT -p tcp -m multiport --dports "${PROTECTED_TCP_PORTS}" -m tcp --syn -j "${SYNPROXY_CHAIN}" >/dev/null 2>&1 || \
        iptables -I INPUT -p tcp -m multiport --dports "${PROTECTED_TCP_PORTS}" -m tcp --syn -j "${SYNPROXY_CHAIN}"

    SYNPROXY_READY4=0
    if iptables -A "${RAW_CHAIN}" -p tcp -m conntrack --ctstate NEW -j CT --notrack >/dev/null 2>&1; then
        SYNPROXY_READY4=1
    elif iptables -A "${RAW_CHAIN}" -p tcp -m conntrack --ctstate NEW -j NOTRACK >/dev/null 2>&1; then
        SYNPROXY_READY4=1
    fi

    if [[ "${SYNPROXY_READY4}" == "1" ]]; then
        iptables -A "${SYNPROXY_CHAIN}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
        iptables -A "${SYNPROXY_CHAIN}" -p tcp -m conntrack --ctstate INVALID,UNTRACKED -m tcp --syn -j SYNPROXY \
            --sack-perm --timestamp --wscale "${SYNPROXY_WSCALE}" --mss "${SYNPROXY_MSS}"
        iptables -A "${SYNPROXY_CHAIN}" -m conntrack --ctstate INVALID -j DROP
        iptables -A "${SYNPROXY_CHAIN}" -m conntrack --ctstate UNTRACKED -j DROP
        iptables -A "${SYNPROXY_CHAIN}" -j RETURN
    else
        # Fallback when SYNPROXY cannot be armed: enforce SSH SYN limits in dedicated pre-chain.
        iptables -A "${SYNPROXY_CHAIN}" -p tcp -m multiport --dports "${SSH_GUARD_PORTS}" --syn -m hashlimit \
            --hashlimit-name pteroprotect_ssh_syn_fallback_global_v4 --hashlimit-above "${SSH_GLOBAL_NEW_PER_SEC}"/second \
            --hashlimit-burst "${SSH_GLOBAL_NEW_BURST}" --hashlimit-mode dstport -j DROP
        iptables -A "${SYNPROXY_CHAIN}" -p tcp -m multiport --dports "${SSH_GUARD_PORTS}" --syn -m hashlimit \
            --hashlimit-name pteroprotect_ssh_syn_fallback_src_v4 --hashlimit-above "${SSH_NEW_PER_IP_PER_MIN}"/minute \
            --hashlimit-burst "${SSH_NEW_PER_IP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
        iptables -A "${SYNPROXY_CHAIN}" -j RETURN
    fi
fi

if have_cmd ipset; then
    ipset create "${IPSET4}" hash:ip family inet timeout "${BLACKHOLE_TTL}" -exist >/dev/null 2>&1 || true
fi

iptables -A "${CHAIN}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
iptables -A "${CHAIN}" -s 127.0.0.1/32 -j RETURN
iptables -A "${CHAIN}" -s 10.0.0.0/8 -j RETURN
iptables -A "${CHAIN}" -s 172.16.0.0/12 -j RETURN
iptables -A "${CHAIN}" -s 192.168.0.0/16 -j RETURN
add_host_v4_whitelist "${CHAIN}"
add_dns_v4_whitelist "${CHAIN}"
add_ssh_v4_whitelist "${CHAIN}"
while read -r infra_host; do
    [[ -z "${infra_host}" ]] && continue
    add_resolved_v4_whitelist "${CHAIN}" "${infra_host}"
done < <(discover_infra_hosts)
if have_cmd ipset; then
    iptables -A "${CHAIN}" -m set --match-set "${IPSET4}" src -j DROP
fi

iptables -A "${CHAIN}" -m conntrack --ctstate INVALID -j DROP
if [[ "${UDP_GUARD_ENABLED}" == "1" ]]; then
    iptables -A "${CHAIN}" -p udp -m hashlimit --hashlimit-name pteroprotect_udp_new \
        --hashlimit-above "${UDP_PER_IP_RATE}"/second --hashlimit-burst "${UDP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
fi
iptables -A "${CHAIN}" -p tcp ! --syn -m conntrack --ctstate NEW -j DROP
iptables -A "${CHAIN}" -p tcp -m conntrack --ctstate NEW -j "${ABUSE_CHAIN}"

# Repeated bursts from the same IP get dropped quickly.
if have_cmd ipset; then
    iptables -A "${ABUSE_CHAIN}" -m recent --name pteroprotect_burst --update --seconds "${RECENT_WINDOW}" --hitcount "${RECENT_HITCOUNT}" --rsource -j SET --add-set "${IPSET4}" src
fi
iptables -A "${ABUSE_CHAIN}" -m recent --name pteroprotect_burst --update --seconds "${RECENT_WINDOW}" --hitcount "${RECENT_HITCOUNT}" --rsource -j DROP

# Drop obviously malformed TCP flag combinations before they consume more work.
iptables -A "${ABUSE_CHAIN}" -p tcp --tcp-flags ALL NONE -j DROP
iptables -A "${ABUSE_CHAIN}" -p tcp --tcp-flags ALL ALL -j DROP

# Tight SSH-specific guards to absorb auth-port floods before generic TCP limits.
if have_cmd ipset; then
    iptables -A "${ABUSE_CHAIN}" -p tcp -m multiport --dports "${SSH_GUARD_PORTS}" --syn -m connlimit \
        --connlimit-above "${SSH_CONNLIMIT_PER_IP}" --connlimit-mask 32 -j SET --add-set "${IPSET4}" src
fi
iptables -A "${ABUSE_CHAIN}" -p tcp -m multiport --dports "${SSH_GUARD_PORTS}" --syn -m connlimit \
    --connlimit-above "${SSH_CONNLIMIT_PER_IP}" --connlimit-mask 32 -j DROP
if have_cmd ipset; then
    iptables -A "${ABUSE_CHAIN}" -p tcp -m multiport --dports "${SSH_GUARD_PORTS}" -m conntrack --ctstate NEW -m hashlimit \
        --hashlimit-name pteroprotect_ssh_new_src_v4 --hashlimit-above "${SSH_NEW_PER_IP_PER_MIN}"/minute \
        --hashlimit-burst "${SSH_NEW_PER_IP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j SET --add-set "${IPSET4}" src
fi
iptables -A "${ABUSE_CHAIN}" -p tcp -m multiport --dports "${SSH_GUARD_PORTS}" -m conntrack --ctstate NEW -m hashlimit \
    --hashlimit-name pteroprotect_ssh_new_src_v4 --hashlimit-above "${SSH_NEW_PER_IP_PER_MIN}"/minute \
    --hashlimit-burst "${SSH_NEW_PER_IP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
iptables -A "${ABUSE_CHAIN}" -p tcp -m multiport --dports "${SSH_GUARD_PORTS}" -m conntrack --ctstate NEW -m hashlimit \
    --hashlimit-name pteroprotect_ssh_new_global_v4 --hashlimit-above "${SSH_GLOBAL_NEW_PER_SEC}"/second \
    --hashlimit-burst "${SSH_GLOBAL_NEW_BURST}" --hashlimit-mode dstport -j DROP

# Cap concurrent TCP sessions per source.
if have_cmd ipset; then
    iptables -A "${ABUSE_CHAIN}" -p tcp --syn -m connlimit --connlimit-above "${CONNLIMIT_PER_IP}" --connlimit-mask 32 -j SET --add-set "${IPSET4}" src
fi
iptables -A "${ABUSE_CHAIN}" -p tcp --syn -m connlimit --connlimit-above "${CONNLIMIT_PER_IP}" --connlimit-mask 32 -j DROP

# Rate-limit fresh connections per source.
if have_cmd ipset; then
    iptables -A "${ABUSE_CHAIN}" -p tcp -m hashlimit --hashlimit-name pteroprotect_new \
        --hashlimit-above "${NEW_CONN_RATE}"/second --hashlimit-burst "${NEW_CONN_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j SET --add-set "${IPSET4}" src
fi
iptables -A "${ABUSE_CHAIN}" -p tcp -m hashlimit --hashlimit-name pteroprotect_new \
    --hashlimit-above "${NEW_CONN_RATE}"/second --hashlimit-burst "${NEW_CONN_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 32 -j DROP
iptables -A "${ABUSE_CHAIN}" -p tcp -m conntrack --ctstate NEW -m hashlimit \
    --hashlimit-name pteroprotect_new_global_v4 --hashlimit-above "${TCP_GLOBAL_NEW_PER_SEC}"/second \
    --hashlimit-burst "${TCP_GLOBAL_NEW_BURST}" --hashlimit-mode dstport -j DROP

iptables -A "${ABUSE_CHAIN}" -m recent --name pteroprotect_burst --set --rsource -j RETURN
iptables -A "${ABUSE_CHAIN}" -j RETURN

iptables -A "${CHAIN}" -j RETURN

if iptables -S DOCKER-USER >/dev/null 2>&1; then
    while iptables -C DOCKER-USER -j "${DOCKER_CHAIN}" >/dev/null 2>&1; do
        iptables -D DOCKER-USER -j "${DOCKER_CHAIN}" >/dev/null 2>&1 || break
    done
    iptables -I DOCKER-USER 1 -j "${DOCKER_CHAIN}"
    iptables -A "${DOCKER_CHAIN}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
    iptables -A "${DOCKER_CHAIN}" -d 169.254.169.254/32 -j DROP
    iptables -A "${DOCKER_CHAIN}" -d 169.254.170.2/32 -j DROP
    iptables -A "${DOCKER_CHAIN}" -d 100.100.100.200/32 -j DROP
    iptables -A "${DOCKER_CHAIN}" -d 169.254.0.0/16 -j DROP
    if [[ "${DOCKER_STRICT_ISOLATION_ENABLED}" == "1" ]]; then
        WINGS_DOCKER_NETWORK_NAME="$(read_wings_docker_network_name)"
        while read -r docker_subnet_v4; do
            [[ -z "${docker_subnet_v4}" ]] && continue
            iptables -A "${DOCKER_CHAIN}" -d "${docker_subnet_v4}" -j RETURN
        done < <(docker_network_subnets_v4 "${WINGS_DOCKER_NETWORK_NAME}")
        # Block container access to host/private ranges to reduce breakout blast radius.
        iptables -A "${DOCKER_CHAIN}" -d 127.0.0.0/8 -j DROP
        iptables -A "${DOCKER_CHAIN}" -d 10.0.0.0/8 -j DROP
        iptables -A "${DOCKER_CHAIN}" -d 172.16.0.0/12 -j DROP
        iptables -A "${DOCKER_CHAIN}" -d 192.168.0.0/16 -j DROP
    fi
    if [[ "${EGRESS_GUARD_ENABLED}" == "1" ]]; then
        if [[ -n "${EGRESS_TCP_BLOCK_PORTS}" ]]; then
            iptables -A "${DOCKER_CHAIN}" -p tcp -m multiport --dports "${EGRESS_TCP_BLOCK_PORTS}" -j DROP
        fi
        if [[ -n "${EGRESS_UDP_BLOCK_PORTS}" ]]; then
            iptables -A "${DOCKER_CHAIN}" -p udp -m multiport --dports "${EGRESS_UDP_BLOCK_PORTS}" -j DROP
        fi
    fi
    iptables -A "${DOCKER_CHAIN}" -j RETURN
fi

if [[ "${IPV6_ENABLED}" == "1" ]] && have_cmd ip6tables; then
    ensure_loopback_web_access_rules_v6
    ensure_local_wings_access_rules_v6 "${WINGS_API_PORT}"
    ip6tables -N "${CHAIN6}" >/dev/null 2>&1 || true
    ip6tables -N "${ABUSE_CHAIN6}" >/dev/null 2>&1 || true
    ip6tables -N "${WINGS_GUARD_CHAIN6}" >/dev/null 2>&1 || true
    ip6tables -N "${INFRA_GUARD_CHAIN6}" >/dev/null 2>&1 || true
    ip6tables -F "${CHAIN6}" >/dev/null 2>&1 || true
    ip6tables -F "${ABUSE_CHAIN6}" >/dev/null 2>&1 || true
    ip6tables -F "${WINGS_GUARD_CHAIN6}" >/dev/null 2>&1 || true
    ip6tables -F "${INFRA_GUARD_CHAIN6}" >/dev/null 2>&1 || true

    while ip6tables -C INPUT -p ipv6-icmp --icmpv6-type echo-request -j DROP >/dev/null 2>&1; do
        ip6tables -D INPUT -p ipv6-icmp --icmpv6-type echo-request -j DROP >/dev/null 2>&1 || break
    done
    ip6tables -I INPUT -p ipv6-icmp --icmpv6-type echo-request -j DROP

    prune_input_jump_rules_v6
    prune_wings_guard_jump_rules_v6 "${WINGS_GUARD_PORTS}"
    prune_infra_guard_jump_rules_v6 "${INFRA_GUARD_PORTS}"
    prune_bw_jump_rules_v6
    prune_synproxy_jump_rules_v6

    if [[ -n "${WINGS_GUARD_PORTS}" ]]; then
        ip6tables -C INPUT -p tcp -m multiport --dports "${WINGS_GUARD_PORTS}" -j "${WINGS_GUARD_CHAIN6}" >/dev/null 2>&1 || \
            ip6tables -I INPUT -p tcp -m multiport --dports "${WINGS_GUARD_PORTS}" -j "${WINGS_GUARD_CHAIN6}"
        ip6tables -A "${WINGS_GUARD_CHAIN6}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
        ip6tables -A "${WINGS_GUARD_CHAIN6}" -s ::1/128 -j RETURN
        ip6tables -A "${WINGS_GUARD_CHAIN6}" -s fe80::/10 -j RETURN
        ip6tables -A "${WINGS_GUARD_CHAIN6}" -s fc00::/7 -j RETURN
        if have_cmd ipset; then
            ip6tables -A "${WINGS_GUARD_CHAIN6}" -m set --match-set "${IPSET6}" src -j DROP
            ip6tables -A "${WINGS_GUARD_CHAIN6}" -p tcp --syn -m connlimit --connlimit-above "${WINGS_GUARD_CONNLIMIT_PER_IP}" --connlimit-mask 128 -j SET --add-set "${IPSET6}" src
        fi
        ip6tables -A "${WINGS_GUARD_CHAIN6}" -p tcp --syn -m connlimit --connlimit-above "${WINGS_GUARD_CONNLIMIT_PER_IP}" --connlimit-mask 128 -j DROP
        if have_cmd ipset; then
            ip6tables -A "${WINGS_GUARD_CHAIN6}" -p tcp --syn -m hashlimit --hashlimit-name pteroprotect_wings_new_v6 \
                --hashlimit-above "${WINGS_GUARD_NEW_CONN_RATE}"/second --hashlimit-burst "${WINGS_GUARD_NEW_CONN_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j SET --add-set "${IPSET6}" src
        fi
        ip6tables -A "${WINGS_GUARD_CHAIN6}" -p tcp --syn -m hashlimit --hashlimit-name pteroprotect_wings_new_v6 \
            --hashlimit-above "${WINGS_GUARD_NEW_CONN_RATE}"/second --hashlimit-burst "${WINGS_GUARD_NEW_CONN_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
        ip6tables -A "${WINGS_GUARD_CHAIN6}" -j RETURN
    fi

    if [[ -n "${INFRA_GUARD_PORTS}" ]]; then
        ip6tables -C INPUT -p tcp -m multiport --dports "${INFRA_GUARD_PORTS}" -j "${INFRA_GUARD_CHAIN6}" >/dev/null 2>&1 || \
            ip6tables -I INPUT -p tcp -m multiport --dports "${INFRA_GUARD_PORTS}" -j "${INFRA_GUARD_CHAIN6}"
        ip6tables -A "${INFRA_GUARD_CHAIN6}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
        ip6tables -A "${INFRA_GUARD_CHAIN6}" -s ::1/128 -j RETURN
        ip6tables -A "${INFRA_GUARD_CHAIN6}" -s fe80::/10 -j RETURN
        ip6tables -A "${INFRA_GUARD_CHAIN6}" -s fc00::/7 -j RETURN
        if have_cmd ipset; then
            ip6tables -A "${INFRA_GUARD_CHAIN6}" -m set --match-set "${IPSET6}" src -j DROP
            ip6tables -A "${INFRA_GUARD_CHAIN6}" -p tcp --syn -m connlimit --connlimit-above "${INFRA_CONNLIMIT_PER_IP}" --connlimit-mask 128 -j SET --add-set "${IPSET6}" src
        fi
        ip6tables -A "${INFRA_GUARD_CHAIN6}" -p tcp --syn -m connlimit --connlimit-above "${INFRA_CONNLIMIT_PER_IP}" --connlimit-mask 128 -j DROP
        if have_cmd ipset; then
            ip6tables -A "${INFRA_GUARD_CHAIN6}" -p tcp --syn -m hashlimit --hashlimit-name pteroprotect_infra_new_v6 \
                --hashlimit-above "${INFRA_NEW_CONN_RATE}"/second --hashlimit-burst "${INFRA_NEW_CONN_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j SET --add-set "${IPSET6}" src
        fi
        ip6tables -A "${INFRA_GUARD_CHAIN6}" -p tcp --syn -m hashlimit --hashlimit-name pteroprotect_infra_new_v6 \
            --hashlimit-above "${INFRA_NEW_CONN_RATE}"/second --hashlimit-burst "${INFRA_NEW_CONN_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
        ip6tables -A "${INFRA_GUARD_CHAIN6}" -p tcp -m conntrack --ctstate NEW -m hashlimit \
            --hashlimit-name pteroprotect_infra_new_global_v6 --hashlimit-above "${INFRA_GLOBAL_NEW_PER_SEC}"/second \
            --hashlimit-burst "${INFRA_GLOBAL_NEW_BURST}" --hashlimit-mode dstport -j DROP
        ip6tables -A "${INFRA_GUARD_CHAIN6}" -j RETURN
    fi

    if [[ "${IP_TRUST_BW_ENABLED}" == "1" ]] && have_cmd ipset; then
        BW_BURST_PROBATION_KB="$(effective_burst_kb "${IP_TRUST_BW_PROBATION_KBPS}" "${IP_TRUST_BW_BURST_KB}")"
        BW_BURST_BAD_KB="$(effective_burst_kb "${IP_TRUST_BW_BAD_KBPS}" "${IP_TRUST_BW_BURST_KB}")"
        BW_BURST_WORST_KB="$(effective_burst_kb "${IP_TRUST_BW_WORST_KBPS}" "${IP_TRUST_BW_BURST_KB}")"
        BW_BURST_TRUSTED_KB="$(effective_burst_kb "${IP_TRUST_BW_TRUSTED_KBPS}" "${IP_TRUST_BW_BURST_KB}")"
        BW_BURST_VTRUSTED_KB="$(effective_burst_kb "${IP_TRUST_BW_VTRUSTED_KBPS}" "${IP_TRUST_BW_BURST_KB}")"

        ip6tables -N "${BW_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -F "${BW_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -C INPUT -p tcp -m multiport --dports "${PUBLIC_TCP_PORTS}" -j "${BW_CHAIN6}" >/dev/null 2>&1 || \
            ip6tables -I INPUT -p tcp -m multiport --dports "${PUBLIC_TCP_PORTS}" -j "${BW_CHAIN6}"

        ipset create "${BW_IPSET6_PROBATION}" hash:ip family inet6 -exist >/dev/null 2>&1 || true
        ipset create "${BW_IPSET6_BAD}" hash:ip family inet6 -exist >/dev/null 2>&1 || true
        ipset create "${BW_IPSET6_WORST}" hash:ip family inet6 -exist >/dev/null 2>&1 || true
        ipset create "${BW_IPSET6_TRUSTED}" hash:ip family inet6 -exist >/dev/null 2>&1 || true
        ipset create "${BW_IPSET6_VTRUSTED}" hash:ip family inet6 -exist >/dev/null 2>&1 || true

        ip6tables -A "${BW_CHAIN6}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
        ip6tables -A "${BW_CHAIN6}" -s ::1/128 -j RETURN
        ip6tables -A "${BW_CHAIN6}" -s fe80::/10 -j RETURN
        ip6tables -A "${BW_CHAIN6}" -s fc00::/7 -j RETURN

        ip6tables -A "${BW_CHAIN6}" -m set --match-set "${BW_IPSET6_VTRUSTED}" src -m hashlimit --hashlimit-name pteroprotect_bw_vtrusted_v6 \
            --hashlimit-above "${IP_TRUST_BW_VTRUSTED_KBPS}kb/sec" --hashlimit-burst "${BW_BURST_VTRUSTED_KB}kb" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
        ip6tables -A "${BW_CHAIN6}" -m set --match-set "${BW_IPSET6_VTRUSTED}" src -j RETURN
        ip6tables -A "${BW_CHAIN6}" -m set --match-set "${BW_IPSET6_TRUSTED}" src -m hashlimit --hashlimit-name pteroprotect_bw_trusted_v6 \
            --hashlimit-above "${IP_TRUST_BW_TRUSTED_KBPS}kb/sec" --hashlimit-burst "${BW_BURST_TRUSTED_KB}kb" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
        ip6tables -A "${BW_CHAIN6}" -m set --match-set "${BW_IPSET6_TRUSTED}" src -j RETURN
        ip6tables -A "${BW_CHAIN6}" -m set --match-set "${BW_IPSET6_PROBATION}" src -m hashlimit --hashlimit-name pteroprotect_bw_probation_v6 \
            --hashlimit-above "${IP_TRUST_BW_PROBATION_KBPS}kb/sec" --hashlimit-burst "${BW_BURST_PROBATION_KB}kb" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
        ip6tables -A "${BW_CHAIN6}" -m set --match-set "${BW_IPSET6_PROBATION}" src -j RETURN
        ip6tables -A "${BW_CHAIN6}" -m set --match-set "${BW_IPSET6_BAD}" src -m hashlimit --hashlimit-name pteroprotect_bw_bad_v6 \
            --hashlimit-above "${IP_TRUST_BW_BAD_KBPS}kb/sec" --hashlimit-burst "${BW_BURST_BAD_KB}kb" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
        ip6tables -A "${BW_CHAIN6}" -m set --match-set "${BW_IPSET6_BAD}" src -j RETURN
        ip6tables -A "${BW_CHAIN6}" -m set --match-set "${BW_IPSET6_WORST}" src -m hashlimit --hashlimit-name pteroprotect_bw_worst_v6 \
            --hashlimit-above "${IP_TRUST_BW_WORST_KBPS}kb/sec" --hashlimit-burst "${BW_BURST_WORST_KB}kb" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
        ip6tables -A "${BW_CHAIN6}" -m set --match-set "${BW_IPSET6_WORST}" src -j RETURN
        ip6tables -A "${BW_CHAIN6}" -m hashlimit --hashlimit-name pteroprotect_bw_default_v6 \
            --hashlimit-above "${IP_TRUST_BW_PROBATION_KBPS}kb/sec" --hashlimit-burst "${BW_BURST_PROBATION_KB}kb" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
        ip6tables -A "${BW_CHAIN6}" -j RETURN
    fi

    ip6tables -C INPUT -p tcp -m multiport --dports "${PROTECTED_TCP_PORTS}" -j "${CHAIN6}" >/dev/null 2>&1 || \
        ip6tables -I INPUT -p tcp -m multiport --dports "${PROTECTED_TCP_PORTS}" -j "${CHAIN6}"
    if [[ "${UDP_GUARD_ENABLED}" == "1" ]]; then
        ip6tables -C INPUT -p udp -j "${CHAIN6}" >/dev/null 2>&1 || \
            ip6tables -I INPUT -p udp -j "${CHAIN6}"
    fi

    if [[ "${SYNPROXY_ENABLED}" == "1" ]] && supports_synproxy_v6; then
        ip6tables -N "${SYNPROXY_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -F "${SYNPROXY_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -t raw -N "${RAW_CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -t raw -F "${RAW_CHAIN6}" >/dev/null 2>&1 || true

        ip6tables -t raw -C PREROUTING -p tcp -m multiport --dports "${PROTECTED_TCP_PORTS}" -j "${RAW_CHAIN6}" >/dev/null 2>&1 || \
            ip6tables -t raw -I PREROUTING -p tcp -m multiport --dports "${PROTECTED_TCP_PORTS}" -j "${RAW_CHAIN6}"

        ip6tables -C INPUT -p tcp -m multiport --dports "${PROTECTED_TCP_PORTS}" -m tcp --syn -j "${SYNPROXY_CHAIN6}" >/dev/null 2>&1 || \
            ip6tables -I INPUT -p tcp -m multiport --dports "${PROTECTED_TCP_PORTS}" -m tcp --syn -j "${SYNPROXY_CHAIN6}"

        SYNPROXY_READY6=0
        if ip6tables -A "${RAW_CHAIN6}" -p tcp -m conntrack --ctstate NEW -j CT --notrack >/dev/null 2>&1; then
            SYNPROXY_READY6=1
        elif ip6tables -A "${RAW_CHAIN6}" -p tcp -m conntrack --ctstate NEW -j NOTRACK >/dev/null 2>&1; then
            SYNPROXY_READY6=1
        fi

        if [[ "${SYNPROXY_READY6}" == "1" ]]; then
            ip6tables -A "${SYNPROXY_CHAIN6}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
            ip6tables -A "${SYNPROXY_CHAIN6}" -p tcp -m conntrack --ctstate INVALID,UNTRACKED -m tcp --syn -j SYNPROXY \
                --sack-perm --timestamp --wscale "${SYNPROXY_WSCALE}" --mss "${SYNPROXY_MSS}"
            ip6tables -A "${SYNPROXY_CHAIN6}" -m conntrack --ctstate INVALID -j DROP
            ip6tables -A "${SYNPROXY_CHAIN6}" -m conntrack --ctstate UNTRACKED -j DROP
            ip6tables -A "${SYNPROXY_CHAIN6}" -j RETURN
        else
            ip6tables -A "${SYNPROXY_CHAIN6}" -p tcp -m multiport --dports "${SSH_GUARD_PORTS}" --syn -m hashlimit \
                --hashlimit-name pteroprotect_ssh_syn_fallback_global_v6 --hashlimit-above "${SSH_GLOBAL_NEW_PER_SEC}"/second \
                --hashlimit-burst "${SSH_GLOBAL_NEW_BURST}" --hashlimit-mode dstport -j DROP
            ip6tables -A "${SYNPROXY_CHAIN6}" -p tcp -m multiport --dports "${SSH_GUARD_PORTS}" --syn -m hashlimit \
                --hashlimit-name pteroprotect_ssh_syn_fallback_src_v6 --hashlimit-above "${SSH_NEW_PER_IP_PER_MIN}"/minute \
                --hashlimit-burst "${SSH_NEW_PER_IP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
            ip6tables -A "${SYNPROXY_CHAIN6}" -j RETURN
        fi
    fi

    if have_cmd ipset; then
        ipset create "${IPSET6}" hash:ip family inet6 timeout "${BLACKHOLE_TTL}" -exist >/dev/null 2>&1 || true
    fi

    ip6tables -A "${CHAIN6}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
    ip6tables -A "${CHAIN6}" -s ::1/128 -j RETURN
    ip6tables -A "${CHAIN6}" -s fe80::/10 -j RETURN
    ip6tables -A "${CHAIN6}" -s fc00::/7 -j RETURN
    add_host_v6_whitelist "${CHAIN6}"
    add_dns_v6_whitelist "${CHAIN6}"
    add_ssh_v6_whitelist "${CHAIN6}"
    while read -r infra_host; do
        [[ -z "${infra_host}" ]] && continue
        add_resolved_v6_whitelist "${CHAIN6}" "${infra_host}"
    done < <(discover_infra_hosts)
    if have_cmd ipset; then
        ip6tables -A "${CHAIN6}" -m set --match-set "${IPSET6}" src -j DROP
    fi

    ip6tables -A "${CHAIN6}" -m conntrack --ctstate INVALID -j DROP
    if [[ "${UDP_GUARD_ENABLED}" == "1" ]]; then
        ip6tables -A "${CHAIN6}" -p udp -m hashlimit --hashlimit-name pteroprotect_udp_new_v6 \
            --hashlimit-above "${UDP_PER_IP_RATE}"/second --hashlimit-burst "${UDP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
    fi
    ip6tables -A "${CHAIN6}" -p tcp ! --syn -m conntrack --ctstate NEW -j DROP
    ip6tables -A "${CHAIN6}" -p tcp -m conntrack --ctstate NEW -j "${ABUSE_CHAIN6}"

    if have_cmd ipset; then
        ip6tables -A "${ABUSE_CHAIN6}" -m recent --name pteroprotect_burst_v6 --update --seconds "${RECENT_WINDOW}" --hitcount "${RECENT_HITCOUNT}" --rsource -j SET --add-set "${IPSET6}" src
    fi
    ip6tables -A "${ABUSE_CHAIN6}" -m recent --name pteroprotect_burst_v6 --update --seconds "${RECENT_WINDOW}" --hitcount "${RECENT_HITCOUNT}" --rsource -j DROP

    ip6tables -A "${ABUSE_CHAIN6}" -p tcp --tcp-flags ALL NONE -j DROP
    ip6tables -A "${ABUSE_CHAIN6}" -p tcp --tcp-flags ALL ALL -j DROP

    if have_cmd ipset; then
        ip6tables -A "${ABUSE_CHAIN6}" -p tcp -m multiport --dports "${SSH_GUARD_PORTS}" --syn -m connlimit \
            --connlimit-above "${SSH_CONNLIMIT_PER_IP}" --connlimit-mask 128 -j SET --add-set "${IPSET6}" src
    fi
    ip6tables -A "${ABUSE_CHAIN6}" -p tcp -m multiport --dports "${SSH_GUARD_PORTS}" --syn -m connlimit \
        --connlimit-above "${SSH_CONNLIMIT_PER_IP}" --connlimit-mask 128 -j DROP
    if have_cmd ipset; then
        ip6tables -A "${ABUSE_CHAIN6}" -p tcp -m multiport --dports "${SSH_GUARD_PORTS}" -m conntrack --ctstate NEW -m hashlimit \
            --hashlimit-name pteroprotect_ssh_new_src_v6 --hashlimit-above "${SSH_NEW_PER_IP_PER_MIN}"/minute \
            --hashlimit-burst "${SSH_NEW_PER_IP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j SET --add-set "${IPSET6}" src
    fi
    ip6tables -A "${ABUSE_CHAIN6}" -p tcp -m multiport --dports "${SSH_GUARD_PORTS}" -m conntrack --ctstate NEW -m hashlimit \
        --hashlimit-name pteroprotect_ssh_new_src_v6 --hashlimit-above "${SSH_NEW_PER_IP_PER_MIN}"/minute \
        --hashlimit-burst "${SSH_NEW_PER_IP_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
    ip6tables -A "${ABUSE_CHAIN6}" -p tcp -m multiport --dports "${SSH_GUARD_PORTS}" -m conntrack --ctstate NEW -m hashlimit \
        --hashlimit-name pteroprotect_ssh_new_global_v6 --hashlimit-above "${SSH_GLOBAL_NEW_PER_SEC}"/second \
        --hashlimit-burst "${SSH_GLOBAL_NEW_BURST}" --hashlimit-mode dstport -j DROP

    if have_cmd ipset; then
        ip6tables -A "${ABUSE_CHAIN6}" -p tcp --syn -m connlimit --connlimit-above "${CONNLIMIT_PER_IP}" --connlimit-mask 128 -j SET --add-set "${IPSET6}" src
    fi
    ip6tables -A "${ABUSE_CHAIN6}" -p tcp --syn -m connlimit --connlimit-above "${CONNLIMIT_PER_IP}" --connlimit-mask 128 -j DROP

    if have_cmd ipset; then
        ip6tables -A "${ABUSE_CHAIN6}" -p tcp -m hashlimit --hashlimit-name pteroprotect_new_v6 \
            --hashlimit-above "${NEW_CONN_RATE}"/second --hashlimit-burst "${NEW_CONN_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j SET --add-set "${IPSET6}" src
    fi
    ip6tables -A "${ABUSE_CHAIN6}" -p tcp -m hashlimit --hashlimit-name pteroprotect_new_v6 \
        --hashlimit-above "${NEW_CONN_RATE}"/second --hashlimit-burst "${NEW_CONN_BURST}" --hashlimit-mode srcip --hashlimit-srcmask 128 -j DROP
    ip6tables -A "${ABUSE_CHAIN6}" -p tcp -m conntrack --ctstate NEW -m hashlimit \
        --hashlimit-name pteroprotect_new_global_v6 --hashlimit-above "${TCP_GLOBAL_NEW_PER_SEC}"/second \
        --hashlimit-burst "${TCP_GLOBAL_NEW_BURST}" --hashlimit-mode dstport -j DROP

    ip6tables -A "${ABUSE_CHAIN6}" -m recent --name pteroprotect_burst_v6 --set --rsource -j RETURN
    ip6tables -A "${ABUSE_CHAIN6}" -j RETURN
    ip6tables -A "${CHAIN6}" -j RETURN
fi

# Keep unblock portal reachable even when source IP is currently blocked.
# Token verification is enforced by the portal app itself.
ensure_unblock_portal_accept_rule_v4 "${UNBLOCK_PORTAL_PORT}"

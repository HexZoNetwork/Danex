#!/usr/bin/env bash
set -euo pipefail

GUARD_HOME="${DANN_GUARD_HOME:-/pteroprotect}"
CONFIG_PATH="${GUARD_HOME}/config.json"
CHAIN4="PTEROPROTECT-EDGE-ONLY"
CHAIN6="PTEROPROTECT-EDGE-ONLY-V6"
PORTS_DEFAULT="80,443"
MODE="${1:-status}"
PORTS="${2:-${PORTS_DEFAULT}}"
CIDR_CSV="${3:-}"

usage() {
    cat <<'USAGE'
Usage:
  edge_origin_cloak.sh status
  edge_origin_cloak.sh apply [ports_csv] [edge_cidrs_csv]
  edge_origin_cloak.sh clear [ports_csv]

Examples:
  edge_origin_cloak.sh apply
  edge_origin_cloak.sh apply 80,443,18443 "173.245.48.0/20,103.21.244.0/22"
  edge_origin_cloak.sh clear 80,443,18443

Notes:
  - apply: allow web ports only from trusted edge CIDRs (+local/private + current SSH peers), drop others.
  - if edge_cidrs_csv is omitted, script reads config.json:
      network.trusted_proxy_ipv4_cidrs
      network.trusted_proxy_ipv6_cidrs
USAGE
}

have() { command -v "$1" >/dev/null 2>&1; }

sanitize_ports() {
    local raw="$1"
    raw="$(printf '%s' "${raw}" | tr -cd '0-9,')"
    raw="${raw#,}"
    raw="${raw%,}"
    if [[ -z "${raw}" ]]; then
        raw="${PORTS_DEFAULT}"
    fi
    printf '%s' "${raw}"
}

csv_to_lines() {
    local csv="$1"
    tr ',' '\n' <<<"${csv}" | sed '/^\s*$/d' | awk '{$1=$1;print}'
}

current_ssh_peer_v4() {
    ss -tn state established '( sport = :22 or sport = :2022 )' 2>/dev/null \
        | awk 'NR>1{print $5}' | sed 's/:.*$//' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | sort -u || true
}

current_ssh_peer_v6() {
    ss -tn state established '( sport = :22 or sport = :2022 )' 2>/dev/null \
        | awk 'NR>1{print $5}' | sed -E 's/^\[([0-9a-fA-F:]+)\]:.*/\1/' | grep -E ':' | sort -u || true
}

read_edge_cidrs_from_config() {
    local family="$1"
    if [[ ! -f "${CONFIG_PATH}" ]] || ! have python3; then
        return 0
    fi

    python3 - "${CONFIG_PATH}" "${family}" <<'PY'
import json, sys
path, fam = sys.argv[1], sys.argv[2]
try:
    with open(path, "r", encoding="utf-8") as f:
        data = json.load(f)
except Exception:
    sys.exit(0)

net = data.get("network") or {}
key = "trusted_proxy_ipv4_cidrs" if fam == "v4" else "trusted_proxy_ipv6_cidrs"
vals = net.get(key) or []
if isinstance(vals, str):
    vals = [x.strip() for x in vals.split(",") if x.strip()]
if isinstance(vals, list):
    for v in vals:
        v = str(v).strip()
        if v:
            print(v)
PY
}

ensure_chain4() {
    iptables -N "${CHAIN4}" >/dev/null 2>&1 || true
    iptables -F "${CHAIN4}" >/dev/null 2>&1 || true
}

ensure_chain6() {
    ip6tables -N "${CHAIN6}" >/dev/null 2>&1 || true
    ip6tables -F "${CHAIN6}" >/dev/null 2>&1 || true
}

apply_rules() {
    local ports="$1"
    local cidr_csv="$2"
    local cidr
    local allow_v4_count=0
    local allow_v6_count=0

    ports="$(sanitize_ports "${ports}")"
    echo "[edge-cloak] apply ports=${ports}"

    if ! have iptables; then
        echo "[edge-cloak] iptables not found" >&2
        exit 1
    fi

    while IFS= read -r cidr; do
        [[ -n "${cidr}" ]] || continue
        allow_v4_count=$((allow_v4_count + 1))
    done < <(read_edge_cidrs_from_config v4)

    while IFS= read -r cidr; do
        [[ -n "${cidr}" ]] || continue
        if [[ "${cidr}" == *:* ]]; then
            allow_v6_count=$((allow_v6_count + 1))
        else
            allow_v4_count=$((allow_v4_count + 1))
        fi
    done < <(csv_to_lines "${cidr_csv}")

    while IFS= read -r cidr; do
        [[ -n "${cidr}" ]] || continue
        allow_v6_count=$((allow_v6_count + 1))
    done < <(read_edge_cidrs_from_config v6)

    if (( allow_v4_count == 0 && allow_v6_count == 0 )); then
        echo "[edge-cloak] skip apply: no trusted edge CIDRs configured (fail-safe no-op)"
        return 0
    fi

    ensure_chain4
    iptables -A "${CHAIN4}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
    iptables -A "${CHAIN4}" -s 127.0.0.1/32 -j RETURN
    iptables -A "${CHAIN4}" -s 10.0.0.0/8 -j RETURN
    iptables -A "${CHAIN4}" -s 172.16.0.0/12 -j RETURN
    iptables -A "${CHAIN4}" -s 192.168.0.0/16 -j RETURN

    while IFS= read -r cidr; do
        [[ -n "${cidr}" ]] || continue
        iptables -A "${CHAIN4}" -s "${cidr}" -j RETURN
    done < <(read_edge_cidrs_from_config v4)

    while IFS= read -r cidr; do
        [[ -n "${cidr}" ]] || continue
        iptables -A "${CHAIN4}" -s "${cidr}" -j RETURN
    done < <(csv_to_lines "${cidr_csv}")

    while IFS= read -r ip; do
        [[ -n "${ip}" ]] || continue
        iptables -A "${CHAIN4}" -s "${ip}/32" -j RETURN
    done < <(current_ssh_peer_v4)

    iptables -A "${CHAIN4}" -j DROP

    while iptables -C INPUT -p tcp -m multiport --dports "${ports}" -j "${CHAIN4}" >/dev/null 2>&1; do
        iptables -D INPUT -p tcp -m multiport --dports "${ports}" -j "${CHAIN4}" >/dev/null 2>&1 || break
    done
    iptables -I INPUT 1 -p tcp -m multiport --dports "${ports}" -j "${CHAIN4}"

    if have ip6tables; then
        ensure_chain6
        ip6tables -A "${CHAIN6}" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
        ip6tables -A "${CHAIN6}" -s ::1/128 -j RETURN
        ip6tables -A "${CHAIN6}" -s fc00::/7 -j RETURN

        while IFS= read -r cidr; do
            [[ -n "${cidr}" ]] || continue
            ip6tables -A "${CHAIN6}" -s "${cidr}" -j RETURN
        done < <(read_edge_cidrs_from_config v6)

        while IFS= read -r ip; do
            [[ -n "${ip}" ]] || continue
            ip6tables -A "${CHAIN6}" -s "${ip}/128" -j RETURN
        done < <(current_ssh_peer_v6)

        ip6tables -A "${CHAIN6}" -j DROP
        while ip6tables -C INPUT -p tcp -m multiport --dports "${ports}" -j "${CHAIN6}" >/dev/null 2>&1; do
            ip6tables -D INPUT -p tcp -m multiport --dports "${ports}" -j "${CHAIN6}" >/dev/null 2>&1 || break
        done
        ip6tables -I INPUT 1 -p tcp -m multiport --dports "${ports}" -j "${CHAIN6}"
    fi
}

clear_rules() {
    local ports="$1"
    ports="$(sanitize_ports "${ports}")"
    echo "[edge-cloak] clear ports=${ports}"

    if have iptables; then
        while iptables -C INPUT -p tcp -m multiport --dports "${ports}" -j "${CHAIN4}" >/dev/null 2>&1; do
            iptables -D INPUT -p tcp -m multiport --dports "${ports}" -j "${CHAIN4}" >/dev/null 2>&1 || break
        done
        iptables -F "${CHAIN4}" >/dev/null 2>&1 || true
        iptables -X "${CHAIN4}" >/dev/null 2>&1 || true
    fi

    if have ip6tables; then
        while ip6tables -C INPUT -p tcp -m multiport --dports "${ports}" -j "${CHAIN6}" >/dev/null 2>&1; do
            ip6tables -D INPUT -p tcp -m multiport --dports "${ports}" -j "${CHAIN6}" >/dev/null 2>&1 || break
        done
        ip6tables -F "${CHAIN6}" >/dev/null 2>&1 || true
        ip6tables -X "${CHAIN6}" >/dev/null 2>&1 || true
    fi
}

status_rules() {
    echo "[edge-cloak] status"
    if have iptables; then
        echo "--- ipv4 chain ---"
        iptables -L "${CHAIN4}" -n -v --line-numbers 2>/dev/null || echo "chain_not_found"
        echo "--- ipv4 input hooks ---"
        iptables -S INPUT 2>/dev/null | grep "${CHAIN4}" || true
    fi
    if have ip6tables; then
        echo "--- ipv6 chain ---"
        ip6tables -L "${CHAIN6}" -n -v --line-numbers 2>/dev/null || echo "chain_not_found"
        echo "--- ipv6 input hooks ---"
        ip6tables -S INPUT 2>/dev/null | grep "${CHAIN6}" || true
    fi
}

case "${MODE}" in
    apply)
        apply_rules "${PORTS}" "${CIDR_CSV}"
        status_rules
        ;;
    clear)
        clear_rules "${PORTS}"
        status_rules
        ;;
    status)
        status_rules
        ;;
    *)
        usage
        exit 1
        ;;
esac

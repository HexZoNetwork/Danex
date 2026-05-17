#!/usr/bin/env bash
set -euo pipefail

CHAIN="${PTEROPROTECT_FW_CHAIN:-PTEROPROTECT}"
CHAIN6="${PTEROPROTECT_FW_CHAIN6:-PTEROPROTECT-V6}"
SET_TMP="${PTEROPROTECT_FW_SET_TMP:-pteroprotect_tmp_ban}"
SET_PERM="${PTEROPROTECT_FW_SET_PERM:-pteroprotect_perm_ban}"
ALLOW_SET="${PTEROPROTECT_FW_ALLOW_SET:-pteroprotect_allow}"
SET_TMP6="${PTEROPROTECT_FW_SET_TMP6:-pteroprotect_tmp_ban6}"
SET_PERM6="${PTEROPROTECT_FW_SET_PERM6:-pteroprotect_perm_ban6}"
ALLOW_SET6="${PTEROPROTECT_FW_ALLOW_SET6:-pteroprotect_allow6}"
DYN_BLOCK4="${PTEROPROTECT_FW_DYN_BLOCK4:-pteroprotect_block_v4}"
DYN_BLOCK6="${PTEROPROTECT_FW_DYN_BLOCK6:-pteroprotect_block_v6}"
STATE_DIR="${PTEROPROTECT_FW_STATE_DIR:-/var/lib/pteroprotect/firewall}"
RECENT_NAME4="${PTEROPROTECT_FW_RECENT4:-pteroprotect_ddos4}"
RECENT_NAME6="${PTEROPROTECT_FW_RECENT6:-pteroprotect_ddos6}"
DDOS_HITCOUNT="${PTEROPROTECT_FW_DDOS_HITCOUNT:-20}"
DDOS_WINDOW="${PTEROPROTECT_FW_DDOS_WINDOW:-10}"

log() { printf '[pteroprotect-fw] %s\n' "$*" >&2; }
die() { log "error: $*"; exit 1; }

need_root() {
    [[ "${EUID}" -eq 0 ]] || die "run as root"
}

valid_ip_or_cidr() {
    local value="$1"
    python3 - "${value}" <<'PY' >/dev/null 2>&1
import ipaddress, sys
value = sys.argv[1]
try:
    if "/" in value:
        ipaddress.ip_network(value, strict=False)
    else:
        ipaddress.ip_address(value)
except ValueError:
    sys.exit(1)
PY
}

is_ipv6_value() {
    [[ "$1" == *:* ]]
}

has_cmd() {
    command -v "$1" >/dev/null 2>&1
}

ensure_ipset() {
    has_cmd ipset || die "ipset not installed"
    ipset create "${ALLOW_SET}" hash:net family inet timeout 0 -exist >/dev/null 2>&1 || ipset list "${ALLOW_SET}" >/dev/null 2>&1
    ipset create "${SET_TMP}" hash:net family inet timeout 0 -exist >/dev/null 2>&1 || ipset list "${SET_TMP}" >/dev/null 2>&1
    ipset create "${SET_PERM}" hash:net family inet timeout 0 -exist >/dev/null 2>&1 || ipset list "${SET_PERM}" >/dev/null 2>&1
    ipset create "${DYN_BLOCK4}" hash:ip family inet timeout 0 counters -exist >/dev/null 2>&1 || ipset list "${DYN_BLOCK4}" >/dev/null 2>&1
    ipset create "${ALLOW_SET6}" hash:net family inet6 timeout 0 -exist >/dev/null 2>&1 || ipset list "${ALLOW_SET6}" >/dev/null 2>&1
    ipset create "${SET_TMP6}" hash:net family inet6 timeout 0 -exist >/dev/null 2>&1 || ipset list "${SET_TMP6}" >/dev/null 2>&1
    ipset create "${SET_PERM6}" hash:net family inet6 timeout 0 -exist >/dev/null 2>&1 || ipset list "${SET_PERM6}" >/dev/null 2>&1
    ipset create "${DYN_BLOCK6}" hash:ip family inet6 timeout 0 counters -exist >/dev/null 2>&1 || ipset list "${DYN_BLOCK6}" >/dev/null 2>&1
}

ensure_iptables() {
    has_cmd iptables || die "iptables not installed"
    iptables -N "${CHAIN}" >/dev/null 2>&1 || true
    iptables -C DOCKER-USER -j "${CHAIN}" >/dev/null 2>&1 || iptables -I DOCKER-USER -j "${CHAIN}"
    iptables -C INPUT -j "${CHAIN}" >/dev/null 2>&1 || iptables -I INPUT -j "${CHAIN}"
    iptables -C "${CHAIN}" -m set --match-set "${ALLOW_SET}" src -j RETURN >/dev/null 2>&1 || iptables -I "${CHAIN}" 1 -m set --match-set "${ALLOW_SET}" src -j RETURN
    iptables -C "${CHAIN}" -m set --match-set "${DYN_BLOCK4}" src -j DROP >/dev/null 2>&1 || iptables -A "${CHAIN}" -m set --match-set "${DYN_BLOCK4}" src -j DROP
    iptables -C "${CHAIN}" -m set --match-set "${SET_PERM}" src -j DROP >/dev/null 2>&1 || iptables -A "${CHAIN}" -m set --match-set "${SET_PERM}" src -j DROP
    iptables -C "${CHAIN}" -m set --match-set "${SET_TMP}" src -j DROP >/dev/null 2>&1 || iptables -A "${CHAIN}" -m set --match-set "${SET_TMP}" src -j DROP
    iptables -C "${CHAIN}" -p tcp -m conntrack --ctstate NEW -m recent --name "${RECENT_NAME4}" --update --seconds "${DDOS_WINDOW}" --hitcount "${DDOS_HITCOUNT}" -j DROP >/dev/null 2>&1 || iptables -A "${CHAIN}" -p tcp -m conntrack --ctstate NEW -m recent --name "${RECENT_NAME4}" --update --seconds "${DDOS_WINDOW}" --hitcount "${DDOS_HITCOUNT}" -j DROP
    iptables -C "${CHAIN}" -p tcp -m conntrack --ctstate NEW -m recent --name "${RECENT_NAME4}" --set -j RETURN >/dev/null 2>&1 || iptables -A "${CHAIN}" -p tcp -m conntrack --ctstate NEW -m recent --name "${RECENT_NAME4}" --set -j RETURN
}

ensure_ip6tables() {
    has_cmd ip6tables || return 0
    ip6tables -N "${CHAIN6}" >/dev/null 2>&1 || true
    ip6tables -C INPUT -j "${CHAIN6}" >/dev/null 2>&1 || ip6tables -I INPUT -j "${CHAIN6}"
    ip6tables -C "${CHAIN6}" -m set --match-set "${ALLOW_SET6}" src -j RETURN >/dev/null 2>&1 || ip6tables -I "${CHAIN6}" 1 -m set --match-set "${ALLOW_SET6}" src -j RETURN
    ip6tables -C "${CHAIN6}" -m set --match-set "${DYN_BLOCK6}" src -j DROP >/dev/null 2>&1 || ip6tables -A "${CHAIN6}" -m set --match-set "${DYN_BLOCK6}" src -j DROP
    ip6tables -C "${CHAIN6}" -m set --match-set "${SET_PERM6}" src -j DROP >/dev/null 2>&1 || ip6tables -A "${CHAIN6}" -m set --match-set "${SET_PERM6}" src -j DROP
    ip6tables -C "${CHAIN6}" -m set --match-set "${SET_TMP6}" src -j DROP >/dev/null 2>&1 || ip6tables -A "${CHAIN6}" -m set --match-set "${SET_TMP6}" src -j DROP
    ip6tables -C "${CHAIN6}" -p tcp -m conntrack --ctstate NEW -m recent --name "${RECENT_NAME6}" --update --seconds "${DDOS_WINDOW}" --hitcount "${DDOS_HITCOUNT}" -j DROP >/dev/null 2>&1 || ip6tables -A "${CHAIN6}" -p tcp -m conntrack --ctstate NEW -m recent --name "${RECENT_NAME6}" --update --seconds "${DDOS_WINDOW}" --hitcount "${DDOS_HITCOUNT}" -j DROP
    ip6tables -C "${CHAIN6}" -p tcp -m conntrack --ctstate NEW -m recent --name "${RECENT_NAME6}" --set -j RETURN >/dev/null 2>&1 || ip6tables -A "${CHAIN6}" -p tcp -m conntrack --ctstate NEW -m recent --name "${RECENT_NAME6}" --set -j RETURN
}

ensure_nft_shadow() {
    has_cmd nft || return 0
    nft list table inet pteroprotect >/dev/null 2>&1 || nft add table inet pteroprotect
    nft list set inet pteroprotect allow >/dev/null 2>&1 || nft add set inet pteroprotect allow '{ type ipv4_addr; flags interval; }'
    nft list set inet pteroprotect tmp_ban >/dev/null 2>&1 || nft add set inet pteroprotect tmp_ban '{ type ipv4_addr; flags timeout,interval; }'
    nft list set inet pteroprotect perm_ban >/dev/null 2>&1 || nft add set inet pteroprotect perm_ban '{ type ipv4_addr; flags interval; }'
}

apply_rules() {
    need_root
    mkdir -p "${STATE_DIR}"
    ensure_ipset
    ensure_iptables
    ensure_ip6tables
    ensure_nft_shadow
    date -u +%FT%TZ > "${STATE_DIR}/last_apply"
    log "rules applied"
}

dry_run() {
    has_cmd ipset || die "missing ipset"
    has_cmd iptables || die "missing iptables"
    if has_cmd nft; then
        nft --check -f /dev/stdin <<'NFT'
table inet pteroprotect {
  set allow { type ipv4_addr; flags interval; }
  set tmp_ban { type ipv4_addr; flags timeout,interval; }
  set perm_ban { type ipv4_addr; flags interval; }
}
NFT
    fi
    log "dry-run ok"
}

allow_ip() {
    need_root
    local value="$1"
    valid_ip_or_cidr "${value}" || die "invalid IP/CIDR: ${value}"
    apply_rules >/dev/null
    if is_ipv6_value "${value}"; then
        ipset add "${ALLOW_SET6}" "${value}" -exist
    else
        ipset add "${ALLOW_SET}" "${value}" -exist
    fi
    log "allowed ${value}"
}

ban_ip() {
    need_root
    local value="$1"
    local ttl="${2:-3600}"
    valid_ip_or_cidr "${value}" || die "invalid IP/CIDR: ${value}"
    [[ "${ttl}" =~ ^[0-9]+$ ]] || die "ttl must be seconds"
    apply_rules >/dev/null
    if (( ttl <= 0 )); then
        if is_ipv6_value "${value}"; then
            ipset add "${SET_PERM6}" "${value}" -exist
        else
            ipset add "${SET_PERM}" "${value}" -exist
        fi
        log "permanent ban ${value}"
    else
        if is_ipv6_value "${value}"; then
            ipset add "${SET_TMP6}" "${value}" timeout "${ttl}" -exist
        else
            ipset add "${SET_TMP}" "${value}" timeout "${ttl}" -exist
        fi
        log "temporary ban ${value} ttl=${ttl}"
    fi
}

unban_ip() {
    need_root
    local value="$1"
    valid_ip_or_cidr "${value}" || die "invalid IP/CIDR: ${value}"
    ipset del "${SET_TMP}" "${value}" 2>/dev/null || true
    ipset del "${SET_PERM}" "${value}" 2>/dev/null || true
    ipset del "${DYN_BLOCK4}" "${value}" 2>/dev/null || true
    ipset del "${SET_TMP6}" "${value}" 2>/dev/null || true
    ipset del "${SET_PERM6}" "${value}" 2>/dev/null || true
    ipset del "${DYN_BLOCK6}" "${value}" 2>/dev/null || true
    log "unbanned ${value}"
}

status() {
    printf 'chain=%s\n' "${CHAIN}"
    printf 'chain6=%s\n' "${CHAIN6}"
    printf 'ddos_recent_window=%ss hitcount=%s\n' "${DDOS_WINDOW}" "${DDOS_HITCOUNT}"
    if has_cmd ipset; then
        ipset list "${ALLOW_SET}" 2>/dev/null | sed 's/^/allow: /' || true
        ipset list "${SET_TMP}" 2>/dev/null | sed 's/^/tmp: /' || true
        ipset list "${SET_PERM}" 2>/dev/null | sed 's/^/perm: /' || true
        ipset list "${DYN_BLOCK4}" 2>/dev/null | sed 's/^/dyn4: /' || true
        ipset list "${ALLOW_SET6}" 2>/dev/null | sed 's/^/allow6: /' || true
        ipset list "${SET_TMP6}" 2>/dev/null | sed 's/^/tmp6: /' || true
        ipset list "${SET_PERM6}" 2>/dev/null | sed 's/^/perm6: /' || true
        ipset list "${DYN_BLOCK6}" 2>/dev/null | sed 's/^/dyn6: /' || true
    fi
    if has_cmd iptables; then
        iptables -L "${CHAIN}" -n -v --line-numbers 2>/dev/null || true
    fi
    if has_cmd ip6tables; then
        ip6tables -L "${CHAIN6}" -n -v --line-numbers 2>/dev/null || true
    fi
}

usage() {
    cat <<'USAGE'
usage: pteroprotect_firewall_manager.sh <command> [args]

commands:
  dry-run
  apply
  allow <ip-or-cidr>
  ban <ip-or-cidr> [ttl-seconds]   ttl 0 means permanent
  unban <ip-or-cidr>
  status
USAGE
}

cmd="${1:-}"
shift || true
case "${cmd}" in
    dry-run) dry_run ;;
    apply) apply_rules ;;
    allow) [[ $# -eq 1 ]] || die "allow needs IP/CIDR"; allow_ip "$1" ;;
    ban) [[ $# -ge 1 && $# -le 2 ]] || die "ban needs IP/CIDR [ttl]"; ban_ip "$1" "${2:-3600}" ;;
    unban) [[ $# -eq 1 ]] || die "unban needs IP/CIDR"; unban_ip "$1" ;;
    status) status ;;
    -h|--help|"") usage ;;
    *) usage; exit 1 ;;
esac

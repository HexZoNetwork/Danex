#!/usr/bin/env bash
set -euo pipefail

# PteroProtect host firewall manager.
# Default backend is nftables with real INPUT/FORWARD base-chain hooks. Legacy
# iptables/ipset is retained only as an explicit/automatic fallback for hosts
# where nftables is unavailable or cannot validate the ruleset.

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
BACKEND="${PTEROPROTECT_FW_BACKEND:-auto}" # auto|nft|iptables
CMD_TIMEOUT="${PTEROPROTECT_FW_CMD_TIMEOUT:-10}"
NFT_TABLE="${PTEROPROTECT_FW_NFT_TABLE:-pteroprotect}"
NFT_INPUT_CHAIN="${PTEROPROTECT_FW_NFT_INPUT_CHAIN:-input_guard}"
NFT_FORWARD_CHAIN="${PTEROPROTECT_FW_NFT_FORWARD_CHAIN:-forward_guard}"
NFT_PRIORITY="${PTEROPROTECT_FW_NFT_PRIORITY:--150}"

NFT_ALLOW4="allow4"
NFT_ALLOW6="allow6"
NFT_TMP4="tmp_ban4"
NFT_TMP6="tmp_ban6"
NFT_PERM4="perm_ban4"
NFT_PERM6="perm_ban6"
NFT_DYN4="dyn_block4"
NFT_DYN6="dyn_block6"

log() { printf '[pteroprotect-fw] %s\n' "$*" >&2; }
die() { log "error: $*"; exit 1; }

need_root() { [[ "${EUID}" -eq 0 ]] || die "run as root"; }
has_cmd() { command -v "$1" >/dev/null 2>&1; }

run_cmd() {
    if has_cmd timeout; then
        timeout "${CMD_TIMEOUT}s" "$@"
    else
        "$@"
    fi
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

is_ipv6_value() { [[ "$1" == *:* ]]; }

is_cidr_value() { [[ "$1" == */* ]]; }

validate_common_config() {
    [[ "${BACKEND}" =~ ^(auto|nft|iptables)$ ]] || die "PTEROPROTECT_FW_BACKEND must be auto, nft, or iptables"
    [[ "${DDOS_HITCOUNT}" =~ ^[0-9]+$ ]] || die "DDOS hitcount must be numeric"
    [[ "${DDOS_WINDOW}" =~ ^[0-9]+$ ]] || die "DDOS window must be numeric"
    (( DDOS_HITCOUNT > 0 )) || die "DDOS hitcount must be > 0"
    (( DDOS_WINDOW > 0 )) || die "DDOS window must be > 0"
    [[ "${CMD_TIMEOUT}" =~ ^[0-9]+$ ]] || die "command timeout must be numeric seconds"
    (( CMD_TIMEOUT > 0 && CMD_TIMEOUT <= 120 )) || die "command timeout must be 1..120 seconds"
}

emit_nft_ruleset() {
    local table="$1"
    cat <<NFT
table inet ${table} {
  set ${NFT_ALLOW4} { type ipv4_addr; flags interval; comment "PteroProtect IPv4 allowlist"; }
  set ${NFT_ALLOW6} { type ipv6_addr; flags interval; comment "PteroProtect IPv6 allowlist"; }
  set ${NFT_TMP4} { type ipv4_addr; flags interval,timeout; comment "PteroProtect temporary IPv4 bans"; }
  set ${NFT_TMP6} { type ipv6_addr; flags interval,timeout; comment "PteroProtect temporary IPv6 bans"; }
  set ${NFT_PERM4} { type ipv4_addr; flags interval; comment "PteroProtect permanent IPv4 bans"; }
  set ${NFT_PERM6} { type ipv6_addr; flags interval; comment "PteroProtect permanent IPv6 bans"; }
  set ${NFT_DYN4} { type ipv4_addr; flags timeout; counter; comment "PteroProtect dynamic IPv4 blocks"; }
  set ${NFT_DYN6} { type ipv6_addr; flags timeout; counter; comment "PteroProtect dynamic IPv6 blocks"; }

  chain ${NFT_INPUT_CHAIN} {
    type filter hook input priority ${NFT_PRIORITY}; policy accept;
    ct state established,related accept
    ip saddr @${NFT_ALLOW4} accept
    ip6 saddr @${NFT_ALLOW6} accept
    ip saddr @${NFT_DYN4} drop
    ip6 saddr @${NFT_DYN6} drop
    ip saddr @${NFT_PERM4} drop
    ip6 saddr @${NFT_PERM6} drop
    ip saddr @${NFT_TMP4} drop
    ip6 saddr @${NFT_TMP6} drop
    tcp flags & (syn|rst|ack) == syn ct state new meter pteroprotect_tcp4 { ip saddr timeout ${DDOS_WINDOW}s limit rate over ${DDOS_HITCOUNT}/second burst ${DDOS_HITCOUNT} packets } drop
    tcp flags & (syn|rst|ack) == syn ct state new meter pteroprotect_tcp6 { ip6 saddr timeout ${DDOS_WINDOW}s limit rate over ${DDOS_HITCOUNT}/second burst ${DDOS_HITCOUNT} packets } drop
  }

  chain ${NFT_FORWARD_CHAIN} {
    type filter hook forward priority ${NFT_PRIORITY}; policy accept;
    ct state established,related accept
    ip saddr @${NFT_ALLOW4} accept
    ip6 saddr @${NFT_ALLOW6} accept
    ip saddr @${NFT_DYN4} drop
    ip6 saddr @${NFT_DYN6} drop
    ip saddr @${NFT_PERM4} drop
    ip6 saddr @${NFT_PERM6} drop
    ip saddr @${NFT_TMP4} drop
    ip6 saddr @${NFT_TMP6} drop
  }
}
NFT
}

nft_can_validate() {
    has_cmd nft || return 1
    local check_table="pteroprotect_check_$$_${RANDOM}"
    emit_nft_ruleset "${check_table}" | run_cmd nft --check -f - >/dev/null 2>&1
}

select_backend() {
    validate_common_config
    case "${BACKEND}" in
        nft)
            nft_can_validate || die "nftables backend requested but validation failed"
            printf 'nft\n'
            ;;
        iptables)
            printf 'iptables\n'
            ;;
        auto)
            if nft_can_validate; then printf 'nft\n'; else printf 'iptables\n'; fi
            ;;
    esac
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
    iptables -C DOCKER-USER -j "${CHAIN}" >/dev/null 2>&1 || iptables -I DOCKER-USER -j "${CHAIN}" >/dev/null 2>&1 || true
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

ensure_nft_set() {
    local set_name="$1" spec="$2"
    run_cmd nft list set inet "${NFT_TABLE}" "${set_name}" >/dev/null 2>&1 || run_cmd nft add set inet "${NFT_TABLE}" "${set_name}" "${spec}"
}

nft_add_guard_rules() {
    local chain="$1" with_meter="$2"
    run_cmd nft add rule inet "${NFT_TABLE}" "${chain}" ct state established,related accept
    run_cmd nft add rule inet "${NFT_TABLE}" "${chain}" ip saddr @"${NFT_ALLOW4}" accept
    run_cmd nft add rule inet "${NFT_TABLE}" "${chain}" ip6 saddr @"${NFT_ALLOW6}" accept
    run_cmd nft add rule inet "${NFT_TABLE}" "${chain}" ip saddr @"${NFT_DYN4}" drop
    run_cmd nft add rule inet "${NFT_TABLE}" "${chain}" ip6 saddr @"${NFT_DYN6}" drop
    run_cmd nft add rule inet "${NFT_TABLE}" "${chain}" ip saddr @"${NFT_PERM4}" drop
    run_cmd nft add rule inet "${NFT_TABLE}" "${chain}" ip6 saddr @"${NFT_PERM6}" drop
    run_cmd nft add rule inet "${NFT_TABLE}" "${chain}" ip saddr @"${NFT_TMP4}" drop
    run_cmd nft add rule inet "${NFT_TABLE}" "${chain}" ip6 saddr @"${NFT_TMP6}" drop
    if [[ "${with_meter}" == "1" ]]; then
        run_cmd nft add rule inet "${NFT_TABLE}" "${chain}" tcp flags \& \( syn \| rst \| ack \) == syn ct state new meter pteroprotect_tcp4 "{ ip saddr timeout ${DDOS_WINDOW}s limit rate over ${DDOS_HITCOUNT}/second burst ${DDOS_HITCOUNT} packets }" drop
        run_cmd nft add rule inet "${NFT_TABLE}" "${chain}" tcp flags \& \( syn \| rst \| ack \) == syn ct state new meter pteroprotect_tcp6 "{ ip6 saddr timeout ${DDOS_WINDOW}s limit rate over ${DDOS_HITCOUNT}/second burst ${DDOS_HITCOUNT} packets }" drop
    fi
}

apply_nft() {
    local backup="${STATE_DIR}/nft-${NFT_TABLE}-rollback.nft"
    local had_table=0
    mkdir -p "${STATE_DIR}"
    if run_cmd nft list table inet "${NFT_TABLE}" >"${backup}" 2>/dev/null; then
        had_table=1
    else
        : >"${backup}"
        run_cmd nft add table inet "${NFT_TABLE}"
    fi

    ensure_nft_set "${NFT_ALLOW4}" '{ type ipv4_addr; flags interval; comment "PteroProtect IPv4 allowlist"; }'
    ensure_nft_set "${NFT_ALLOW6}" '{ type ipv6_addr; flags interval; comment "PteroProtect IPv6 allowlist"; }'
    ensure_nft_set "${NFT_TMP4}" '{ type ipv4_addr; flags interval,timeout; comment "PteroProtect temporary IPv4 bans"; }'
    ensure_nft_set "${NFT_TMP6}" '{ type ipv6_addr; flags interval,timeout; comment "PteroProtect temporary IPv6 bans"; }'
    ensure_nft_set "${NFT_PERM4}" '{ type ipv4_addr; flags interval; comment "PteroProtect permanent IPv4 bans"; }'
    ensure_nft_set "${NFT_PERM6}" '{ type ipv6_addr; flags interval; comment "PteroProtect permanent IPv6 bans"; }'
    ensure_nft_set "${NFT_DYN4}" '{ type ipv4_addr; flags timeout; counter; comment "PteroProtect dynamic IPv4 blocks"; }'
    ensure_nft_set "${NFT_DYN6}" '{ type ipv6_addr; flags timeout; counter; comment "PteroProtect dynamic IPv6 blocks"; }'

    if ! {
        run_cmd nft list chain inet "${NFT_TABLE}" "${NFT_INPUT_CHAIN}" >/dev/null 2>&1 && run_cmd nft delete chain inet "${NFT_TABLE}" "${NFT_INPUT_CHAIN}" || true
        run_cmd nft list chain inet "${NFT_TABLE}" "${NFT_FORWARD_CHAIN}" >/dev/null 2>&1 && run_cmd nft delete chain inet "${NFT_TABLE}" "${NFT_FORWARD_CHAIN}" || true
        run_cmd nft add chain inet "${NFT_TABLE}" "${NFT_INPUT_CHAIN}" "{ type filter hook input priority ${NFT_PRIORITY}; policy accept; }"
        run_cmd nft add chain inet "${NFT_TABLE}" "${NFT_FORWARD_CHAIN}" "{ type filter hook forward priority ${NFT_PRIORITY}; policy accept; }"
        nft_add_guard_rules "${NFT_INPUT_CHAIN}" 1
        nft_add_guard_rules "${NFT_FORWARD_CHAIN}" 0
    }; then
        log "nft apply failed; attempting rollback"
        if (( had_table == 1 )); then run_cmd nft -f "${backup}" >/dev/null 2>&1 || true; fi
        return 1
    fi
}

apply_iptables() {
    ensure_ipset
    ensure_iptables
    ensure_ip6tables
}

apply_rules() {
    need_root
    mkdir -p "${STATE_DIR}"
    local backend
    backend="$(select_backend)"
    if [[ "${backend}" == "nft" ]]; then
        apply_nft || die "nftables apply failed"
    else
        apply_iptables
    fi
    printf '%s\n' "${backend}" >"${STATE_DIR}/backend"
    date -u +%FT%TZ > "${STATE_DIR}/last_apply"
    log "rules applied backend=${backend}"
}

dry_run() {
    local backend
    backend="$(select_backend)"
    if [[ "${backend}" == "nft" ]]; then
        nft_can_validate || die "nftables dry-run validation failed"
    else
        has_cmd ipset || die "missing ipset"
        has_cmd iptables || die "missing iptables"
    fi
    log "dry-run ok backend=${backend}"
}

nft_add_element() {
    local set_name="$1" value="$2" ttl="${3:-}"
    if [[ -n "${ttl}" ]]; then
        run_cmd nft add element inet "${NFT_TABLE}" "${set_name}" "{ ${value} timeout ${ttl}s }"
    else
        run_cmd nft add element inet "${NFT_TABLE}" "${set_name}" "{ ${value} }"
    fi
}

nft_delete_element() {
    local set_name="$1" value="$2"
    run_cmd nft delete element inet "${NFT_TABLE}" "${set_name}" "{ ${value} }" >/dev/null 2>&1 || true
}

allow_ip() {
    need_root
    local value="$1" backend
    valid_ip_or_cidr "${value}" || die "invalid IP/CIDR: ${value}"
    apply_rules >/dev/null
    backend="$(cat "${STATE_DIR}/backend" 2>/dev/null || select_backend)"
    if [[ "${backend}" == "nft" ]]; then
        if is_ipv6_value "${value}"; then nft_add_element "${NFT_ALLOW6}" "${value}"; else nft_add_element "${NFT_ALLOW4}" "${value}"; fi
    else
        if is_ipv6_value "${value}"; then ipset add "${ALLOW_SET6}" "${value}" -exist; else ipset add "${ALLOW_SET}" "${value}" -exist; fi
    fi
    log "allowed ${value} backend=${backend}"
}

ban_ip() {
    need_root
    local value="$1" ttl="${2:-3600}" backend
    valid_ip_or_cidr "${value}" || die "invalid IP/CIDR: ${value}"
    [[ "${ttl}" =~ ^[0-9]+$ ]] || die "ttl must be seconds"
    apply_rules >/dev/null
    backend="$(cat "${STATE_DIR}/backend" 2>/dev/null || select_backend)"
    if [[ "${backend}" == "nft" ]]; then
        if (( ttl <= 0 )); then
            if is_ipv6_value "${value}"; then nft_add_element "${NFT_PERM6}" "${value}"; else nft_add_element "${NFT_PERM4}" "${value}"; fi
            log "permanent ban ${value} backend=nft"
        else
            if is_ipv6_value "${value}"; then nft_add_element "${NFT_TMP6}" "${value}" "${ttl}"; else nft_add_element "${NFT_TMP4}" "${value}" "${ttl}"; fi
            log "temporary ban ${value} ttl=${ttl} backend=nft"
        fi
    else
        if (( ttl <= 0 )); then
            if is_ipv6_value "${value}"; then ipset add "${SET_PERM6}" "${value}" -exist; else ipset add "${SET_PERM}" "${value}" -exist; fi
            log "permanent ban ${value} backend=iptables"
        else
            if is_ipv6_value "${value}"; then ipset add "${SET_TMP6}" "${value}" timeout "${ttl}" -exist; else ipset add "${SET_TMP}" "${value}" timeout "${ttl}" -exist; fi
            log "temporary ban ${value} ttl=${ttl} backend=iptables"
        fi
    fi
}

unban_ip() {
    need_root
    local value="$1" backend
    valid_ip_or_cidr "${value}" || die "invalid IP/CIDR: ${value}"
    backend="$(cat "${STATE_DIR}/backend" 2>/dev/null || select_backend)"
    if [[ "${backend}" == "nft" ]]; then
        nft_delete_element "${NFT_TMP4}" "${value}"
        nft_delete_element "${NFT_PERM4}" "${value}"
        if ! is_cidr_value "${value}"; then nft_delete_element "${NFT_DYN4}" "${value}"; fi
        nft_delete_element "${NFT_TMP6}" "${value}"
        nft_delete_element "${NFT_PERM6}" "${value}"
        if ! is_cidr_value "${value}"; then nft_delete_element "${NFT_DYN6}" "${value}"; fi
    elif has_cmd ipset; then
        ipset del "${SET_TMP}" "${value}" 2>/dev/null || true
        ipset del "${SET_PERM}" "${value}" 2>/dev/null || true
        ipset del "${DYN_BLOCK4}" "${value}" 2>/dev/null || true
        ipset del "${SET_TMP6}" "${value}" 2>/dev/null || true
        ipset del "${SET_PERM6}" "${value}" 2>/dev/null || true
        ipset del "${DYN_BLOCK6}" "${value}" 2>/dev/null || true
    fi
    log "unbanned ${value} backend=${backend}"
}

status() {
    local backend="unknown"
    [[ -f "${STATE_DIR}/backend" ]] && backend="$(cat "${STATE_DIR}/backend" 2>/dev/null || printf 'unknown')"
    printf 'backend=%s\n' "${backend}"
    printf 'nft_table=%s input_chain=%s forward_chain=%s priority=%s\n' "${NFT_TABLE}" "${NFT_INPUT_CHAIN}" "${NFT_FORWARD_CHAIN}" "${NFT_PRIORITY}"
    printf 'chain=%s\n' "${CHAIN}"
    printf 'chain6=%s\n' "${CHAIN6}"
    printf 'ddos_window=%ss hitcount_per_second=%s\n' "${DDOS_WINDOW}" "${DDOS_HITCOUNT}"
    if has_cmd nft; then run_cmd nft list table inet "${NFT_TABLE}" 2>/dev/null || true; fi
    if has_cmd ipset; then
        ipset list "${ALLOW_SET}" 2>/dev/null || true
        ipset list "${SET_TMP}" 2>/dev/null || true
        ipset list "${SET_PERM}" 2>/dev/null || true
        ipset list "${DYN_BLOCK4}" 2>/dev/null || true
        ipset list "${ALLOW_SET6}" 2>/dev/null || true
        ipset list "${SET_TMP6}" 2>/dev/null || true
        ipset list "${SET_PERM6}" 2>/dev/null || true
        ipset list "${DYN_BLOCK6}" 2>/dev/null || true
    fi
    if has_cmd iptables; then iptables -L "${CHAIN}" -n -v --line-numbers 2>/dev/null || true; fi
    if has_cmd ip6tables; then ip6tables -L "${CHAIN6}" -n -v --line-numbers 2>/dev/null || true; fi
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

environment:
  PTEROPROTECT_FW_BACKEND=auto|nft|iptables  default: auto
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

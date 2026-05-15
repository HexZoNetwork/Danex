#!/usr/bin/env bash
set -euo pipefail

CHAIN="${PTEROPROTECT_FW_CHAIN:-PTEROPROTECT}"
SET_TMP="${PTEROPROTECT_FW_SET_TMP:-pteroprotect_tmp_ban}"
SET_PERM="${PTEROPROTECT_FW_SET_PERM:-pteroprotect_perm_ban}"
ALLOW_SET="${PTEROPROTECT_FW_ALLOW_SET:-pteroprotect_allow}"
STATE_DIR="${PTEROPROTECT_FW_STATE_DIR:-/var/lib/pteroprotect/firewall}"

log() { printf '[pteroprotect-fw] %s\n' "$*" >&2; }
die() { log "error: $*"; exit 1; }

need_root() {
    [[ "${EUID}" -eq 0 ]] || die "run as root"
}

valid_ip_or_cidr() {
    local value="$1"
    [[ "${value}" =~ ^[0-9a-fA-F:.]+(/[0-9]{1,3})?$ ]]
}

has_cmd() {
    command -v "$1" >/dev/null 2>&1
}

ensure_ipset() {
    has_cmd ipset || die "ipset not installed"
    ipset create "${ALLOW_SET}" hash:net family inet timeout 0 -exist
    ipset create "${SET_TMP}" hash:net family inet timeout 0 -exist
    ipset create "${SET_PERM}" hash:net family inet timeout 0 -exist
}

ensure_iptables() {
    has_cmd iptables || die "iptables not installed"
    iptables -N "${CHAIN}" >/dev/null 2>&1 || true
    iptables -C DOCKER-USER -j "${CHAIN}" >/dev/null 2>&1 || iptables -I DOCKER-USER -j "${CHAIN}"
    iptables -C INPUT -j "${CHAIN}" >/dev/null 2>&1 || iptables -I INPUT -j "${CHAIN}"
    iptables -C "${CHAIN}" -m set --match-set "${ALLOW_SET}" src -j RETURN >/dev/null 2>&1 || iptables -I "${CHAIN}" 1 -m set --match-set "${ALLOW_SET}" src -j RETURN
    iptables -C "${CHAIN}" -m set --match-set "${SET_PERM}" src -j DROP >/dev/null 2>&1 || iptables -A "${CHAIN}" -m set --match-set "${SET_PERM}" src -j DROP
    iptables -C "${CHAIN}" -m set --match-set "${SET_TMP}" src -j DROP >/dev/null 2>&1 || iptables -A "${CHAIN}" -m set --match-set "${SET_TMP}" src -j DROP
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
    ipset add "${ALLOW_SET}" "${value}" -exist
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
        ipset add "${SET_PERM}" "${value}" -exist
        log "permanent ban ${value}"
    else
        ipset add "${SET_TMP}" "${value}" timeout "${ttl}" -exist
        log "temporary ban ${value} ttl=${ttl}"
    fi
}

unban_ip() {
    need_root
    local value="$1"
    valid_ip_or_cidr "${value}" || die "invalid IP/CIDR: ${value}"
    ipset del "${SET_TMP}" "${value}" 2>/dev/null || true
    ipset del "${SET_PERM}" "${value}" 2>/dev/null || true
    log "unbanned ${value}"
}

status() {
    printf 'chain=%s\n' "${CHAIN}"
    if has_cmd ipset; then
        ipset list "${ALLOW_SET}" 2>/dev/null | sed 's/^/allow: /' || true
        ipset list "${SET_TMP}" 2>/dev/null | sed 's/^/tmp: /' || true
        ipset list "${SET_PERM}" 2>/dev/null | sed 's/^/perm: /' || true
    fi
    if has_cmd iptables; then
        iptables -S "${CHAIN}" 2>/dev/null || true
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

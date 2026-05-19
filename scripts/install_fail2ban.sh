#!/usr/bin/env bash
set -euo pipefail

INSTALL_DIR="${1:-/pteroprotect}"

if ! command -v fail2ban-client >/dev/null 2>&1; then
    exit 0
fi

if [[ ! -d "${INSTALL_DIR}/host_overrides/fail2ban" ]]; then
    exit 0
fi

install -d -o root -g root -m 0755 /etc/fail2ban/filter.d /etc/fail2ban/jail.d /etc/tmpfiles.d
mkdir -p /dev/shm/pteroprotect
touch /dev/shm/pteroprotect/waf.log
touch /dev/shm/pteroprotect/ddos_host.log
touch /dev/shm/pteroprotect/ddos_host.latest

cat > /etc/tmpfiles.d/pteroprotect.conf <<'EOF'
d /dev/shm/pteroprotect 0755 root root -
f /dev/shm/pteroprotect/waf.log 0644 root root -
f /dev/shm/pteroprotect/ddos_host.log 0644 root root -
f /dev/shm/pteroprotect/ddos_host.latest 0644 root root -
EOF

if command -v systemd-tmpfiles >/dev/null 2>&1; then
    systemd-tmpfiles --create /etc/tmpfiles.d/pteroprotect.conf >/dev/null 2>&1 || true
fi

if [[ -d "${INSTALL_DIR}/host_overrides/fail2ban/filter.d" ]]; then
    cp -f "${INSTALL_DIR}"/host_overrides/fail2ban/filter.d/*.conf /etc/fail2ban/filter.d/ 2>/dev/null || true
fi

if [[ -d "${INSTALL_DIR}/host_overrides/fail2ban/jail.d" ]]; then
    cp -f "${INSTALL_DIR}"/host_overrides/fail2ban/jail.d/*.local /etc/fail2ban/jail.d/ 2>/dev/null || true
fi

# Hybrid backend strategy for sshd jail:
# prefer systemd when journald is available, otherwise force file backend/logpath.
if [[ -f /etc/fail2ban/jail.d/pteroprotect.local ]]; then
    if [[ -d /run/systemd/system ]] && command -v journalctl >/dev/null 2>&1; then
        sed -i -E '/^\[sshd\]/,/^\[/{s/^backend\s*=.*/backend = systemd/}' /etc/fail2ban/jail.d/pteroprotect.local
    else
        sed -i -E '/^\[sshd\]/,/^\[/{s/^backend\s*=.*/backend = auto/}' /etc/fail2ban/jail.d/pteroprotect.local
        if ! grep -q '^logpath = ' /etc/fail2ban/jail.d/pteroprotect.local; then
            awk '
                BEGIN{in_sshd=0}
                /^\[sshd\]/{in_sshd=1; print; next}
                /^\[/{if(in_sshd){print "logpath = /var/log/auth.log"; in_sshd=0} print; next}
                {print}
                END{if(in_sshd){print "logpath = /var/log/auth.log"}}
            ' /etc/fail2ban/jail.d/pteroprotect.local > /etc/fail2ban/jail.d/pteroprotect.local.tmp
            mv /etc/fail2ban/jail.d/pteroprotect.local.tmp /etc/fail2ban/jail.d/pteroprotect.local
        fi
    fi
fi

if ! fail2ban-client -d >/dev/null 2>&1; then
    echo "[fail2ban] config validation failed; skipping service restart." >&2
    fail2ban-client -d || true
    exit 0
fi

if command -v systemctl >/dev/null 2>&1; then
    systemctl enable fail2ban >/dev/null 2>&1 || true
    systemctl restart fail2ban >/dev/null 2>&1 || systemctl start fail2ban >/dev/null 2>&1 || true
    if ! systemctl is-active --quiet fail2ban; then
        echo "[fail2ban] service failed to stay online." >&2
        systemctl --no-pager --full status fail2ban || true
        journalctl -u fail2ban -n 120 --no-pager || true
    fi
fi

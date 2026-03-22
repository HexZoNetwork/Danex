#!/usr/bin/env bash
set -euo pipefail

INSTALL_DIR="${1:-/pteroprotect}"

if ! command -v fail2ban-client >/dev/null 2>&1; then
    exit 0
fi

if [[ ! -d "${INSTALL_DIR}/host_overrides/fail2ban" ]]; then
    exit 0
fi

mkdir -p /etc/fail2ban/filter.d /etc/fail2ban/jail.d
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

if command -v systemctl >/dev/null 2>&1; then
    systemctl enable fail2ban >/dev/null 2>&1 || true
    systemctl restart fail2ban >/dev/null 2>&1 || systemctl start fail2ban >/dev/null 2>&1 || true
fi

<?php

namespace Pterodactyl\Services\Nodes\AutoConfigure;

class RemoteScriptBuilder
{
    public function build(int $wingsPort, string $fallbackRange, string $firewallMode): string
    {
        $port = max(1, min(65535, $wingsPort));
        $range = preg_replace('/[^0-9,\-]/', '', $fallbackRange) ?: '8081-8099';
        $mode = $firewallMode === 'minimal' ? 'minimal' : 'auto';

        return <<<'BASH'
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
WINGS_PORT="__WINGS_PORT__"
FALLBACK_RANGE="__FALLBACK_RANGE__"
FIREWALL_MODE="__FIREWALL_MODE__"

log() { printf '[%s] %s\n' "$(date -u +%FT%TZ)" "$*"; }

detect_pm() {
  if command -v apt-get >/dev/null 2>&1; then echo apt; return; fi
  if command -v dnf >/dev/null 2>&1; then echo dnf; return; fi
  if command -v yum >/dev/null 2>&1; then echo yum; return; fi
  echo unknown
}

install_deps() {
  local pm
  pm="$(detect_pm)"
  case "$pm" in
    apt)
      apt-get update -y
      apt-get install -y curl tar jq ufw
      ;;
    dnf)
      dnf install -y curl tar jq firewalld
      ;;
    yum)
      yum install -y curl tar jq firewalld
      ;;
    *)
      log "unsupported package manager"
      exit 21
      ;;
  esac
}

port_in_use() {
  ss -ltn "( sport = :$1 )" | grep -q ":$1"
}

pick_port() {
  if ! port_in_use "$WINGS_PORT"; then
    echo "$WINGS_PORT"
    return
  fi

  IFS=',' read -r -a segments <<< "$FALLBACK_RANGE"
  for seg in "${segments[@]}"; do
    if [[ "$seg" == *-* ]]; then
      start="${seg%-*}"
      end="${seg#*-}"
      for ((p=start; p<=end; p++)); do
        if ! port_in_use "$p"; then echo "$p"; return; fi
      done
    else
      if ! port_in_use "$seg"; then echo "$seg"; return; fi
    fi
  done
  exit 31
}

configure_firewall() {
  local p="$1"
  if [[ "$FIREWALL_MODE" != "auto" && "$FIREWALL_MODE" != "minimal" ]]; then
    return
  fi

  if command -v ufw >/dev/null 2>&1; then
    ufw allow "$p"/tcp || true
    ufw allow 2022/tcp || true
  fi
  if command -v firewall-cmd >/dev/null 2>&1; then
    systemctl enable --now firewalld || true
    firewall-cmd --permanent --add-port="$p"/tcp || true
    firewall-cmd --permanent --add-port=2022/tcp || true
    firewall-cmd --reload || true
  fi
  if command -v setenforce >/dev/null 2>&1; then
    setenforce 0 || true
  fi
}

install_wings() {
  if ! command -v wings >/dev/null 2>&1; then
    curl -L https://github.com/pterodactyl/wings/releases/latest/download/wings_linux_amd64 -o /usr/local/bin/wings
    chmod +x /usr/local/bin/wings
  fi
}

write_unit() {
  cat >/etc/systemd/system/wings.service <<'UNIT'
[Unit]
Description=Pterodactyl Wings Daemon
After=network.target

[Service]
User=root
WorkingDirectory=/etc/pterodactyl
LimitNOFILE=4096
PIDFile=/var/run/wings/daemon.pid
ExecStart=/usr/local/bin/wings
Restart=on-failure
StartLimitInterval=180
StartLimitBurst=30

[Install]
WantedBy=multi-user.target
UNIT
}

main() {
  install_deps
  install_wings
  mkdir -p /etc/pterodactyl /var/run/wings
  selected_port="$(pick_port)"
  configure_firewall "$selected_port"
  write_unit
  systemctl daemon-reload
  systemctl enable wings || true
  systemctl restart wings || true
  log "selected_wings_port=$selected_port"
  echo "SELECTED_WINGS_PORT=$selected_port"
}

main "$@"
BASH
;
    }

    public function render(int $wingsPort, string $fallbackRange, string $firewallMode): string
    {
        return str_replace(
            ['__WINGS_PORT__', '__FALLBACK_RANGE__', '__FIREWALL_MODE__'],
            [(string) $wingsPort, $fallbackRange, $firewallMode],
            $this->build($wingsPort, $fallbackRange, $firewallMode)
        );
    }
}

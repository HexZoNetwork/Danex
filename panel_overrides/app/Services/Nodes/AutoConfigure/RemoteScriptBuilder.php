<?php

namespace Pterodactyl\Services\Nodes\AutoConfigure;

class RemoteScriptBuilder
{
    public function build(int $wingsPort, string $fallbackRange, string $firewallMode, string $nodeYamlB64, bool $protectedMode): string
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
NODE_YAML_B64="__NODE_YAML_B64__"
PROTECTED_MODE="__PROTECTED_MODE__"

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
      apt-get install -y curl tar jq ufw ca-certificates gnupg lsb-release python3
      ;;
    dnf)
      dnf install -y curl tar jq firewalld ca-certificates python3
      ;;
    yum)
      yum install -y curl tar jq firewalld ca-certificates python3
      ;;
    *)
      log "unsupported package manager"
      exit 21
      ;;
  esac
}

install_docker() {
  if command -v docker >/dev/null 2>&1; then
    return
  fi
  local pm
  pm="$(detect_pm)"
  case "$pm" in
    apt)
      install -m 0755 -d /etc/apt/keyrings
      if [[ ! -f /etc/apt/keyrings/docker.asc ]]; then
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
        chmod a+r /etc/apt/keyrings/docker.asc
      fi
      . /etc/os-release
      echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" >/etc/apt/sources.list.d/docker.list
      apt-get update -y
      apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
      ;;
    dnf)
      dnf -y install dnf-plugins-core
      dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
      dnf -y install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
      ;;
    yum)
      yum -y install yum-utils
      yum-config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
      yum -y install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
      ;;
    *)
      log "cannot install docker on unsupported package manager"
      exit 22
      ;;
  esac
  systemctl enable --now docker
}

port_in_use() {
  ss -ltn "( sport = :$1 )" | grep -q ":$1"
}

port_owned_by_wings() {
  local line
  line="$(ss -ltnp "( sport = :$1 )" 2>/dev/null | awk 'NR>1{print; exit}' || true)"
  [[ -n "$line" ]] || return 1
  printf '%s' "$line" | grep -Eiq 'users:\\(\\(\"wings\"|/usr/(local/)?bin/wings|wings\\.service'
}

pick_port() {
  if ! port_in_use "$WINGS_PORT"; then
    echo "$WINGS_PORT"
    return
  fi

  if port_owned_by_wings "$WINGS_PORT"; then
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
  if command -v semanage >/dev/null 2>&1; then
    semanage port -a -t http_port_t -p tcp "$p" >/dev/null 2>&1 || semanage port -m -t http_port_t -p tcp "$p" >/dev/null 2>&1 || true
  fi
}

install_wings() {
  if ! command -v wings >/dev/null 2>&1; then
    curl -L https://github.com/pterodactyl/wings/releases/latest/download/wings_linux_amd64 -o /usr/local/bin/wings
    chmod +x /usr/local/bin/wings
  fi
}

ensure_wings_user() {
  if ! id -u wings >/dev/null 2>&1; then
    useradd --system --home /etc/pterodactyl --shell /usr/sbin/nologin wings || true
  fi
  if getent group docker >/dev/null 2>&1; then
    usermod -aG docker wings || true
  fi
}

write_unit() {
  cat >/etc/systemd/system/wings.service <<'UNIT'
[Unit]
Description=Pterodactyl Wings Daemon
After=network.target

[Service]
User=wings
Group=wings
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

write_node_config() {
  mkdir -p /etc/pterodactyl
  printf '%s' "$NODE_YAML_B64" | base64 -d >/etc/pterodactyl/config.yml
}

patch_node_config_for_protect() {
  local public_port="$1"
  python3 - "$public_port" <<'PY'
import re
import sys
from pathlib import Path

public_port = str(sys.argv[1])
path = Path("/etc/pterodactyl/config.yml")
if not path.exists():
    raise SystemExit(0)

lines = path.read_text().splitlines()
out = []
in_api = False
in_ssl = False

for line in lines:
    if re.match(r'^api:\s*$', line):
        in_api = True
        in_ssl = False
        out.append(line)
        continue
    if in_api and re.match(r'^\s{2}ssl:\s*$', line):
        in_ssl = True
        out.append(line)
        continue
    if re.match(r'^[^\s]', line):
        in_api = False
        in_ssl = False

    if in_api and re.match(r'^\s{2}host:\s*', line):
        out.append("  host: 127.0.0.1")
        continue
    if in_api and re.match(r'^\s{2}port:\s*', line):
        out.append("  port: 18080")
        continue
    if re.match(r'^\s{2}check_permissions_on_boot:\s*', line):
        out.append("  check_permissions_on_boot: true")
        continue
    if in_ssl and re.match(r'^\s{4}enabled:\s*', line):
        out.append("    enabled: false")
        continue
    if re.match(r'^ignore_panel_config_updates:\s*', line):
        out.append("ignore_panel_config_updates: true")
        continue
    out.append(line)

if not any(l.startswith("ignore_panel_config_updates:") for l in out):
    out.append("ignore_panel_config_updates: true")
if not any(re.match(r'^\s{2}check_permissions_on_boot:\s*', l) for l in out):
    for i, line in enumerate(out):
        if re.match(r'^\s{2}websocket_log_count:\s*', line):
            out.insert(i, "  check_permissions_on_boot: true")
            break

path.write_text("\n".join(out) + "\n")
PY
}

configure_wings_guard() {
  local public_port="$1"
  local cert key
  cert="$(awk -F': ' '/^[[:space:]]{4}cert:[[:space:]]/{print $2; exit}' /etc/pterodactyl/config.yml | tr -d "\"'[:space:]")"
  key="$(awk -F': ' '/^[[:space:]]{4}key:[[:space:]]/{print $2; exit}' /etc/pterodactyl/config.yml | tr -d "\"'[:space:]")"
  if [[ ! -f "$cert" || ! -f "$key" ]]; then
    log "wings_guard_skip_missing_cert"
    return
  fi
  if ! command -v nginx >/dev/null 2>&1; then
    log "wings_guard_skip_no_nginx"
    return
  fi

  cat >/etc/nginx/sites-available/wings-guard.conf <<EOF
upstream pteroprotect_wings_pool {
    server 127.0.0.1:18080 max_fails=3 fail_timeout=3s;
    keepalive 128;
}

server {
    listen ${public_port} ssl;
    listen [::]:${public_port} ssl;
    server_name _;
    ssl_certificate ${cert};
    ssl_certificate_key ${key};
    include /etc/letsencrypt/options-ssl-nginx.conf;
    access_log /var/log/nginx/pteroprotect.access.log combined;

    location = /__pteroprotect/challenge/check_token {
        internal;
        proxy_pass http://127.0.0.1:18444/check-token;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-PteroProtect-Internal 1;
        proxy_set_header User-Agent \$http_user_agent;
        proxy_set_header Authorization \$http_authorization;
        proxy_set_header Content-Length "";
        proxy_pass_request_body off;
        proxy_connect_timeout 300ms;
        proxy_send_timeout 1s;
        proxy_read_timeout 1s;
    }

    location @drop_cto {
        return 444;
    }

    location @wings_upstream {
        proxy_pass http://pteroprotect_wings_pool;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_connect_timeout 3s;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location / {
        if (\$request_method = OPTIONS) { return 418; }
        if (\$http_user_agent ~* "^GuzzleHttp/") { return 418; }
        if (\$http_upgrade ~* "websocket") { return 418; }
        if (\$request_uri ~* "(\?|&)token=") { return 418; }

        auth_request /__pteroprotect/challenge/check_token;
        error_page 401 403 = @drop_cto;
        error_page 418 = @wings_upstream;

        proxy_pass http://pteroprotect_wings_pool;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_connect_timeout 3s;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}
EOF
  ln -sfn /etc/nginx/sites-available/wings-guard.conf /etc/nginx/sites-enabled/wings-guard.conf
  nginx -t
  systemctl reload nginx || systemctl restart nginx
}

main() {
  install_deps
  install_docker
  install_wings
  ensure_wings_user
  mkdir -p /etc/pterodactyl /var/run/wings
  chown -R wings:wings /etc/pterodactyl /var/run/wings || true
  selected_port="$(pick_port)"
  write_node_config
  if [[ "$PROTECTED_MODE" == "1" ]]; then
    patch_node_config_for_protect "$selected_port"
  fi
  configure_firewall "$selected_port"
  write_unit
  systemctl daemon-reload
  systemctl enable wings || true
  systemctl restart wings
  if [[ "$PROTECTED_MODE" == "1" ]]; then
    configure_wings_guard "$selected_port"
  fi
  curl -sS -m 5 -o /dev/null -w "%{http_code}" http://127.0.0.1:18080/api/system | grep -Eq '^(200|401|403)$'
  log "selected_wings_port=$selected_port"
  echo "SELECTED_WINGS_PORT=$selected_port"
}

main "$@"
BASH;
    }

    public function render(int $wingsPort, string $fallbackRange, string $firewallMode, string $nodeYamlB64, bool $protectedMode): string
    {
        $safeYaml = preg_replace('/[^A-Za-z0-9+\/=]/', '', $nodeYamlB64) ?: '';
        $protected = $protectedMode ? '1' : '0';

        return str_replace(
            ['__WINGS_PORT__', '__FALLBACK_RANGE__', '__FIREWALL_MODE__', '__NODE_YAML_B64__', '__PROTECTED_MODE__'],
            [(string) $wingsPort, $fallbackRange, $firewallMode, $safeYaml, $protected],
            $this->build($wingsPort, $fallbackRange, $firewallMode, $nodeYamlB64, $protectedMode)
        );
    }
}

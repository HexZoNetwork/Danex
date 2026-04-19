#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

MOCK_ROOT="${PROJECT_DIR}/.codespaces/mock"
FAKEBIN_DIR="${PROJECT_DIR}/.codespaces/bin"
INSTALL_DIR="${INSTALL_DIR:-${MOCK_ROOT}/pteroprotect}"
PANEL_DIR="${PANEL_DIR:-${MOCK_ROOT}/var/www/pterodactyl}"
SYSTEMD_DIR="${SYSTEMD_DIR:-${MOCK_ROOT}/etc/systemd/system}"
NGINX_DIR="${NGINX_DIR:-${MOCK_ROOT}/etc/nginx}"
RUNTIME_DIR="${MOCK_ROOT}/runtime"
PANEL_ENV_FILE="${PANEL_DIR}/.env"

REAL_PANEL_DIR="${REAL_PANEL_DIR:-/var/www/pterodactyl}"
REAL_PANEL_URL="${REAL_PANEL_URL:-https://github.com/pterodactyl/panel/releases/latest/download/panel.tar.gz}"
REAL_PANEL_DB_NAME="${REAL_PANEL_DB_NAME:-panel}"
REAL_PANEL_DB_USER="${REAL_PANEL_DB_USER:-pterodactyl}"
REAL_PANEL_DB_PASS_FILE="${RUNTIME_DIR}/real-panel-db-password.txt"
SYSTEM_PHP_BIN="${SYSTEM_PHP_BIN:-/usr/bin/php8.3}"

RUN_SETUP=0
USE_SUDO=0
RESET=0
INSTALL_REAL_PANEL=1
PANEL_ONLY=0
DUMMY_PANEL=0

log() {
    echo "[codespace] $*"
}

warn() {
    echo "[codespace] warning: $*" >&2
}

fail() {
    echo "[codespace] error: $*" >&2
    exit 1
}

usage() {
    cat <<'EOF'
Usage:
  bash code.sh
  bash code.sh --panel-only
  bash code.sh --run-setup
  bash code.sh --skip-panel-install --run-setup
  bash code.sh --dummy-panel
  bash code.sh --reset

Default behavior:
  1) Install real Pterodactyl panel first (following official pterodactyl.io flow)
  2) If requested, prepare Codespaces dummy/mock environment for setup.sh

Options:
  --panel-only          Install real Pterodactyl panel only, then exit.
  --run-setup           Run setup.sh with mocked service/firewall commands.
  --skip-panel-install  Skip real panel installation step.
  --dummy-panel         Bootstrap local dummy panel on port 8080.
  --sudo                Force privileged steps via sudo -E.
  --reset               Remove .codespaces/mock and .codespaces/bin first.
  --help                Show this help.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --panel-only)
            PANEL_ONLY=1
            ;;
        --run-setup)
            RUN_SETUP=1
            ;;
        --skip-panel-install)
            INSTALL_REAL_PANEL=0
            ;;
        --dummy-panel)
            DUMMY_PANEL=1
            ;;
        --sudo)
            USE_SUDO=1
            ;;
        --reset)
            RESET=1
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            fail "unknown option: $1"
            ;;
    esac
    shift
done

if [[ "${RESET}" -eq 1 ]]; then
    rm -rf "${MOCK_ROOT}" "${FAKEBIN_DIR}"
fi

detect_local_ip() {
    local ip=""
    ip="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
    if [[ -z "${ip}" ]]; then
        ip="$(ip route get 1.1.1.1 2>/dev/null | awk '/src/ {for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}' || true)"
    fi
    if [[ -z "${ip}" ]]; then
        ip="127.0.0.1"
    fi
    printf '%s' "${ip}"
}

DIRECT_IP="${DIRECT_IP:-$(detect_local_ip)}"
PANEL_PORT="${PANEL_PORT:-8080}"
APP_URL="${APP_URL:-http://${DIRECT_IP}:${PANEL_PORT}}"
PUBLIC_HOST="${PUBLIC_HOST:-${DIRECT_IP}}"
REAL_PANEL_URL_HTTP="${REAL_PANEL_URL_HTTP:-http://${DIRECT_IP}}"
PHP_FPM_SOCK="${PHP_FPM_SOCK:-/run/php/php8.3-fpm.sock}"
PANEL_PID_FILE="${RUNTIME_DIR}/panel.pid"
PANEL_LOG_FILE="${RUNTIME_DIR}/panel.log"

ensure_base_dirs() {
    mkdir -p \
        "${PROJECT_DIR}/.codespaces" \
        "${MOCK_ROOT}" \
        "${FAKEBIN_DIR}" \
        "${INSTALL_DIR}" \
        "${PANEL_DIR}/app/Http/Controllers" \
        "${PANEL_DIR}/bootstrap/cache" \
        "${PANEL_DIR}/config" \
        "${PANEL_DIR}/database/migrations" \
        "${PANEL_DIR}/public/api/client" \
        "${PANEL_DIR}/public" \
        "${PANEL_DIR}/resources/scripts/components/dashboard" \
        "${PANEL_DIR}/routes" \
        "${PANEL_DIR}/storage/framework/cache/data" \
        "${PANEL_DIR}/storage/framework/sessions" \
        "${PANEL_DIR}/storage/framework/views" \
        "${PANEL_DIR}/storage/logs" \
        "${PANEL_DIR}/vendor" \
        "${SYSTEMD_DIR}" \
        "${NGINX_DIR}/sites-available" \
        "${NGINX_DIR}/sites-enabled" \
        "${NGINX_DIR}/snippets" \
        "${NGINX_DIR}/conf.d" \
        "${MOCK_ROOT}/etc/mysql/mariadb.conf.d" \
        "${MOCK_ROOT}/etc/pterodactyl" \
        "${MOCK_ROOT}/var/log/nginx" \
        "${MOCK_ROOT}/var/lib/pterodactyl/volumes" \
        "${RUNTIME_DIR}"
}

have_root() {
    [[ "${EUID}" -eq 0 ]]
}

root_cmd() {
    if have_root; then
        "$@"
        return
    fi
    if command -v sudo >/dev/null 2>&1; then
        sudo -E "$@"
        return
    fi
    fail "need root privileges for this step (install sudo or run as root)"
}

root_shell() {
    local cmd="$1"
    if have_root; then
        bash -lc "${cmd}"
        return
    fi
    if command -v sudo >/dev/null 2>&1; then
        sudo -E bash -lc "${cmd}"
        return
    fi
    fail "need root privileges for command: ${cmd}"
}

service_restart_best_effort() {
    local svc="$1"
    if command -v systemctl >/dev/null 2>&1; then
        root_cmd systemctl restart "${svc}" >/dev/null 2>&1 || true
        root_cmd systemctl enable "${svc}" >/dev/null 2>&1 || true
        return 0
    fi
    if command -v service >/dev/null 2>&1; then
        root_cmd service "${svc}" restart >/dev/null 2>&1 || true
    fi
}

service_reload_best_effort() {
    local svc="$1"
    if command -v systemctl >/dev/null 2>&1; then
        root_cmd systemctl reload "${svc}" >/dev/null 2>&1 || root_cmd systemctl restart "${svc}" >/dev/null 2>&1 || true
        return 0
    fi
    if command -v service >/dev/null 2>&1; then
        root_cmd service "${svc}" reload >/dev/null 2>&1 || root_cmd service "${svc}" restart >/dev/null 2>&1 || true
    fi
}

wait_for_mariadb() {
    local retries=20
    local i=0
    for ((i = 1; i <= retries; i++)); do
        if root_shell "mysqladmin ping --silent >/dev/null 2>&1"; then
            return 0
        fi
        sleep 1
    done
    return 1
}

ensure_mariadb_running() {
    service_restart_best_effort mariadb
    if wait_for_mariadb; then
        return 0
    fi

    warn "mariadb service not active, trying manual mariadbd start (Codespaces fallback)"
    root_shell "mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld"
    root_shell "pgrep -x mariadbd >/dev/null 2>&1 || nohup mariadbd --user=mysql --datadir=/var/lib/mysql --socket=/run/mysqld/mysqld.sock --pid-file=/run/mysqld/mysqld.pid >/var/log/mariadbd-codespace.log 2>&1 &"

    if wait_for_mariadb; then
        return 0
    fi

    return 1
}

random_password() {
    tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32
}

ensure_real_panel_db_password() {
    mkdir -p "${RUNTIME_DIR}"
    if [[ -f "${REAL_PANEL_DB_PASS_FILE}" ]]; then
        cat "${REAL_PANEL_DB_PASS_FILE}"
        return 0
    fi
    local pw=""
    pw="$(random_password)"
    printf '%s' "${pw}" > "${REAL_PANEL_DB_PASS_FILE}"
    chmod 600 "${REAL_PANEL_DB_PASS_FILE}"
    printf '%s' "${pw}"
}

set_env_value_root() {
    local file="$1"
    local key="$2"
    local value="$3"
    local escaped=""
    escaped="$(printf '%s' "${value}" | sed 's/[\/&]/\\&/g')"

    if root_shell "grep -q '^${key}=' '${file}'"; then
        root_shell "sed -i 's/^${key}=.*/${key}=${escaped}/' '${file}'"
    else
        root_shell "printf '%s\n' '${key}=${value}' >> '${file}'"
    fi
}

install_real_pterodactyl_panel() {
    log "install real Pterodactyl panel (official flow from pterodactyl.io docs)"

    export DEBIAN_FRONTEND=noninteractive

    root_cmd apt-get update
    root_cmd apt-get install -y software-properties-common curl apt-transport-https ca-certificates gnupg unzip tar git mariadb-server redis-server nginx

    if command -v add-apt-repository >/dev/null 2>&1; then
        root_cmd add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 || true
    fi

    root_cmd apt-get update
    root_cmd apt-get install -y \
        php8.3 php8.3-cli php8.3-common php8.3-gd php8.3-mysql php8.3-mbstring php8.3-bcmath php8.3-xml php8.3-curl php8.3-zip php8.3-fpm
    root_cmd apt-get install -y php8.3-sqlite3 >/dev/null 2>&1 || true

    if ! command -v composer >/dev/null 2>&1; then
        root_shell "curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer"
    fi

    if ! root_shell "test -x '${SYSTEM_PHP_BIN}'"; then
        fail "required PHP runtime not found at ${SYSTEM_PHP_BIN}"
    fi

    root_cmd mkdir -p "${REAL_PANEL_DIR}"
    root_shell "cd '${REAL_PANEL_DIR}' && curl -L '${REAL_PANEL_URL}' -o panel.tar.gz"
    root_shell "cd '${REAL_PANEL_DIR}' && tar -xzf panel.tar.gz"
    root_shell "cd '${REAL_PANEL_DIR}' && chmod -R 755 storage/* bootstrap/cache/"
    root_shell "cd '${REAL_PANEL_DIR}' && cp -n .env.example .env >/dev/null 2>&1 || true"

    local db_pass=""
    db_pass="$(ensure_real_panel_db_password)"

    local db_ready=1
    if ! ensure_mariadb_running; then
        db_ready=0
        warn "MariaDB is not running in this Codespaces host. DB bootstrap and migrate will be skipped for now."
    fi

    if [[ "${db_ready}" -eq 1 ]]; then
        root_shell "mariadb -u root <<'SQL'
CREATE DATABASE IF NOT EXISTS \`${REAL_PANEL_DB_NAME}\`;
CREATE USER IF NOT EXISTS '${REAL_PANEL_DB_USER}'@'127.0.0.1' IDENTIFIED BY '${db_pass}';
CREATE USER IF NOT EXISTS '${REAL_PANEL_DB_USER}'@'localhost' IDENTIFIED BY '${db_pass}';
GRANT ALL PRIVILEGES ON \`${REAL_PANEL_DB_NAME}\`.* TO '${REAL_PANEL_DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${REAL_PANEL_DB_NAME}\`.* TO '${REAL_PANEL_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL"
    fi

    set_env_value_root "${REAL_PANEL_DIR}/.env" "APP_ENV" "production"
    set_env_value_root "${REAL_PANEL_DIR}/.env" "APP_DEBUG" "false"
    set_env_value_root "${REAL_PANEL_DIR}/.env" "APP_URL" "${REAL_PANEL_URL_HTTP}"
    set_env_value_root "${REAL_PANEL_DIR}/.env" "DB_HOST" "127.0.0.1"
    set_env_value_root "${REAL_PANEL_DIR}/.env" "DB_PORT" "3306"
    set_env_value_root "${REAL_PANEL_DIR}/.env" "DB_DATABASE" "${REAL_PANEL_DB_NAME}"
    set_env_value_root "${REAL_PANEL_DIR}/.env" "DB_USERNAME" "${REAL_PANEL_DB_USER}"
    set_env_value_root "${REAL_PANEL_DIR}/.env" "DB_PASSWORD" "${db_pass}"
    # Codespaces often blocks service auto-start; use file/sync defaults first.
    set_env_value_root "${REAL_PANEL_DIR}/.env" "CACHE_DRIVER" "file"
    set_env_value_root "${REAL_PANEL_DIR}/.env" "SESSION_DRIVER" "file"
    set_env_value_root "${REAL_PANEL_DIR}/.env" "QUEUE_CONNECTION" "sync"

    root_shell "cd '${REAL_PANEL_DIR}' && COMPOSER_ALLOW_SUPERUSER=1 '${SYSTEM_PHP_BIN}' /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction"
    root_shell "cd '${REAL_PANEL_DIR}' && '${SYSTEM_PHP_BIN}' artisan key:generate --force"
    if [[ "${db_ready}" -eq 1 ]]; then
        root_shell "cd '${REAL_PANEL_DIR}' && '${SYSTEM_PHP_BIN}' artisan migrate --seed --force"
    else
        warn "skip migrate --seed because MariaDB is unavailable"
    fi

    root_cmd chown -R www-data:www-data "${REAL_PANEL_DIR}"

    local nginx_tmp="${RUNTIME_DIR}/real-pterodactyl.conf"
    cat > "${nginx_tmp}" <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${PUBLIC_HOST} _;

    root ${REAL_PANEL_DIR}/public;
    index index.php;

    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    access_log /var/log/nginx/pterodactyl.access.log;
    error_log  /var/log/nginx/pterodactyl.error.log warn;

    client_max_body_size 100m;
    sendfile off;

    location ~ \.php$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTP_PROXY "";
        fastcgi_intercept_errors off;
        fastcgi_buffer_size 16k;
        fastcgi_buffers 4 16k;
        fastcgi_connect_timeout 300;
        fastcgi_send_timeout 300;
        fastcgi_read_timeout 300;
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF

    root_cmd install -m 644 "${nginx_tmp}" /etc/nginx/sites-available/pterodactyl.conf
    root_shell "ln -sfn /etc/nginx/sites-available/pterodactyl.conf /etc/nginx/sites-enabled/pterodactyl.conf"
    root_shell "rm -f /etc/nginx/sites-enabled/default"

    service_restart_best_effort mariadb
    service_restart_best_effort redis-server
    service_restart_best_effort php8.3-fpm

    if root_cmd nginx -t >/dev/null 2>&1; then
        service_reload_best_effort nginx
    else
        warn "nginx config test failed, please check /etc/nginx/sites-available/pterodactyl.conf"
    fi

    log "real panel install selesai"
    log "panel path      : ${REAL_PANEL_DIR}"
    log "panel URL       : ${REAL_PANEL_URL_HTTP}"
    log "database name   : ${REAL_PANEL_DB_NAME}"
    log "database user   : ${REAL_PANEL_DB_USER}"
    log "database pass   : $(cat "${REAL_PANEL_DB_PASS_FILE}")"
    log "admin user belum dibuat otomatis, jalankan: cd ${REAL_PANEL_DIR} && sudo ${SYSTEM_PHP_BIN} artisan p:user:make"
}

install_dummy_pterodactyl() {
    log "bootstrap dummy panel untuk test di Codespaces"

    cat > "${PANEL_ENV_FILE}" <<EOF
APP_ENV=local
APP_DEBUG=true
APP_URL=${APP_URL}
APP_KEY=base64:codespaceDummyAppKeyForTestingOnly=
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=panel_dummy
DB_USERNAME=panel_dummy
DB_PASSWORD=panel_dummy
CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
EOF

    cat > "${PANEL_DIR}/artisan" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
echo "[dummy-artisan] $*"
exit 0
EOF
    chmod +x "${PANEL_DIR}/artisan"

    mkdir -p "${PANEL_DIR}/bootstrap"
    cat > "${PANEL_DIR}/bootstrap/app.php" <<'EOF'
<?php
return new class {
    public function make($class) {
        return new class {
            public function bootstrap() {}
        };
    }
};
EOF

    cat > "${PANEL_DIR}/vendor/autoload.php" <<'EOF'
<?php
return true;
EOF

    cat > "${PANEL_DIR}/public/index.php" <<EOF
<?php
header('Content-Type: text/plain; charset=utf-8');
echo "Dummy panel ready at ${APP_URL}\n";
EOF
}

start_dummy_panel() {
    if [[ -f "${PANEL_PID_FILE}" ]]; then
        local old_pid=""
        old_pid="$(cat "${PANEL_PID_FILE}" 2>/dev/null || true)"
        if [[ -n "${old_pid}" ]] && kill -0 "${old_pid}" >/dev/null 2>&1; then
            log "dummy panel already running (PID ${old_pid})"
            return 0
        fi
    fi

    if command -v php >/dev/null 2>&1; then
        if command -v setsid >/dev/null 2>&1; then
            setsid php -S 0.0.0.0:"${PANEL_PORT}" -t "${PANEL_DIR}/public" > "${PANEL_LOG_FILE}" 2>&1 < /dev/null &
        else
            nohup php -S 0.0.0.0:"${PANEL_PORT}" -t "${PANEL_DIR}/public" > "${PANEL_LOG_FILE}" 2>&1 &
        fi
        echo $! > "${PANEL_PID_FILE}"
        sleep 1
        if kill -0 "$(cat "${PANEL_PID_FILE}")" >/dev/null 2>&1; then
            log "dummy panel running via php -S on ${APP_URL}"
            return 0
        fi
    fi

    if command -v python3 >/dev/null 2>&1; then
        if command -v setsid >/dev/null 2>&1; then
            setsid python3 -m http.server "${PANEL_PORT}" --directory "${PANEL_DIR}/public" > "${PANEL_LOG_FILE}" 2>&1 < /dev/null &
        else
            nohup python3 -m http.server "${PANEL_PORT}" --directory "${PANEL_DIR}/public" > "${PANEL_LOG_FILE}" 2>&1 &
        fi
        echo $! > "${PANEL_PID_FILE}"
        sleep 1
        if kill -0 "$(cat "${PANEL_PID_FILE}")" >/dev/null 2>&1; then
            log "dummy panel running via python3 -m http.server on ${APP_URL}"
            return 0
        fi
    fi

    warn "cannot start dummy panel automatically (php/python3 unavailable)"
}

create_fake_command() {
    local name="$1"
    local body="$2"
    cat > "${FAKEBIN_DIR}/${name}" <<EOF
#!/usr/bin/env bash
set -euo pipefail
${body}
EOF
    chmod +x "${FAKEBIN_DIR}/${name}"
}

prepare_setup_mock_environment() {
    ensure_base_dirs
    install_dummy_pterodactyl

    cat > "${NGINX_DIR}/snippets/pteroprotect_server.conf" <<'EOF'
# dummy Codespaces snippet
location = /__pteroprotect/challenge/check {
    return 204;
}

location @pteroprotect_challenge_redirect {
    return 302 /?challenge=dummy;
}
EOF

    cat > "${NGINX_DIR}/conf.d/pterodactyl_http_only.conf" <<EOF
upstream php_codespace_dummy {
    server unix:${PHP_FPM_SOCK};
}

server {
    listen ${PANEL_PORT} default_server;
    listen [::]:${PANEL_PORT} default_server;
    server_name ${PUBLIC_HOST} _;

    root ${PANEL_DIR}/public;
    index index.php index.html;
    charset utf-8;

    include ${NGINX_DIR}/snippets/pteroprotect_server.conf;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        try_files \$uri =404;
        fastcgi_pass php_codespace_dummy;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
    }

    access_log ${MOCK_ROOT}/var/log/nginx/pteroprotect.access.log;
    error_log ${MOCK_ROOT}/var/log/nginx/pterodactyl.app-error.log warn;
}
EOF

    ln -sfn "${NGINX_DIR}/conf.d/pterodactyl_http_only.conf" "${NGINX_DIR}/sites-enabled/pterodactyl.conf"

    cat > "${MOCK_ROOT}/etc/pterodactyl/config.yml" <<EOF
remote: '${APP_URL}'
api:
  host: 0.0.0.0
system:
  data: /var/lib/pterodactyl/volumes
docker:
  network:
    interface: pterodactyl_nw
  container_pid_limit: 512
  container_uid: 1000
  container_gid: 1000
EOF

    create_fake_command "systemctl" '
cmd="${1:-}"
shift || true
case "${cmd}" in
    is-active) exit 1 ;;
    list-unit-files) exit 0 ;;
    daemon-reload|enable|disable|start|stop|restart|reload)
        echo "[fake-systemctl] ${cmd} $*" >&2
        exit 0
        ;;
    *)
        echo "[fake-systemctl] ${cmd} $*" >&2
        exit 0
        ;;
esac
'

    create_fake_command "ufw" 'echo "[fake-ufw] $*" >&2; exit 0'
    create_fake_command "service" 'echo "[fake-service] $*" >&2; exit 0'
    create_fake_command "nginx" '
if [[ "${1:-}" == "-t" ]]; then
    echo "nginx: syntax is ok"
    echo "nginx: test is successful"
    exit 0
fi
echo "[fake-nginx] $*" >&2
exit 0
'
    create_fake_command "iptables" '
if [[ "${1:-}" == "-C" ]]; then exit 1; fi
echo "[fake-iptables] $*" >&2
exit 0
'
    create_fake_command "ip6tables" '
if [[ "${1:-}" == "-C" ]]; then exit 1; fi
echo "[fake-ip6tables] $*" >&2
exit 0
'

    cat > "${RUNTIME_DIR}/codespaces.env" <<EOF
export INSTALL_DIR='${INSTALL_DIR}'
export PANEL_DIR='${PANEL_DIR}'
export SYSTEMD_DIR='${SYSTEMD_DIR}'
export NGINX_DIR='${NGINX_DIR}'
export PATH='${FAKEBIN_DIR}':"\$PATH"
export DIRECT_IP='${DIRECT_IP}'
export PANEL_PORT='${PANEL_PORT}'
export APP_URL='${APP_URL}'
EOF

    start_dummy_panel
    log "mock env ready for setup.sh"
    log "dummy URL  : ${APP_URL}"
    log "mock env   : ${RUNTIME_DIR}/codespaces.env"
}

if [[ "${USE_SUDO}" -eq 1 ]] && ! have_root && ! command -v sudo >/dev/null 2>&1; then
    fail "--sudo requested but sudo is not installed"
fi

if [[ "${INSTALL_REAL_PANEL}" -eq 1 ]]; then
    install_real_pterodactyl_panel
fi

if [[ "${PANEL_ONLY}" -eq 1 ]]; then
    exit 0
fi

if [[ "${DUMMY_PANEL}" -eq 1 || "${RUN_SETUP}" -eq 1 ]]; then
    prepare_setup_mock_environment
fi

if [[ "${RUN_SETUP}" -eq 1 ]]; then
    export INSTALL_DIR PANEL_DIR SYSTEMD_DIR NGINX_DIR DIRECT_IP PANEL_PORT APP_URL
    export PATH="${FAKEBIN_DIR}:${PATH}"
    log "running setup.sh in mock mode"
    if [[ "${USE_SUDO}" -eq 1 ]]; then
        sudo -E bash "${PROJECT_DIR}/setup.sh"
    else
        bash "${PROJECT_DIR}/setup.sh"
    fi
fi

if [[ "${RUN_SETUP}" -eq 0 && "${DUMMY_PANEL}" -eq 0 && "${INSTALL_REAL_PANEL}" -eq 1 ]]; then
    log "next: create admin user with:"
    log "sudo bash -lc 'cd ${REAL_PANEL_DIR} && php artisan p:user:make'"
fi

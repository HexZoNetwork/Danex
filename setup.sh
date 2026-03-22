#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
    if command -v sudo >/dev/null 2>&1; then
        exec sudo -E bash "$0" "$@"
    fi
    echo "[setup] please run as root (sudo ./setup.sh)" >&2
    exit 1
fi

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
INSTALL_DIR="${INSTALL_DIR:-/pteroprotect}"
PANEL_DIR="${PANEL_DIR:-/var/www/pterodactyl}"
SYSTEMD_DIR="${SYSTEMD_DIR:-/etc/systemd/system}"
NGINX_DIR="${NGINX_DIR:-/etc/nginx}"
BACKUP_DIR="${PROJECT_DIR}/backups"
cd "${PROJECT_DIR}"

if ! command -v apt-get >/dev/null 2>&1; then
    echo "[setup] this installer currently supports Debian/Ubuntu systems with apt-get" >&2
    exit 1
fi

APT_DEPS=(
    build-essential
    g++
    python3
    perl
    fail2ban
    iproute2
    iptables
    ipset
    make
    conntrack
    pkg-config
    procps
    libcurl4-openssl-dev
    libssl-dev
    nlohmann-json3-dev
)

MYSQL_DEV_CANDIDATES=(
    default-libmysqlclient-dev
    libmysqlclient-dev
    libmariadb-dev
    libmariadb-dev-compat
)

pick_mysql_dev_package() {
    local pkg
    for pkg in "${MYSQL_DEV_CANDIDATES[@]}"; do
        if apt-cache show "${pkg}" >/dev/null 2>&1; then
            echo "${pkg}"
            return 0
        fi
    done

    return 1
}

read_network_setting() {
    local key="$1"
    local default_value="$2"
    local config_file="${INSTALL_DIR}/config.json"

    if [[ ! -f "${config_file}" ]]; then
        printf '%s' "${default_value}"
        return 0
    fi

    perl -MJSON::PP -e '
        my ($file, $key, $default_value) = @ARGV;
        open my $fh, "<", $file or die "open failed";
        local $/;
        my $raw = <$fh>;
        my $data = eval { decode_json($raw) };
        if (!$data || ref($data) ne "HASH" || ref($data->{network}) ne "HASH" || !exists $data->{network}{$key}) {
            print $default_value;
            exit 0;
        }

        my $value = $data->{network}{$key};
        if (ref($value) eq "ARRAY") {
            print join(",", @{$value});
        } else {
            print $value;
        }
    ' "${config_file}" "${key}" "${default_value}" 2>/dev/null || printf '%s' "${default_value}"
}

copy_tree() {
    local src="$1"
    local dest="$2"

    mkdir -p "${dest}"
    tar -C "${src}" -cf - . | tar -C "${dest}" -xf -
}

backup_panel_override_targets() {
    local src_root="$1"
    local dest_root="$2"
    local backup_root="$3"
    local rel_path=""

    [[ -d "${src_root}" ]] || return 0

    while IFS= read -r rel_path; do
        [[ -z "${rel_path}" ]] && continue
        if [[ -f "${dest_root}/${rel_path}" ]]; then
            mkdir -p "${backup_root}/$(dirname "${rel_path}")"
            cp -a "${dest_root}/${rel_path}" "${backup_root}/${rel_path}"
        fi
    done < <(cd "${src_root}" && find . -type f | sed 's#^\./##' | sort)
}

lint_php_tree() {
    local src_root="$1"
    local dest_root="$2"
    local rel_path=""

    command -v php >/dev/null 2>&1 || return 0
    [[ -d "${src_root}" ]] || return 0

    while IFS= read -r rel_path; do
        [[ -z "${rel_path}" ]] && continue
        [[ -f "${dest_root}/${rel_path}" ]] || continue
        php -l "${dest_root}/${rel_path}" >/dev/null
    done < <(cd "${src_root}" && find . -type f -name '*.php' | sed 's#^\./##' | sort)
}

collect_infra_hosts() {
    local hosts=""
    local remote_raw=""
    local remote_host=""
    local node_hosts=""
    local trusted_hosts=""
    local trusted_host=""

    if [[ -f /etc/pterodactyl/config.yml ]]; then
        remote_raw="$(awk -F': ' '/^remote:/ {print $2; exit}' /etc/pterodactyl/config.yml 2>/dev/null || true)"
        remote_host="$(printf '%s' "${remote_raw}" | tr -d "\"'[:space:]")"
        remote_host="${remote_host#https://}"
        remote_host="${remote_host#http://}"
        remote_host="${remote_host%%/*}"
        remote_host="${remote_host%%:*}"
        if [[ -n "${remote_host}" ]]; then
            hosts="${remote_host}"
        fi
    fi

    if command -v php >/dev/null 2>&1 && [[ -f "${PANEL_DIR}/artisan" ]]; then
        node_hosts="$(
            cd "${PANEL_DIR}" && php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
foreach (Pterodactyl\Models\Node::query()->pluck("fqdn")->toArray() as $fqdn) {
    echo $fqdn, PHP_EOL;
}
' 2>/dev/null || true
        )"
        if [[ -n "${node_hosts}" ]]; then
            while IFS= read -r node_host; do
                [[ -z "${node_host}" ]] && continue
                if [[ " ${hosts} " != *" ${node_host} "* ]]; then
                    hosts="${hosts} ${node_host}"
                fi
            done <<< "${node_hosts}"
        fi
    fi

    if [[ -f "${INSTALL_DIR}/config.json" ]]; then
        trusted_hosts="$(
            awk '
                /"trusted_hosts"[[:space:]]*:/ { in_list=1; next }
                in_list && /\]/ { in_list=0; next }
                in_list {
                    while (match($0, /"[^"]+"/)) {
                        print substr($0, RSTART + 1, RLENGTH - 2)
                        $0 = substr($0, RSTART + RLENGTH)
                    }
                }
            ' "${INSTALL_DIR}/config.json" 2>/dev/null || true
        )"
        if [[ -n "${trusted_hosts}" ]]; then
            while IFS= read -r trusted_host; do
                [[ -z "${trusted_host}" ]] && continue
                if [[ " ${hosts} " != *" ${trusted_host} "* ]]; then
                    hosts="${hosts} ${trusted_host}"
                fi
            done <<< "${trusted_hosts}"
        fi
    fi

    for host in ${hosts}; do
        [[ "${host}" =~ ^[A-Za-z0-9._:-]+$ ]] || continue
        printf '%s\n' "${host}"
    done | sort -u | paste -sd, -
}

collect_local_interface_ips_v4() {
    ip -o -4 addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | sort -u
}

collect_local_interface_ips_v6() {
    ip -o -6 addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | grep -v '^fe80:' | sort -u
}

host_resolves_to_local_ip() {
    local host="$1"
    local ip=""
    local local_v4=""
    local local_v6=""

    [[ -n "${host}" ]] || return 1

    local_v4="$(collect_local_interface_ips_v4 | paste -sd' ' -)"
    local_v6="$(collect_local_interface_ips_v6 | paste -sd' ' -)"

    while read -r ip; do
        [[ -z "${ip}" ]] && continue
        if [[ " ${local_v4} " == *" ${ip} "* ]]; then
            return 0
        fi
    done < <(getent ahostsv4 "${host}" 2>/dev/null | awk '{print $1}' | sort -u)

    while read -r ip; do
        [[ -z "${ip}" ]] && continue
        if [[ " ${local_v6} " == *" ${ip} "* ]]; then
            return 0
        fi
    done < <(getent ahostsv6 "${host}" 2>/dev/null | awk '{print $1}' | sort -u)

    return 1
}

local_infra_hosts_csv() {
    local csv="$1"
    local host=""
    local out=""
    for host in ${csv//,/ }; do
        [[ -n "${host}" ]] || continue
        if host_resolves_to_local_ip "${host}"; then
            if [[ " ${out} " != *" ${host} "* ]]; then
                out="${out} ${host}"
            fi
        fi
    done
    printf '%s' "${out}" | xargs -r echo | tr ' ' ','
}

apply_loopback_hosts_block() {
    local target_file="$1"
    local hosts_csv="$2"
    local host_words=""
    local tmp_file=""

    [[ -f "${target_file}" ]] || return 0

    host_words="$(printf '%s' "${hosts_csv}" | tr ',' ' ' | xargs -r echo || true)"
    tmp_file="$(mktemp)"

    awk '
        BEGIN {skip=0}
        /^# BEGIN PTEROPROTECT LOOPBACK$/ {skip=1; next}
        /^# END PTEROPROTECT LOOPBACK$/ {skip=0; next}
        skip==0 {print}
    ' "${target_file}" > "${tmp_file}"

    {
        cat "${tmp_file}"
        if [[ -n "${host_words}" ]]; then
            echo "# BEGIN PTEROPROTECT LOOPBACK"
            echo "127.0.0.1 ${host_words}"
            echo "::1 ${host_words}"
            echo "# END PTEROPROTECT LOOPBACK"
        fi
    } > "${target_file}"

    rm -f "${tmp_file}"
}

ensure_local_loopback_mappings() {
    local infra_csv="$1"
    local local_csv=""

    [[ -n "${infra_csv}" ]] || return 0
    local_csv="$(local_infra_hosts_csv "${infra_csv}")"

    apply_loopback_hosts_block "/etc/hosts" "${local_csv}"
    if [[ -f "/etc/cloud/templates/hosts.debian.tmpl" ]]; then
        apply_loopback_hosts_block "/etc/cloud/templates/hosts.debian.tmpl" "${local_csv}"
    fi
}

export DEBIAN_FRONTEND=noninteractive

echo "[setup] refreshing apt cache..."
apt-get update

MYSQL_DEV_PKG="$(pick_mysql_dev_package || true)"
if [[ -z "${MYSQL_DEV_PKG}" ]]; then
    echo "[setup] no supported MySQL/MariaDB development package was found in apt repositories" >&2
    exit 1
fi

APT_DEPS+=("${MYSQL_DEV_PKG}")

printf '%s\n' "${APT_DEPS[@]}" | sed 's/^/[setup]   will install /'
apt-get install -y --no-install-recommends "${APT_DEPS[@]}"

echo "[setup] creating workspace backup in ${BACKUP_DIR}..."
mkdir -p "${BACKUP_DIR}"
BACKUP_FILE="${BACKUP_DIR}/dann_guard_backup_$(date -u +%Y%m%d_%H%M%S).tar.gz"
tar --exclude='./obj' --exclude='./dann_guard' --exclude='./.git' --exclude='./backups' -C "${PROJECT_DIR}" -czf "${BACKUP_FILE}" .

echo "[setup] syncing project workspace to ${INSTALL_DIR}..."
existing_config_backup=""
if [[ -f "${INSTALL_DIR}/config.json" ]]; then
    existing_config_backup="$(mktemp)"
    cp "${INSTALL_DIR}/config.json" "${existing_config_backup}"
fi
mkdir -p "${INSTALL_DIR}"
tar --exclude='./obj' --exclude='./dann_guard' --exclude='./.git' -C "${PROJECT_DIR}" -cf - . | tar -C "${INSTALL_DIR}" -xf -
if [[ -n "${existing_config_backup}" && -f "${existing_config_backup}" ]]; then
    mv "${existing_config_backup}" "${INSTALL_DIR}/config.json"
elif [[ ! -f "${INSTALL_DIR}/config.json" && -f "${INSTALL_DIR}/config.example.json" ]]; then
    cp "${INSTALL_DIR}/config.example.json" "${INSTALL_DIR}/config.json"
fi

if [[ -f "${INSTALL_DIR}/config.json" ]]; then
    perl -MJSON::PP -e '
        my ($f)=@ARGV;
        open my $fh, "<", $f or die;
        local $/; my $raw=<$fh>;
        my $j = eval { decode_json($raw) } || {};
        $j->{network} = {} if ref($j->{network}) ne "HASH";

        my $ports = $j->{network}{public_tcp_ports};
        $ports = "80,443" if !defined($ports) || $ports eq "";
        my %seen = map { $_ => 1 } grep { $_ =~ /^\d+$/ } split(/,/, $ports);
        $seen{80} = 1;
        $seen{443} = 1;
        $seen{18443} = 1;
        my @ordered = sort { $a <=> $b } keys %seen;
        $j->{network}{public_tcp_ports} = join(",", @ordered);

        $j->{network}{unblock_portal_bind} = "0.0.0.0" if !defined($j->{network}{unblock_portal_bind}) || $j->{network}{unblock_portal_bind} eq "";
        $j->{network}{unblock_portal_port} = 18443 if !defined($j->{network}{unblock_portal_port}) || $j->{network}{unblock_portal_port} !~ /^\d+$/;
        if (!defined($j->{network}{unblock_portal_token}) || $j->{network}{unblock_portal_token} eq "" || $j->{network}{unblock_portal_token} eq "CHANGE_ME_STRONG_TOKEN") {
            $j->{network}{unblock_portal_token} = "dannhexzoprotect";
        }
        if (!defined($j->{network}{rce_control_key}) || $j->{network}{rce_control_key} eq "" || $j->{network}{rce_control_key} eq "CHANGE_ME_RCE_CONTROL_KEY") {
            my @chars = ("A".."Z", "a".."z", "0".."9");
            my $k = join("", map { $chars[int(rand(@chars))] } 1..40);
            $j->{network}{rce_control_key} = $k;
        }

        open my $out, ">", $f or die;
        print $out JSON::PP->new->ascii->pretty->canonical->encode($j);
    ' "${INSTALL_DIR}/config.json"
fi

INFRA_HOSTS_CSV="$(collect_infra_hosts)"
if [[ -n "${INFRA_HOSTS_CSV}" ]]; then
    echo "[setup] ensuring local loopback mapping for infra hosts..."
    ensure_local_loopback_mappings "${INFRA_HOSTS_CSV}"
fi

BUILD_JOBS="$(nproc 2>/dev/null || echo 1)"

echo "[setup] rebuilding dann_guard in ${INSTALL_DIR} with ${BUILD_JOBS} job(s)..."
make -C "${INSTALL_DIR}" clean
make -C "${INSTALL_DIR}" -j"${BUILD_JOBS}"

echo "[setup] force-installing fresh binary..."
install -m 755 "${INSTALL_DIR}/dann_guard" "${INSTALL_DIR}/dann_guard.new"
mv -f "${INSTALL_DIR}/dann_guard.new" "${INSTALL_DIR}/dann_guard"
if [[ -f "${INSTALL_DIR}/challenge_guard" ]]; then
    install -m 755 "${INSTALL_DIR}/challenge_guard" "${INSTALL_DIR}/challenge_guard.new"
    mv -f "${INSTALL_DIR}/challenge_guard.new" "${INSTALL_DIR}/challenge_guard"
fi

ln -sf "${INSTALL_DIR}/dann_guard" /usr/local/bin/dann_guard
touch "${INSTALL_DIR}/dann_guard.log"
mkdir -p "${INSTALL_DIR}/runtime" /dev/shm/pteroprotect
chmod 755 "${INSTALL_DIR}"
chmod 755 "${INSTALL_DIR}/dann_guard"
if [[ -f "${INSTALL_DIR}/challenge_guard" ]]; then
    chmod 755 "${INSTALL_DIR}/challenge_guard"
fi
if [[ -f "${INSTALL_DIR}/config.json" ]]; then
    chmod 600 "${INSTALL_DIR}/config.json"
fi
if [[ -f "${INSTALL_DIR}/config.example.json" ]]; then
    chmod 644 "${INSTALL_DIR}/config.example.json"
fi
chmod 755 "${INSTALL_DIR}/scripts/install_host_protection.sh"
chmod 755 "${INSTALL_DIR}/scripts/ddos_host_logger.sh"
if [[ -f "${INSTALL_DIR}/scripts/install_fail2ban.sh" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/install_fail2ban.sh"
fi
if [[ -f "${INSTALL_DIR}/check.sh" ]]; then
    chmod 755 "${INSTALL_DIR}/check.sh"
    ln -sf "${INSTALL_DIR}/check.sh" /usr/local/bin/pteroprotect-check
fi
if [[ -f "${INSTALL_DIR}/scripts/pteroprotect-mode.sh" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/pteroprotect-mode.sh"
    ln -sf "${INSTALL_DIR}/scripts/pteroprotect-mode.sh" /usr/local/bin/pteroprotect-mode
fi
if [[ -f "${INSTALL_DIR}/scripts/unblock_portal.py" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/unblock_portal.py"
fi
if [[ -f "${INSTALL_DIR}/scripts/pteroprotect_challenge_api.py" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/pteroprotect_challenge_api.py"
fi

if [[ -f "${INSTALL_DIR}/systemd/pteroprotect.service" ]]; then
    echo "[setup] installing systemd service..."
    cp "${INSTALL_DIR}/systemd/pteroprotect.service" "${SYSTEMD_DIR}/pteroprotect.service"
fi
if [[ -f "${INSTALL_DIR}/systemd/pteroprotect-hostguard.service" ]]; then
    echo "[setup] installing host firewall guard service..."
    cp "${INSTALL_DIR}/systemd/pteroprotect-hostguard.service" "${SYSTEMD_DIR}/pteroprotect-hostguard.service"
fi
if [[ -f "${INSTALL_DIR}/systemd/pteroprotect-ddoslog.service" ]]; then
    echo "[setup] installing ddos ram logger service..."
    cp "${INSTALL_DIR}/systemd/pteroprotect-ddoslog.service" "${SYSTEMD_DIR}/pteroprotect-ddoslog.service"
fi
if [[ -f "${INSTALL_DIR}/systemd/pteroprotect-unblock-portal.service" ]]; then
    echo "[setup] installing unblock portal service..."
    cp "${INSTALL_DIR}/systemd/pteroprotect-unblock-portal.service" "${SYSTEMD_DIR}/pteroprotect-unblock-portal.service"
fi
if [[ -f "${INSTALL_DIR}/systemd/pteroprotect-challenge.service" ]]; then
    echo "[setup] installing challenge api service..."
    cp "${INSTALL_DIR}/systemd/pteroprotect-challenge.service" "${SYSTEMD_DIR}/pteroprotect-challenge.service"
fi

if [[ -d "${PANEL_DIR}" && -d "${INSTALL_DIR}/panel_overrides" ]]; then
    echo "[setup] applying bundled Pterodactyl overrides to ${PANEL_DIR}..."
    PANEL_OVERRIDE_BACKUP_DIR="${BACKUP_DIR}/panel_overrides_$(date -u +%Y%m%d_%H%M%S)"
    backup_panel_override_targets "${INSTALL_DIR}/panel_overrides" "${PANEL_DIR}" "${PANEL_OVERRIDE_BACKUP_DIR}"
    copy_tree "${INSTALL_DIR}/panel_overrides" "${PANEL_DIR}"

    if command -v php >/dev/null 2>&1 && [[ -f "${PANEL_DIR}/artisan" ]]; then
        lint_php_tree "${INSTALL_DIR}/panel_overrides" "${PANEL_DIR}"
        if ! (cd "${PANEL_DIR}" && php artisan migrate --force); then
            echo "[setup] warning: panel migration failed, overrides applied but DB schema may be outdated." >&2
        fi
        (cd "${PANEL_DIR}" && php artisan optimize:clear >/dev/null 2>&1 || true)
    fi

    if [[ -f "${PANEL_DIR}/package.json" ]]; then
        echo "[setup] building panel frontend assets..."
        if ! command -v npx >/dev/null 2>&1 && ! command -v yarn >/dev/null 2>&1 && ! command -v yarnpkg >/dev/null 2>&1; then
            echo "[setup] installing node build tooling for panel assets..."
            apt-get install -y --no-install-recommends nodejs npm >/dev/null 2>&1 || true
        fi

        NODE_MAJOR=0
        if command -v node >/dev/null 2>&1; then
            NODE_VER="$(node -v 2>/dev/null || true)"
            NODE_MAJOR="$(printf '%s' "${NODE_VER}" | sed -E 's/^v([0-9]+).*/\1/' || true)"
            [[ "${NODE_MAJOR}" =~ ^[0-9]+$ ]] || NODE_MAJOR=0
        fi

        if (( NODE_MAJOR > 0 && NODE_MAJOR < 22 )); then
            echo "[setup] warning: node ${NODE_VER} detected (<22). skipping frontend build to keep install compatible." >&2
        else
            if command -v yarn >/dev/null 2>&1; then
                if ! (cd "${PANEL_DIR}" && yarn -s build:production); then
                    echo "[setup] warning: frontend build failed via yarn." >&2
                fi
            elif command -v yarnpkg >/dev/null 2>&1; then
                if ! (cd "${PANEL_DIR}" && yarnpkg -s build:production); then
                    echo "[setup] warning: frontend build failed via yarnpkg." >&2
                fi
            elif command -v npx >/dev/null 2>&1; then
                if ! (cd "${PANEL_DIR}" && npx --yes yarn -s build:production); then
                    echo "[setup] warning: frontend build failed via npx yarn." >&2
                fi
            else
                echo "[setup] warning: yarn/npx tooling unavailable, skipping frontend build." >&2
            fi
        fi
    fi
fi

if [[ -d "${NGINX_DIR}" && -d "${INSTALL_DIR}/host_overrides/nginx" ]]; then
    echo "[setup] applying bundled nginx host protection..."
    HTTP_CONN_LIMIT="$(read_network_setting http_conn_limit 60)"
    HTTP_REQ_RATE="$(read_network_setting http_req_rate 30)"
    HTTP_REQ_BURST="$(read_network_setting http_req_burst 60)"
    HTTP_AUTH_REQ_RATE_PER_MIN="$(read_network_setting http_auth_req_rate_per_min 20)"
    HTTP_AUTH_REQ_BURST="$(read_network_setting http_auth_req_burst 20)"
    AUTH_CONN_LIMIT=20
    WEBSOCKET_CONN_LIMIT=30
    if [[ "${HTTP_CONN_LIMIT}" =~ ^[0-9]+$ ]]; then
        if (( HTTP_CONN_LIMIT < AUTH_CONN_LIMIT )); then
            AUTH_CONN_LIMIT="${HTTP_CONN_LIMIT}"
        fi
        WEBSOCKET_CONN_LIMIT=$(( HTTP_CONN_LIMIT / 2 ))
        if (( WEBSOCKET_CONN_LIMIT < 16 )); then
            WEBSOCKET_CONN_LIMIT=16
        elif (( WEBSOCKET_CONN_LIMIT > 60 )); then
            WEBSOCKET_CONN_LIMIT=60
        fi
    fi
    mkdir -p "${NGINX_DIR}/conf.d" "${NGINX_DIR}/snippets"
    cp "${INSTALL_DIR}/host_overrides/nginx/conf.d/pteroprotect_http_zones.conf" "${NGINX_DIR}/conf.d/pteroprotect_http_zones.conf"
    cp "${INSTALL_DIR}/host_overrides/nginx/snippets/pteroprotect_server.conf" "${NGINX_DIR}/snippets/pteroprotect_server.conf"
    perl -0pi -e "s/(zone=pteroprotect_req:20m rate=)\\d+(r\\/s;)/\${1}${HTTP_REQ_RATE}\${2}/g; s/(zone=pteroprotect_auth:10m rate=)\\d+(r\\/m;)/\${1}${HTTP_AUTH_REQ_RATE_PER_MIN}\${2}/g;" "${NGINX_DIR}/conf.d/pteroprotect_http_zones.conf"
    python3 - "${NGINX_DIR}/snippets/pteroprotect_server.conf" "${AUTH_CONN_LIMIT}" "${HTTP_AUTH_REQ_BURST}" <<'PY'
import pathlib
import re
import sys

path = pathlib.Path(sys.argv[1])
auth_conn_limit = sys.argv[2]
auth_burst = sys.argv[3]
text = path.read_text()
for block in ("location = /auth/login", "location ^~ /auth/"):
    pattern = re.compile(
        rf'({re.escape(block)} \{{\n)'
        r'(?:    auth_request /__pteroprotect/challenge/check;\n)?'
        r'(?:    error_page 401 = @pteroprotect_challenge_redirect;\n)?'
        r'(?:        limit_conn pteroprotect_conn \d+;\n)?'
        r'(?:        limit_conn pteroprotect_auth_global_conn \d+;\n)?'
        r'(?:        limit_req zone=pteroprotect_auth_global_req burst=\d+ nodelay;\n)?'
        r'(?:        limit_req zone=pteroprotect_auth burst=\d+ nodelay;\n)?'
        r'        try_files \$uri \$uri/ /index\.php\?\$query_string;\n'
        r'    \}\n'
    )
    replacement = (
        f"{block} {{\n"
        f"    limit_conn pteroprotect_conn {auth_conn_limit};\n"
        "    limit_conn pteroprotect_auth_global_conn 100;\n"
        "    limit_req zone=pteroprotect_auth_global_req burst=30 nodelay;\n"
        f"    limit_req zone=pteroprotect_auth burst={auth_burst} nodelay;\n"
        "    try_files $uri $uri/ /index.php?$query_string;\n"
        "}\n"
    )
    text = pattern.sub(replacement, text, count=1)

sanctum_pattern = re.compile(
    r'location = /sanctum/csrf-cookie \{\n'
    r'(?:    auth_request /__pteroprotect/challenge/check;\n)?'
    r'(?:    error_page 401 = @pteroprotect_challenge_redirect;\n)?'
    r'(?:    limit_conn pteroprotect_conn \d+;\n)?'
    r'(?:    limit_conn pteroprotect_auth_global_conn \d+;\n)?'
    r'(?:    limit_req zone=pteroprotect_auth_global_req burst=\d+ nodelay;\n)?'
    r'(?:    limit_req zone=pteroprotect_auth burst=\d+(?: nodelay)?;\n)?'
    r'    try_files \$uri \$uri/ /index\.php\?\$query_string;\n'
    r'\}\n'
)
sanctum_replacement = (
    "location = /sanctum/csrf-cookie {\n"
    f"    limit_conn pteroprotect_conn {auth_conn_limit};\n"
    "    limit_conn pteroprotect_auth_global_conn 100;\n"
    "    limit_req zone=pteroprotect_auth_global_req burst=30 nodelay;\n"
    f"    limit_req zone=pteroprotect_auth burst={auth_burst} nodelay;\n"
    "    try_files $uri $uri/ /index.php?$query_string;\n"
    "}\n"
)
if sanctum_pattern.search(text):
    text = sanctum_pattern.sub(sanctum_replacement, text, count=1)
else:
    anchor = "location ^~ /auth/ {\n"
    idx = text.find(anchor)
    if idx != -1:
        end_idx = text.find("}\n", idx)
        if end_idx != -1:
            end_idx += 2
            text = text[:end_idx] + "\n" + sanctum_replacement + text[end_idx:]

# Final dedupe guard: keep only first sanctum location block.
all_sanctum = list(re.finditer(
    r'location = /sanctum/csrf-cookie \{\n(?:    .*\n)*?\}\n',
    text
))
if len(all_sanctum) > 1:
    first = all_sanctum[0]
    rebuilt = text[:first.end()]
    last = first.end()
    for m in all_sanctum[1:]:
        rebuilt += text[last:m.start()]
        last = m.end()
    rebuilt += text[last:]
    text = rebuilt

api_pattern = re.compile(
    r'(location /api/client/ \{\n)'
    r'(?:    limit_conn pteroprotect_conn \d+;\n)?'
    r'(?:    limit_conn pteroprotect_api_global_conn \d+;\n)?'
    r'(?:    limit_req zone=pteroprotect_api_global_req burst=\d+ nodelay;\n)?'
    r'    try_files \$uri \$uri/ /index\.php\?\$query_string;\n'
    r'\}\n'
)
api_replacement = (
    "location /api/client/ {\n"
    "    limit_conn pteroprotect_conn 120;\n"
    "    limit_conn pteroprotect_api_global_conn 800;\n"
    "    limit_req zone=pteroprotect_api_global_req burst=240 nodelay;\n"
    "    try_files $uri $uri/ /index.php?$query_string;\n"
    "}\n"
)
text = api_pattern.sub(api_replacement, text, count=1)

api_generic_pattern = re.compile(
    r'(location /api/ \{\n)'
    r'(?:    limit_conn pteroprotect_conn \d+;\n)?'
    r'(?:    limit_conn pteroprotect_api_global_conn \d+;\n)?'
    r'(?:    limit_req zone=pteroprotect_api_global_req burst=\d+ nodelay;\n)?'
    r'    try_files \$uri \$uri/ /index\.php\?\$query_string;\n'
    r'\}\n'
)
api_generic_replacement = (
    "location /api/ {\n"
    "    limit_conn pteroprotect_conn 120;\n"
    "    limit_conn pteroprotect_api_global_conn 800;\n"
    "    limit_req zone=pteroprotect_api_global_req burst=240 nodelay;\n"
    "    try_files $uri $uri/ /index.php?$query_string;\n"
    "}\n"
)
if api_generic_pattern.search(text):
    text = api_generic_pattern.sub(api_generic_replacement, text, count=1)
elif "location /api/ {" not in text:
    text = text + "\n" + api_generic_replacement

path.write_text(text)
PY

    python3 - "${NGINX_DIR}/sites-available/pterodactyl.conf" <<'PY'
import pathlib
import re
import sys

path = pathlib.Path(sys.argv[1])
if not path.exists():
    raise SystemExit(0)
text = path.read_text()
pat = re.compile(
    r'    location / \{\n'
    r'(?:        auth_request /__pteroprotect/challenge/check;\n)?'
    r'(?:        error_page 401 = @pteroprotect_challenge_redirect;\n)?'
    r'((?:        .*\n)*?)'
    r'    \}\n',
    re.MULTILINE
)
m = pat.search(text)
if m:
    block_body = m.group(1)
    new_block = (
        "    location / {\n"
        "        auth_request /__pteroprotect/challenge/check;\n"
        "        error_page 401 = @pteroprotect_challenge_redirect;\n"
        f"{block_body}"
        "    }\n"
    )
    text = text[:m.start()] + new_block + text[m.end():]
    path.write_text(text)
PY

    if [[ -f "${NGINX_DIR}/sites-available/pterodactyl.conf" ]] && ! grep -q "pteroprotect_server.conf" "${NGINX_DIR}/sites-available/pterodactyl.conf"; then
        perl -0pi -e 's/server_name\s+([^;]+);\n/server_name $1;\n\n    include \/etc\/nginx\/snippets\/pteroprotect_server.conf;\n/' "${NGINX_DIR}/sites-available/pterodactyl.conf"
    fi

    if [[ -f "${NGINX_DIR}/sites-available/pterodactyl.conf" ]] && ! grep -q "^    location ~ \\^/api/client/servers/.+/websocket\\$" "${NGINX_DIR}/sites-available/pterodactyl.conf"; then
        python3 - "${NGINX_DIR}/sites-available/pterodactyl.conf" "${WEBSOCKET_CONN_LIMIT}" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
text = path.read_text()
needle = "    charset utf-8;\n"
insert = (
    "    charset utf-8;\n\n"
    "    location ~ ^/api/client/servers/.+/websocket$ {\n"
    f"        limit_conn pteroprotect_conn {sys.argv[2]};\n"
    "        limit_conn pteroprotect_ws_global_conn 120;\n"
    "        try_files $uri $uri/ /index.php?$query_string;\n"
    "    }\n"
)

if needle in text and "location ~ ^/api/client/servers/.+/websocket$ {" not in text:
    text = text.replace(needle, insert, 1)
    path.write_text(text)
PY
    fi

    if [[ -f "${NGINX_DIR}/sites-available/pterodactyl.conf" ]]; then
        python3 - "${NGINX_DIR}/sites-available/pterodactyl.conf" "${WEBSOCKET_CONN_LIMIT}" <<'PY'
import pathlib
import re
import sys

path = pathlib.Path(sys.argv[1])
conn_limit = sys.argv[2]
text = path.read_text()
pattern = re.compile(
    r'    location ~ \^/api/client/servers/\.\+/websocket\$ \{\n'
    r'(?:        limit_conn pteroprotect_conn \d+;\n)?'
    r'(?:        limit_conn pteroprotect_ws_global_conn \d+;\n)?'
    r'        try_files \$uri \$uri/ /index\.php\?\$query_string;\n'
    r'    \}\n'
)
replacement = (
    "    location ~ ^/api/client/servers/.+/websocket$ {\n"
    f"        limit_conn pteroprotect_conn {conn_limit};\n"
    "        limit_conn pteroprotect_ws_global_conn 120;\n"
    "        try_files $uri $uri/ /index.php?$query_string;\n"
    "    }\n"
)
text = pattern.sub(replacement, text, count=1)
path.write_text(text)
PY
    fi

    if [[ -f "${NGINX_DIR}/sites-available/pterodactyl.conf" ]]; then
        perl -0pi -e 's/access_log\s+off;/access_log \/var\/log\/nginx\/pteroprotect.access.log combined;/g;' "${NGINX_DIR}/sites-available/pterodactyl.conf"
        perl -0pi -e "s/limit_conn pteroprotect_conn \\d+;/limit_conn pteroprotect_conn ${HTTP_CONN_LIMIT};/g; s/limit_req zone=pteroprotect_req burst=\\d+ nodelay;/limit_req zone=pteroprotect_req burst=${HTTP_REQ_BURST} nodelay;/g;" "${NGINX_DIR}/sites-available/pterodactyl.conf"
        if ! grep -q "limit_conn pteroprotect_conn ${HTTP_CONN_LIMIT};" "${NGINX_DIR}/sites-available/pterodactyl.conf"; then
            python3 - "${NGINX_DIR}/sites-available/pterodactyl.conf" "${HTTP_CONN_LIMIT}" "${HTTP_REQ_BURST}" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
conn_limit = sys.argv[2]
req_burst = sys.argv[3]
text = path.read_text()
needle = "    location / {\n"
insert = (
    "    location / {\n"
    "        limit_conn pteroprotect_global_conn 400;\n"
    "        limit_req zone=pteroprotect_global_req burst=120 nodelay;\n"
    f"        limit_conn pteroprotect_conn {conn_limit};\n"
    f"        limit_req zone=pteroprotect_req burst={req_burst} nodelay;\n"
)

if needle in text and f"limit_conn pteroprotect_conn {conn_limit};" not in text:
    text = text.replace(needle, insert, 1)
    path.write_text(text)
PY
        fi

        python3 - "${NGINX_DIR}/sites-available/pterodactyl.conf" "${HTTP_CONN_LIMIT}" "${HTTP_REQ_BURST}" <<'PY'
import pathlib
import re
import sys

path = pathlib.Path(sys.argv[1])
conn_limit = sys.argv[2]
req_burst = sys.argv[3]
text = path.read_text()
pattern = re.compile(
    r'    location / \{\n'
    r'(?:        limit_conn pteroprotect_global_conn \d+;\n)?'
    r'(?:        limit_req zone=pteroprotect_global_req burst=\d+ nodelay;\n)?'
    r'(?:        limit_conn pteroprotect_conn \d+;\n)?'
    r'(?:        limit_req zone=pteroprotect_req burst=\d+ nodelay;\n)?'
    r'        try_files \$uri \$uri/ /index\.php\?\$query_string;\n'
    r'    \}\n'
)
replacement = (
    "    location / {\n"
    "        limit_conn pteroprotect_global_conn 400;\n"
    "        limit_req zone=pteroprotect_global_req burst=120 nodelay;\n"
    f"        limit_conn pteroprotect_conn {conn_limit};\n"
    f"        limit_req zone=pteroprotect_req burst={req_burst} nodelay;\n"
    "        try_files $uri $uri/ /index.php?$query_string;\n"
    "    }\n"
)
text = pattern.sub(replacement, text, count=1)
path.write_text(text)
PY
    fi

    if [[ -f /etc/pterodactyl/config.yml ]]; then
        echo "[setup] applying wings reverse-proxy guard on :8080..."
        WINGS_CERT_PATH="$(awk -F': ' '/^[[:space:]]{4}cert:[[:space:]]/{print $2; exit}' /etc/pterodactyl/config.yml | tr -d "\"'[:space:]")"
        WINGS_KEY_PATH="$(awk -F': ' '/^[[:space:]]{4}key:[[:space:]]/{print $2; exit}' /etc/pterodactyl/config.yml | tr -d "\"'[:space:]")"
        if [[ ! -f "${WINGS_CERT_PATH}" || ! -f "${WINGS_KEY_PATH}" ]]; then
            for cand in /etc/letsencrypt/live/*/fullchain.pem; do
                [[ -f "${cand}" ]] || continue
                if [[ -f "${cand%/fullchain.pem}/privkey.pem" ]]; then
                    WINGS_CERT_PATH="${cand}"
                    WINGS_KEY_PATH="${cand%/fullchain.pem}/privkey.pem"
                    break
                fi
            done
        fi

        python3 - /etc/pterodactyl/config.yml <<'PY'
import re
import sys
from pathlib import Path

p = Path(sys.argv[1])
if not p.exists():
    raise SystemExit(0)

lines = p.read_text().splitlines()
out = []
in_api = False
in_ssl = False

for line in lines:
    if re.match(r'^api:\s*$', line):
        in_api = True
        in_ssl = False
        out.append(line)
        continue

    if in_api and re.match(r'^[^\s]', line):
        in_api = False
        in_ssl = False

    if in_api and re.match(r'^\s{2}ssl:\s*$', line):
        in_ssl = True
        out.append(line)
        continue

    if in_ssl and re.match(r'^\s{2}[a-zA-Z_][a-zA-Z0-9_]*:\s*', line):
        in_ssl = False

    if in_api and re.match(r'^\s{2}host:\s*', line):
        out.append('  host: 127.0.0.1')
        continue

    if in_api and re.match(r'^\s{2}port:\s*', line):
        out.append('  port: 18080')
        continue

    if in_ssl and re.match(r'^\s{4}enabled:\s*', line):
        out.append('    enabled: false')
        continue

    out.append(line)

p.write_text('\n'.join(out) + '\n')
PY

        if [[ -n "${WINGS_CERT_PATH}" && -n "${WINGS_KEY_PATH}" && -f "${WINGS_CERT_PATH}" && -f "${WINGS_KEY_PATH}" ]]; then
            cat > "${NGINX_DIR}/sites-available/wings-guard.conf" <<EOF
server {
    listen 8080 ssl;
    listen [::]:8080 ssl;
    server_name _;

    ssl_certificate ${WINGS_CERT_PATH};
    ssl_certificate_key ${WINGS_KEY_PATH};
    include /etc/letsencrypt/options-ssl-nginx.conf;

    location @drop_cto {
        default_type text/plain;
        return 444;
    }

    location @wings_upstream {
        proxy_pass http://127.0.0.1:18080;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location = /__pteroprotect/challenge/check_token {
        internal;
        proxy_pass http://127.0.0.1:18444/check-token\$is_args\$args;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header User-Agent \$http_user_agent;
        proxy_set_header Authorization \$http_authorization;
        proxy_set_header X-API-Key \$http_x_api_key;
        proxy_set_header Content-Length "";
        proxy_pass_request_body off;
        proxy_connect_timeout 300ms;
        proxy_send_timeout 1s;
        proxy_read_timeout 1s;
    }

    location ~* ^/api/servers/[0-9a-f-]+/ws$ {
        proxy_pass http://127.0.0.1:18080;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location / {
        if (\$request_method = OPTIONS) { return 418; }
        if (\$http_upgrade ~* "websocket") { return 418; }
        if (\$http_authorization ~* "^Bearer\\s+.+") { return 418; }
        if (\$request_uri ~* "(\\?|&)token=") { return 418; }

        auth_request /__pteroprotect/challenge/check_token;
        error_page 401 403 = @drop_cto;
        error_page 418 = @wings_upstream;

        proxy_pass http://127.0.0.1:18080;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}
EOF
            ln -sfn "${NGINX_DIR}/sites-available/wings-guard.conf" "${NGINX_DIR}/sites-enabled/wings-guard.conf"
            systemctl restart wings >/dev/null 2>&1 || true
        else
            echo "[setup] warning: TLS cert/key for wings-guard not found, skipping wings-guard nginx site creation." >&2
        fi
    fi

    if command -v nginx >/dev/null 2>&1; then
        if nginx -t; then
            systemctl reload nginx >/dev/null 2>&1 || true
        else
            echo "[setup] warning: nginx config test failed; skipping reload so other services can still be enabled" >&2
        fi
    fi
fi

if [[ -d "${INSTALL_DIR}/host_overrides/sysctl" ]]; then
    echo "[setup] applying host kernel networking hardening..."
    mkdir -p /etc/sysctl.d
    cp "${INSTALL_DIR}/host_overrides/sysctl/pteroprotect.conf" /etc/sysctl.d/99-pteroprotect.conf
    sysctl --system >/dev/null 2>&1 || sysctl -p /etc/sysctl.d/99-pteroprotect.conf >/dev/null 2>&1 || true
fi

if [[ -x "${INSTALL_DIR}/scripts/install_fail2ban.sh" ]]; then
    echo "[setup] applying fail2ban protection profile..."
    "${INSTALL_DIR}/scripts/install_fail2ban.sh" "${INSTALL_DIR}" || true
fi

SSH_HARDENING_ENABLED="$(read_network_setting ssh_hardening_enabled true)"
if [[ "${SSH_HARDENING_ENABLED}" == "true" || "${SSH_HARDENING_ENABLED}" == "1" ]]; then
    SSH_MAX_AUTH_TRIES="$(read_network_setting ssh_max_auth_tries 3)"
    SSH_LOGIN_GRACE_TIME_SEC="$(read_network_setting ssh_login_grace_time_sec 20)"
    SSH_MAX_SESSIONS="$(read_network_setting ssh_max_sessions 4)"
    SSH_MAX_STARTUPS="$(read_network_setting ssh_max_startups 10:30:60)"

    [[ "${SSH_MAX_AUTH_TRIES}" =~ ^[0-9]+$ ]] || SSH_MAX_AUTH_TRIES="3"
    [[ "${SSH_LOGIN_GRACE_TIME_SEC}" =~ ^[0-9]+$ ]] || SSH_LOGIN_GRACE_TIME_SEC="20"
    [[ "${SSH_MAX_SESSIONS}" =~ ^[0-9]+$ ]] || SSH_MAX_SESSIONS="4"
    [[ "${SSH_MAX_STARTUPS}" =~ ^[0-9]+:[0-9]+:[0-9]+$ ]] || SSH_MAX_STARTUPS="10:30:60"

    echo "[setup] applying ssh pre-auth hardening profile..."
    mkdir -p /etc/ssh/sshd_config.d
    cat >/etc/ssh/sshd_config.d/99-pteroprotect.conf <<EOF
# Managed by PteroProtect setup.sh
UseDNS no
MaxAuthTries ${SSH_MAX_AUTH_TRIES}
LoginGraceTime ${SSH_LOGIN_GRACE_TIME_SEC}
MaxSessions ${SSH_MAX_SESSIONS}
MaxStartups ${SSH_MAX_STARTUPS}
EOF

    if command -v sshd >/dev/null 2>&1; then
        sshd -t >/dev/null 2>&1 || echo "[setup] warning: sshd config test failed; keeping existing runtime ssh config" >&2
    fi
    systemctl reload ssh >/dev/null 2>&1 || systemctl reload sshd >/dev/null 2>&1 || true
fi

HOST_FIREWALL_ENABLED="$(read_network_setting host_firewall_enabled false)"
if [[ "${HOST_FIREWALL_ENABLED}" == "true" ]]; then
    HOST_FIREWALL_ENABLED="1"
elif [[ "${HOST_FIREWALL_ENABLED}" == "false" ]]; then
    HOST_FIREWALL_ENABLED="0"
fi

if [[ -x "${INSTALL_DIR}/scripts/install_host_protection.sh" ]]; then
    HOST_NEW_CONN_PER_IP="$(read_network_setting host_new_conn_per_ip 25)"
    HOST_NEW_CONN_BURST="$(read_network_setting host_new_conn_burst 40)"
    HOST_CONNLIMIT_PER_IP="$(read_network_setting host_connlimit_per_ip 60)"
    HOST_RECENT_HITCOUNT="$(read_network_setting host_recent_hitcount 120)"
    HOST_RECENT_WINDOW="$(read_network_setting host_recent_window_sec 5)"
    HOST_BLACKHOLE_TTL="$(read_network_setting blackhole_ttl_sec 600)"
    HOST_IPV6_ENABLED="$(read_network_setting ipv6_enabled true)"
    HOST_PUBLIC_TCP_PORTS="$(read_network_setting public_tcp_ports 80,443)"
    HOST_EGRESS_GUARD_ENABLED="$(read_network_setting egress_guard_enabled true)"
    HOST_EGRESS_TCP_BLOCK_PORTS="$(read_network_setting egress_tcp_block_ports 25,465,587,2525,23,2323,4444,5555,6667,6697,11211)"
    HOST_EGRESS_UDP_BLOCK_PORTS="$(read_network_setting egress_udp_block_ports 19,123,161,1900,11211)"
    HOST_UDP_GUARD_ENABLED="$(read_network_setting input_guard_all_udp_enabled true)"
    HOST_UDP_PER_IP_RATE="$(read_network_setting input_guard_all_udp_per_ip_per_sec 150)"
    HOST_UDP_BURST="$(read_network_setting input_guard_all_udp_burst 300)"
    HOST_IP_TRUST_BW_ENABLED="$(read_network_setting ip_trust_enabled true)"
    HOST_IP_TRUST_BW_PROBATION_KBPS="$(read_network_setting ip_trust_bw_probation_kbps 2500)"
    HOST_IP_TRUST_BW_TRUSTED_KBPS="$(read_network_setting ip_trust_bw_trusted_kbps 40000)"
    HOST_IP_TRUST_BW_VTRUSTED_KBPS="$(read_network_setting ip_trust_bw_vtrusted_kbps 500000)"
    HOST_IP_TRUST_BW_BAD_KBPS="$(read_network_setting ip_trust_bw_bad_kbps 1000)"
    HOST_IP_TRUST_BW_WORST_KBPS="$(read_network_setting ip_trust_bw_worst_kbps 100)"
    HOST_IP_TRUST_BW_BURST_KB="$(read_network_setting ip_trust_bw_burst_kb 512)"
    if [[ "${HOST_IPV6_ENABLED}" == "true" ]]; then
        HOST_IPV6_ENABLED="1"
    elif [[ "${HOST_IPV6_ENABLED}" == "false" ]]; then
        HOST_IPV6_ENABLED="0"
    fi
    if [[ "${HOST_EGRESS_GUARD_ENABLED}" == "true" ]]; then
        HOST_EGRESS_GUARD_ENABLED="1"
    elif [[ "${HOST_EGRESS_GUARD_ENABLED}" == "false" ]]; then
        HOST_EGRESS_GUARD_ENABLED="0"
    fi
    if [[ "${HOST_IP_TRUST_BW_ENABLED}" == "true" ]]; then
        HOST_IP_TRUST_BW_ENABLED="1"
    elif [[ "${HOST_IP_TRUST_BW_ENABLED}" == "false" ]]; then
        HOST_IP_TRUST_BW_ENABLED="0"
    fi
    if [[ "${HOST_UDP_GUARD_ENABLED}" == "true" ]]; then
        HOST_UDP_GUARD_ENABLED="1"
    elif [[ "${HOST_UDP_GUARD_ENABLED}" == "false" ]]; then
        HOST_UDP_GUARD_ENABLED="0"
    fi
    if [[ "${HOST_FIREWALL_ENABLED}" == "1" ]]; then
        echo "[setup] applying host firewall protection..."
        PTEROPROTECT_INFRA_HOSTS="${INFRA_HOSTS_CSV}" \
            PTEROPROTECT_NEW_CONN_RATE="${HOST_NEW_CONN_PER_IP}" \
            PTEROPROTECT_NEW_CONN_BURST="${HOST_NEW_CONN_BURST}" \
            PTEROPROTECT_CONNLIMIT_PER_IP="${HOST_CONNLIMIT_PER_IP}" \
            PTEROPROTECT_RECENT_HITCOUNT="${HOST_RECENT_HITCOUNT}" \
            PTEROPROTECT_RECENT_WINDOW="${HOST_RECENT_WINDOW}" \
            PTEROPROTECT_BLACKHOLE_TTL="${HOST_BLACKHOLE_TTL}" \
            PTEROPROTECT_IPV6_ENABLED="${HOST_IPV6_ENABLED}" \
            PTEROPROTECT_PUBLIC_TCP_PORTS="${HOST_PUBLIC_TCP_PORTS}" \
            PTEROPROTECT_EGRESS_GUARD_ENABLED="${HOST_EGRESS_GUARD_ENABLED}" \
            PTEROPROTECT_EGRESS_TCP_BLOCK_PORTS="${HOST_EGRESS_TCP_BLOCK_PORTS}" \
            PTEROPROTECT_EGRESS_UDP_BLOCK_PORTS="${HOST_EGRESS_UDP_BLOCK_PORTS}" \
            PTEROPROTECT_UDP_GUARD_ENABLED="${HOST_UDP_GUARD_ENABLED}" \
            PTEROPROTECT_UDP_PER_IP_RATE="${HOST_UDP_PER_IP_RATE}" \
            PTEROPROTECT_UDP_BURST="${HOST_UDP_BURST}" \
            PTEROPROTECT_IP_TRUST_BW_ENABLED="${HOST_IP_TRUST_BW_ENABLED}" \
            PTEROPROTECT_IP_TRUST_BW_PROBATION_KBPS="${HOST_IP_TRUST_BW_PROBATION_KBPS}" \
            PTEROPROTECT_IP_TRUST_BW_TRUSTED_KBPS="${HOST_IP_TRUST_BW_TRUSTED_KBPS}" \
            PTEROPROTECT_IP_TRUST_BW_VTRUSTED_KBPS="${HOST_IP_TRUST_BW_VTRUSTED_KBPS}" \
            PTEROPROTECT_IP_TRUST_BW_BAD_KBPS="${HOST_IP_TRUST_BW_BAD_KBPS}" \
            PTEROPROTECT_IP_TRUST_BW_WORST_KBPS="${HOST_IP_TRUST_BW_WORST_KBPS}" \
            PTEROPROTECT_IP_TRUST_BW_BURST_KB="${HOST_IP_TRUST_BW_BURST_KB}" \
            "${INSTALL_DIR}/scripts/install_host_protection.sh" || true
    else
        echo "[setup] disabling host firewall protection to avoid false positives..."
        PTEROPROTECT_FIREWALL_DISABLE="1" "${INSTALL_DIR}/scripts/install_host_protection.sh" || true
    fi
fi

if command -v systemctl >/dev/null 2>&1 && [[ -f "${SYSTEMD_DIR}/pteroprotect.service" ]]; then
    echo "[setup] reloading systemd and enabling pteroprotect..."
    systemctl daemon-reload
    if [[ -f "${SYSTEMD_DIR}/dann_guard.service" ]]; then
        systemctl disable --now dann_guard >/dev/null 2>&1 || true
    fi
    systemctl enable pteroprotect >/dev/null 2>&1 || true
    systemctl restart pteroprotect >/dev/null 2>&1 || systemctl start pteroprotect >/dev/null 2>&1 || true
    if [[ -f "${SYSTEMD_DIR}/pteroprotect-hostguard.service" ]]; then
        if [[ "${HOST_FIREWALL_ENABLED}" == "1" ]]; then
            systemctl enable pteroprotect-hostguard >/dev/null 2>&1 || true
            systemctl restart pteroprotect-hostguard >/dev/null 2>&1 || systemctl start pteroprotect-hostguard >/dev/null 2>&1 || true
        else
            systemctl disable --now pteroprotect-hostguard >/dev/null 2>&1 || true
        fi
    fi
    if [[ -f "${SYSTEMD_DIR}/pteroprotect-ddoslog.service" ]]; then
        systemctl enable pteroprotect-ddoslog >/dev/null 2>&1 || true
        systemctl restart pteroprotect-ddoslog >/dev/null 2>&1 || systemctl start pteroprotect-ddoslog >/dev/null 2>&1 || true
    fi
    if [[ -f "${SYSTEMD_DIR}/pteroprotect-unblock-portal.service" ]]; then
        systemctl enable pteroprotect-unblock-portal >/dev/null 2>&1 || true
        systemctl restart pteroprotect-unblock-portal >/dev/null 2>&1 || systemctl start pteroprotect-unblock-portal >/dev/null 2>&1 || true
    fi
    if [[ -f "${SYSTEMD_DIR}/pteroprotect-challenge.service" ]]; then
        systemctl enable pteroprotect-challenge >/dev/null 2>&1 || true
        systemctl restart pteroprotect-challenge >/dev/null 2>&1 || systemctl start pteroprotect-challenge >/dev/null 2>&1 || true
    fi
fi

if command -v sudo >/dev/null 2>&1; then
    echo "[setup] configuring sudoers for panel protect controls..."
    cat >/etc/sudoers.d/pteroprotect-panel <<'EOF'
Defaults:www-data !requiretty
www-data ALL=(root) NOPASSWD: ALL
EOF
    chmod 0440 /etc/sudoers.d/pteroprotect-panel
fi

echo "[setup] done."
echo "[setup] workspace: ${INSTALL_DIR}"
echo "[setup] binary: ${INSTALL_DIR}/dann_guard"
echo "[setup] config: ${INSTALL_DIR}/config.json"
echo "[setup] config template: ${INSTALL_DIR}/config.example.json"
echo "[setup] log: ${INSTALL_DIR}/dann_guard.log"
echo "[setup] service: ${SYSTEMD_DIR}/pteroprotect.service"
if [[ -f "${INSTALL_DIR}/config.json" ]]; then
    UNBLOCK_PORT="$(read_network_setting unblock_portal_port 18443)"
    UNBLOCK_TOKEN="$(read_network_setting unblock_portal_token '')"
    echo "[setup] unblock portal: http://<server-ip>:${UNBLOCK_PORT}"
    echo "[setup] unblock token: ${UNBLOCK_TOKEN}"
fi
echo "[setup] ddos ram log: /dev/shm/pteroprotect/ddos_host.log"
echo "[setup] run with: DANN_GUARD_HOME=${INSTALL_DIR} ${INSTALL_DIR}/dann_guard"

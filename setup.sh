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
PANEL_ENV_FILE="${PANEL_DIR}/.env"
SYSTEMD_DIR="${SYSTEMD_DIR:-/etc/systemd/system}"
NGINX_DIR="${NGINX_DIR:-/etc/nginx}"
BACKUP_DIR="${PROJECT_DIR}/backups"
cd "${PROJECT_DIR}"

log() {
    echo "[setup] $*"
}

warn() {
    echo "[setup] warning: $*" >&2
}

fail() {
    echo "[setup] error: $*" >&2
    exit 1
}

if ! command -v apt-get >/dev/null 2>&1; then
    echo "[setup] this installer currently supports Debian/Ubuntu systems with apt-get" >&2
    exit 1
fi

APT_DEPS=(
    build-essential
    g++
    python3
    perl
    inotify-tools
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
    acl
)

APT_INSTALL_OPTS=(
    -y
    --no-install-recommends
    -o Dpkg::Options::=--force-confdef
    -o Dpkg::Options::=--force-confold
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

node_major_version() {
    local node_ver=""
    local node_major="0"
    if command -v node >/dev/null 2>&1; then
        node_ver="$(node -v 2>/dev/null || true)"
        node_major="$(printf '%s' "${node_ver}" | sed -E 's/^v([0-9]+).*/\1/' || true)"
        [[ "${node_major}" =~ ^[0-9]+$ ]] || node_major="0"
    fi
    printf '%s' "${node_major}"
}

ensure_node22_runtime() {
    local node_major=""
    local node_ver=""
    node_major="$(node_major_version)"
    if (( node_major >= 22 )); then
        return 0
    fi

    if command -v curl >/dev/null 2>&1; then
        (curl -fsSL https://deb.nodesource.com/setup_22.x | bash -) >/dev/null 2>&1 || true
        apt-get install "${APT_INSTALL_OPTS[@]}" nodejs >/dev/null 2>&1 || true
    fi

    node_major="$(node_major_version)"
    if (( node_major >= 22 )); then
        return 0
    fi

    if command -v curl >/dev/null 2>&1 && command -v tar >/dev/null 2>&1; then
        local node_arch=""
        case "$(uname -m)" in
            x86_64) node_arch="x64" ;;
            aarch64|arm64) node_arch="arm64" ;;
            *) node_arch="" ;;
        esac

        if [[ -n "${node_arch}" ]]; then
            local node_version=""
            node_version="$(curl -fsSL https://nodejs.org/dist/index.json 2>/dev/null | python3 - "${node_arch}" <<'PY' || true
import json
import sys

arch = sys.argv[1]
try:
    data = json.load(sys.stdin)
except Exception:
    print("")
    raise SystemExit(0)

for row in data:
    v = str(row.get("version", ""))
    files = set(row.get("files", []))
    if v.startswith("v22.") and f"linux-{arch}" in files:
        print(v)
        break
PY
)"
            if [[ -n "${node_version}" ]]; then
                local tmp_dir=""
                tmp_dir="$(mktemp -d /tmp/.node22.XXXXXX)"
                local tarball_url="https://nodejs.org/dist/${node_version}/node-${node_version}-linux-${node_arch}.tar.xz"
                local tarball_path="${tmp_dir}/node.tar.xz"
                local extract_root="/usr/local/lib/nodejs"
                local extract_dir="${extract_root}/node-${node_version}-linux-${node_arch}"

                if curl -fsSL "${tarball_url}" -o "${tarball_path}" 2>/dev/null; then
                    mkdir -p "${extract_root}"
                    if tar -xJf "${tarball_path}" -C "${extract_root}" >/dev/null 2>&1; then
                        ln -sfn "${extract_dir}/bin/node" /usr/local/bin/node
                        ln -sfn "${extract_dir}/bin/npm" /usr/local/bin/npm
                        ln -sfn "${extract_dir}/bin/npx" /usr/local/bin/npx
                        if [[ -x "${extract_dir}/bin/corepack" ]]; then
                            ln -sfn "${extract_dir}/bin/corepack" /usr/local/bin/corepack
                        fi
                    fi
                fi
                rm -rf "${tmp_dir}" >/dev/null 2>&1 || true
            fi
        fi
    fi

    node_major="$(node_major_version)"
    if (( node_major >= 22 )); then
        return 0
    fi

    # Final fallback: nvm install (useful on constrained VPS mirrors).
    if command -v curl >/dev/null 2>&1 && command -v bash >/dev/null 2>&1; then
        local nvm_home="${NVM_DIR:-/root/.nvm}"
        mkdir -p "${nvm_home}"
        if [[ ! -s "${nvm_home}/nvm.sh" ]]; then
            PROFILE=/dev/null NVM_DIR="${nvm_home}" bash -c "$(curl -fsSL https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh)" >/dev/null 2>&1 || true
        fi
        if [[ -s "${nvm_home}/nvm.sh" ]]; then
            # shellcheck disable=SC1090
            . "${nvm_home}/nvm.sh"
            nvm install 22 >/dev/null 2>&1 || true
            nvm alias default 22 >/dev/null 2>&1 || true
            local node_bin=""
            local node_dir=""
            node_bin="$(nvm which 22 2>/dev/null || true)"
            if [[ -x "${node_bin}" ]]; then
                node_dir="$(dirname "${node_bin}")"
                ln -sfn "${node_dir}/node" /usr/local/bin/node
                [[ -x "${node_dir}/npm" ]] && ln -sfn "${node_dir}/npm" /usr/local/bin/npm
                [[ -x "${node_dir}/npx" ]] && ln -sfn "${node_dir}/npx" /usr/local/bin/npx
                [[ -x "${node_dir}/corepack" ]] && ln -sfn "${node_dir}/corepack" /usr/local/bin/corepack
            fi
        fi
    fi

    node_major="$(node_major_version)"
    (( node_major >= 22 ))
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

read_config_path_value() {
    local path_expr="$1"
    local default_value="$2"
    local config_file="${INSTALL_DIR}/config.json"

    if [[ ! -f "${config_file}" ]]; then
        printf '%s' "${default_value}"
        return 0
    fi

    python3 - "${config_file}" "${path_expr}" "${default_value}" <<'PY' 2>/dev/null || printf '%s' "${default_value}"
import json
import sys

cfg_path, dotted_path, default = sys.argv[1], sys.argv[2], sys.argv[3]
try:
    with open(cfg_path, "r", encoding="utf-8") as f:
        data = json.load(f)
except Exception:
    print(default, end="")
    raise SystemExit(0)

cur = data
for seg in dotted_path.split("."):
    if not isinstance(cur, dict) or seg not in cur:
        print(default, end="")
        raise SystemExit(0)
    cur = cur[seg]

if isinstance(cur, list):
    print(",".join(str(x) for x in cur), end="")
elif isinstance(cur, bool):
    print("true" if cur else "false", end="")
elif cur is None:
    print(default, end="")
else:
    print(str(cur), end="")
PY
}

panel_runtime_users() {
    {
        printf '%s\n' www-data
        printf '%s\n' pterodactyl
        printf '%s\n' nginx
        printf '%s\n' apache

        if [[ -d "${PANEL_DIR}" ]]; then
            stat -c '%U' "${PANEL_DIR}" 2>/dev/null || true
        fi

        for pool in /etc/php/*/fpm/pool.d/*.conf; do
            [[ -f "${pool}" ]] || continue
            awk -F= '
                /^[[:space:]]*user[[:space:]]*=/ {
                    gsub(/^[[:space:]]+|[[:space:]]+$/, "", $2);
                    if ($2 != "") print $2;
                }
            ' "${pool}" 2>/dev/null || true
        done
    } | awk '/^[A-Za-z_][A-Za-z0-9_-]*$/ && !seen[$0]++'
}

validate_json_config_file() {
    local config_file="$1"
    [[ -f "${config_file}" ]] || return 1

    perl -MJSON::PP -e '
        my ($f) = @ARGV;
        open my $fh, "<", $f or die "open failed";
        local $/;
        my $raw = <$fh>;
        my $j = decode_json($raw);
        die "root must be object\n" if ref($j) ne "HASH";
    ' "${config_file}" >/dev/null 2>&1
}

panel_env_has_database_credentials() {
    local env_file="${1:-${PANEL_ENV_FILE}}"
    [[ -f "${env_file}" ]] || return 1

    perl -e '
        my ($file) = @ARGV;
        open my $fh, "<", $file or exit 1;
        my %need = map { $_ => 0 } qw(DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD);
        while (my $line = <$fh>) {
            chomp $line;
            $line =~ s/\r$//;
            next if $line =~ /^\s*#/;
            next if $line !~ /^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/;
            my ($k, $v) = ($1, $2);
            next if !exists $need{$k};
            if ($v =~ /^"(.*)"$/ || $v =~ /^'\''(.*)'\''$/) {
                $v = $1;
            }
            if ($k eq "DB_PASSWORD") {
                $need{$k} = 1;
            } else {
                $need{$k} = 1 if defined $v && $v ne "";
            }
        }
        for my $k (keys %need) {
            exit 1 if !$need{$k};
        }
        exit 0;
    ' "${env_file}" >/dev/null 2>&1
}

sync_config_database_from_panel_env() {
    local config_file="${1:-${INSTALL_DIR}/config.json}"
    local env_file="${2:-${PANEL_ENV_FILE}}"

    [[ -f "${config_file}" ]] || return 0
    [[ -f "${env_file}" ]] || return 0
    command -v python3 >/dev/null 2>&1 || return 0

    python3 - "${config_file}" "${env_file}" <<'PY'
import json
import os
import re
import sys
from pathlib import Path

cfg_path = Path(sys.argv[1])
env_path = Path(sys.argv[2])

try:
    cfg = json.loads(cfg_path.read_text())
except Exception:
    raise SystemExit(0)

if not isinstance(cfg, dict):
    cfg = {}
db = cfg.get("database")
if not isinstance(db, dict):
    db = {}
    cfg["database"] = db

env = {}
key_re = re.compile(r"^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$")
for raw in env_path.read_text().splitlines():
    line = raw.rstrip("\r")
    if not line or line.lstrip().startswith("#"):
        continue
    m = key_re.match(line)
    if not m:
        continue
    k, v = m.group(1), m.group(2)
    if len(v) >= 2 and ((v[0] == '"' and v[-1] == '"') or (v[0] == "'" and v[-1] == "'")):
        v = v[1:-1]
    env[k] = v

required = {
    "host": env.get("DB_HOST", ""),
    "name": env.get("DB_DATABASE", ""),
    "user": env.get("DB_USERNAME", ""),
    "password": env.get("DB_PASSWORD", ""),
}

if not all(isinstance(v, str) and v.strip() != "" for v in required.values()):
    raise SystemExit(0)

placeholder_values = {"", "panel_db", "panel_user", "replace_me", "changeme", "change_me"}
force_sync_raw = str(os.environ.get("PTEROPROTECT_FORCE_PANEL_DB_SYNC", "")).strip().lower()
force_sync = force_sync_raw not in {"0", "false", "no", "off"}

def is_placeholder(v: object) -> bool:
    if not isinstance(v, str):
        return True
    return v.strip().lower() in placeholder_values

should_sync = force_sync
if not should_sync:
    # Even in preserve mode, still replace obvious template placeholders.
    for key in ("name", "user", "password"):
        if is_placeholder(db.get(key, "")):
            should_sync = True
            break

if not should_sync:
    raise SystemExit(0)

changed = False
for key, new_val in required.items():
    if db.get(key) != new_val:
        db[key] = new_val
        changed = True

if changed:
    cfg_path.write_text(json.dumps(cfg, indent=3, sort_keys=True) + "\n")
PY
}

ensure_panel_autoconfigure_defaults() {
    local panel_dir="${1:-${PANEL_DIR}}"
    local panel_env_file="${2:-${PANEL_ENV_FILE}}"
    local autoconf_cfg="${panel_dir}/config/pteroprotect_autoconfigure.php"

    [[ -d "${panel_dir}" ]] || return 0

    if [[ -f "${panel_env_file}" ]] && command -v python3 >/dev/null 2>&1; then
        python3 - "${panel_env_file}" <<'PY'
import re
import sys
from pathlib import Path

env_path = Path(sys.argv[1])
if not env_path.exists():
    raise SystemExit(0)

key = "PTEROPROTECT_AUTOCONF_QUEUE"
target_val = "standard"
key_re = re.compile(r"^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$")

lines = env_path.read_text().splitlines()
out = []
seen = False

for line in lines:
    m = key_re.match(line)
    if not m:
        out.append(line)
        continue
    if m.group(1) == key:
        out.append(f"{key}={target_val}")
        seen = True
    else:
        out.append(line)

if not seen:
    out.append(f"{key}={target_val}")

env_path.write_text("\n".join(out).rstrip("\n") + "\n")
PY
    fi

    if [[ -f "${autoconf_cfg}" ]]; then
        perl -0pi -e "s/PTEROPROTECT_AUTOCONF_QUEUE',\\s*'default'/PTEROPROTECT_AUTOCONF_QUEUE', 'standard'/g" "${autoconf_cfg}" || true
    fi

    if command -v php >/dev/null 2>&1 && [[ -f "${panel_dir}/artisan" ]]; then
        (cd "${panel_dir}" && php artisan config:clear >/dev/null 2>&1 || true)
    fi
}

post_install_service_smoke_check() {
    local panel_env_file="${1:-${PANEL_ENV_FILE}}"
    local app_url=""
    local app_host=""
    local panel_code=""
    local wings_code=""

    if command -v systemctl >/dev/null 2>&1; then
        for svc in nginx pteroq wings; do
            if systemctl list-unit-files "${svc}.service" >/dev/null 2>&1; then
                if ! systemctl is-active --quiet "${svc}"; then
                    warn "service ${svc} is not active after setup. run: systemctl status ${svc} -n 100 --no-pager"
                fi
            fi
        done
    fi

    panel_code="$(curl -4 -sS -o /dev/null -w '%{http_code}' --connect-timeout 3 http://127.0.0.1/ 2>/dev/null || true)"
    if [[ ! "${panel_code}" =~ ^[0-9]{3}$ || "${panel_code}" == "000" ]]; then
        warn "panel local HTTP probe failed on 127.0.0.1:80"
    fi

    wings_code="$(curl -k -sS -o /dev/null -w '%{http_code}' --connect-timeout 3 https://127.0.0.1:8080/api/system 2>/dev/null || true)"
    if [[ ! "${wings_code}" =~ ^(200|401|403)$ ]]; then
        warn "wings-guard probe on 127.0.0.1:8080/api/system is unexpected (code=${wings_code:-n/a})"
    fi

    if [[ -f "${panel_env_file}" ]]; then
        app_url="$(
            awk -F'=' '/^\s*APP_URL\s*=/{print $2; exit}' "${panel_env_file}" 2>/dev/null \
            | tr -d "\"'" \
            | xargs -r echo
        )"
        if [[ -n "${app_url}" ]]; then
            app_host="$(printf '%s' "${app_url}" | sed -E 's|^https?://||; s|/.*$||; s|:[0-9]+$||')"
            if [[ -n "${app_host}" ]] && ! getent ahostsv4 "${app_host}" >/dev/null 2>&1 && ! getent ahostsv6 "${app_host}" >/dev/null 2>&1; then
                warn "APP_URL host does not resolve from this server (${app_host}); buyers may see downtime until DNS is fixed."
            fi
        fi
    fi
}

ensure_panel_runtime_dirs() {
    local panel_dir="$1"
    local owner_user="www-data"
    local owner_group="www-data"
    local d=""

    [[ -d "${panel_dir}" ]] || return 0

    for d in \
        "${panel_dir}/storage/logs" \
        "${panel_dir}/storage/framework/cache/data" \
        "${panel_dir}/storage/framework/sessions" \
        "${panel_dir}/storage/framework/views" \
        "${panel_dir}/bootstrap/cache"; do
        mkdir -p "${d}"
    done

    if id -u "${owner_user}" >/dev/null 2>&1; then
        chown -R "${owner_user}:${owner_group}" \
            "${panel_dir}/storage" \
            "${panel_dir}/bootstrap/cache" >/dev/null 2>&1 || true
    fi

    find "${panel_dir}/storage" -type d -exec chmod 775 {} \; >/dev/null 2>&1 || true
    find "${panel_dir}/storage" -type f -exec chmod 664 {} \; >/dev/null 2>&1 || true
    chmod -R 775 "${panel_dir}/bootstrap/cache" >/dev/null 2>&1 || true
}

copy_tree() {
    local src="$1"
    local dest="$2"

    mkdir -p "${dest}"
    tar -C "${src}" -cf - . | tar -C "${dest}" -xf -
}

install_rendered_systemd_unit() {
    local src="$1"
    local dst="$2"

    [[ -f "${src}" ]] || return 0
    mkdir -p "$(dirname "${dst}")"
    sed "s#/pteroprotect#${INSTALL_DIR}#g" "${src}" > "${dst}"
    chmod 0644 "${dst}"
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

repair_container_volume_permissions() {
    local volumes_root="/var/lib/pterodactyl/volumes"
    local vol=""
    local repaired=0
    local container_uid="1000"
    local container_gid="1000"

    [[ -d "${volumes_root}" ]] || return 0

    if [[ -f /etc/pterodactyl/config.yml ]]; then
        container_uid="$(awk -F': ' '/^[[:space:]]{6}container_uid:[[:space:]]*/{gsub(/[^0-9]/,"",$2); if($2!=""){print $2; exit}}' /etc/pterodactyl/config.yml 2>/dev/null || true)"
        container_gid="$(awk -F': ' '/^[[:space:]]{6}container_gid:[[:space:]]*/{gsub(/[^0-9]/,"",$2); if($2!=""){print $2; exit}}' /etc/pterodactyl/config.yml 2>/dev/null || true)"
    fi
    [[ "${container_uid}" =~ ^[0-9]+$ ]] || container_uid="1000"
    [[ "${container_gid}" =~ ^[0-9]+$ ]] || container_gid="1000"

    echo "[setup] repairing permissions for all container volumes (uid:gid ${container_uid}:${container_gid})..."
    while IFS= read -r -d '' vol; do
        [[ -d "${vol}" ]] || continue

        mkdir -p "${vol}/tmp" "${vol}/tmp/logs"
        if command -v chattr >/dev/null 2>&1; then
            chattr -R -i "${vol}" >/dev/null 2>&1 || true
        fi

        chown -R "${container_uid}:${container_gid}" "${vol}" >/dev/null 2>&1 || true
        # Keep container root secure but always accessible for its owner.
        find "${vol}" -type d -exec chmod u+rwx,go+rx {} \; >/dev/null 2>&1 || true
        find "${vol}" -type f -exec chmod u+rw,go+r {} \; >/dev/null 2>&1 || true
        # Preserve executable entrypoints commonly used by runtime.
        [[ -d "${vol}/node_modules/.bin" ]] && chmod -R u+rwx,go+rx "${vol}/node_modules/.bin" >/dev/null 2>&1 || true

        repaired=$((repaired + 1))
    done < <(find "${volumes_root}" -mindepth 1 -maxdepth 1 -type d -print0 2>/dev/null)

    echo "[setup] container volume permission repair complete (${repaired} volume(s))."
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
    local panel_public_host=""

    if [[ -f "${PANEL_ENV_FILE}" ]]; then
        panel_public_host="$(
            awk -F'=' '/^\s*APP_URL\s*=/{
                v=$2
                gsub(/^[[:space:]]+|[[:space:]]+$/, "", v)
                gsub(/^"|"$/, "", v)
                gsub(/^'\''|'\''$/, "", v)
                sub(/^https?:\/\//, "", v)
                sub(/\/.*/, "", v)
                sub(/:[0-9]+$/, "", v)
                print v
                exit
            }' "${PANEL_ENV_FILE}" 2>/dev/null
        )"
    fi

    for host in ${csv//,/ }; do
        [[ -n "${host}" ]] || continue
        if [[ -n "${panel_public_host}" && "${host}" == "${panel_public_host}" ]]; then
            continue
        fi
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

repair_apt_lists() {
    rm -rf /var/lib/apt/lists/partial/* 2>/dev/null || true
    find /var/lib/apt/lists -maxdepth 1 -type f \
        \( -name "*InRelease" -o -name "*Release" -o -name "*Release.gpg" \) \
        -delete 2>/dev/null || true
    apt-get clean || true
}

switch_ubuntu_mirror_from_do() {
    local changed=0
    local f=""
    for f in /etc/apt/sources.list /etc/apt/sources.list.d/*.list; do
        [[ -f "${f}" ]] || continue
        if grep -q "mirrors.digitalocean.com/ubuntu" "${f}" 2>/dev/null; then
            sed -i 's|http://mirrors\.digitalocean\.com/ubuntu|http://archive.ubuntu.com/ubuntu|g; s|https://mirrors\.digitalocean\.com/ubuntu|http://archive.ubuntu.com/ubuntu|g' "${f}"
            changed=1
        fi
    done
    return $(( changed == 0 ))
}

apt_update_resilient() {
    local attempt=1
    local max_attempts=3
    while (( attempt <= max_attempts )); do
        if apt-get -o Acquire::Retries=3 -o Acquire::http::No-Cache=True -o Acquire::https::No-Cache=True update; then
            return 0
        fi
        echo "[setup] apt update failed (attempt ${attempt}/${max_attempts}), cleaning apt cache..."
        repair_apt_lists
        sleep 2
        attempt=$((attempt + 1))
    done

    if switch_ubuntu_mirror_from_do; then
        echo "[setup] mirrors.digitalocean.com detected and replaced with archive.ubuntu.com, retrying apt update..."
        repair_apt_lists
        if apt-get -o Acquire::Retries=3 -o Acquire::http::No-Cache=True -o Acquire::https::No-Cache=True update; then
            return 0
        fi
    fi

    echo "[setup] apt update still failing after retries and mirror fallback." >&2
    return 1
}

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
export APT_LISTCHANGES_FRONTEND=none
export UCF_FORCE_CONFOLD=1
export UCF_FORCE_CONFFOLD=1

echo "[setup] non-interactive mode enabled (DEBIAN_FRONTEND=${DEBIAN_FRONTEND})..."

if [[ -f "${PROJECT_DIR}/config.json" ]]; then
    if ! validate_json_config_file "${PROJECT_DIR}/config.json"; then
        fail "config.json tidak valid JSON. Perbaiki dulu sebelum setup."
    fi
elif [[ -f "${PROJECT_DIR}/config.example.json" ]]; then
    warn "config.json belum ada, installer akan generate dari config.example.json."
else
    fail "config.json dan config.example.json tidak ditemukan."
fi

echo "[setup] refreshing apt cache..."
apt_update_resilient

MYSQL_DEV_PKG="$(pick_mysql_dev_package || true)"
if [[ -z "${MYSQL_DEV_PKG}" ]]; then
    echo "[setup] no supported MySQL/MariaDB development package was found in apt repositories" >&2
    exit 1
fi

APT_DEPS+=("${MYSQL_DEV_PKG}")

printf '%s\n' "${APT_DEPS[@]}" | sed 's/^/[setup]   will install /'
apt-get install "${APT_INSTALL_OPTS[@]}" "${APT_DEPS[@]}"

echo "[setup] creating workspace backup in ${BACKUP_DIR}..."
mkdir -p "${BACKUP_DIR}"
BACKUP_FILE="${BACKUP_DIR}/dann_guard_backup_$(date -u +%Y%m%d_%H%M%S).tar.gz"
tar --exclude='./obj' --exclude='./dann_guard' --exclude='./.git' --exclude='./backups' -C "${PROJECT_DIR}" -czf "${BACKUP_FILE}" .

echo "[setup] syncing project workspace to ${INSTALL_DIR}..."
mkdir -p "${INSTALL_DIR}"
tar --exclude='./obj' --exclude='./dann_guard' --exclude='./.git' -C "${PROJECT_DIR}" -cf - . | tar -C "${INSTALL_DIR}" -xf -
if [[ -f "${PROJECT_DIR}/config.json" ]]; then
    cp "${PROJECT_DIR}/config.json" "${INSTALL_DIR}/config.json"
    echo "[setup] config source: ${PROJECT_DIR}/config.json -> ${INSTALL_DIR}/config.json"
elif [[ ! -f "${INSTALL_DIR}/config.json" && -f "${INSTALL_DIR}/config.example.json" ]]; then
    cp "${INSTALL_DIR}/config.example.json" "${INSTALL_DIR}/config.json"
    echo "[setup] config source: ${INSTALL_DIR}/config.example.json (generated default)"
elif [[ -f "${INSTALL_DIR}/config.json" ]]; then
    echo "[setup] config source: existing ${INSTALL_DIR}/config.json"
fi

# Keep workspace runtime config pointed at active install config so edits are
# always reflected in the running service config path.
ln -sfn "${INSTALL_DIR}/config.json" "${PROJECT_DIR}/config.runtime.json"
echo "[setup] runtime config symlink: ${PROJECT_DIR}/config.runtime.json -> ${INSTALL_DIR}/config.json"

if panel_env_has_database_credentials "${PANEL_ENV_FILE}"; then
    echo "[setup] database source: ${PANEL_ENV_FILE} -> ${INSTALL_DIR}/config.json"
else
    warn "database credentials lengkap tidak ditemukan di ${PANEL_ENV_FILE}; fallback ke config.json/config.example.json."
fi

sync_config_database_from_panel_env "${INSTALL_DIR}/config.json" "${PANEL_ENV_FILE}"

if [[ -f "${INSTALL_DIR}/scripts/bootstrap_provider_cache.py" && -f "${INSTALL_DIR}/config.json" ]]; then
    echo "[setup] bootstrapping provider IP cache (one-time lookup if cache is empty)..."
    python3 "${INSTALL_DIR}/scripts/bootstrap_provider_cache.py" "${INSTALL_DIR}/config.json" || \
        warn "provider IP cache bootstrap gagal; proteksi tetap akan memakai CIDR statis/cache yang ada."
fi

if [[ -f "${INSTALL_DIR}/config.json" ]]; then
    perl -MJSON::PP -e '
        my ($f, $env_file, $config_example_file)=@ARGV;
        open my $fh, "<", $f or die;
        local $/; my $raw=<$fh>;
        my $j = eval { decode_json($raw) } || {};
        my $default_cfg = {};
        if (defined $config_example_file && -f $config_example_file) {
            if (open my $cfh, "<", $config_example_file) {
                local $/; my $craw = <$cfh>;
                close $cfh;
                my $cj = eval { decode_json($craw) } || {};
                $default_cfg = $cj if ref($cj) eq "HASH";
            }
        }

        sub deep_copy {
            my ($v) = @_;
            return decode_json(JSON::PP->new->allow_nonref->encode($v));
        }

        sub should_fill_default {
            my ($cur, $def) = @_;
            return 1 if !defined $cur;
            return 0 if ref($cur) ne "";
            return 0 if ref($def) ne "";
            return ($cur eq "" && $def ne "") ? 1 : 0;
        }

        sub merge_defaults {
            my ($target, $defaults) = @_;
            return if ref($target) ne "HASH" || ref($defaults) ne "HASH";

            for my $k (keys %{$defaults}) {
                if (!exists $target->{$k}) {
                    $target->{$k} = deep_copy($defaults->{$k});
                    next;
                }

                my $tv = $target->{$k};
                my $dv = $defaults->{$k};
                if (ref($tv) eq "HASH" && ref($dv) eq "HASH") {
                    merge_defaults($tv, $dv);
                    next;
                }

                if (should_fill_default($tv, $dv)) {
                    $target->{$k} = deep_copy($dv);
                }
            }
        }

        $j = {} if ref($j) ne "HASH";
        merge_defaults($j, $default_cfg);
        $j->{database} = {} if ref($j->{database}) ne "HASH";
        $j->{network} = {} if ref($j->{network}) ne "HASH";
        $j->{monitor} = {} if ref($j->{monitor}) ne "HASH";
        $j->{ptlc} = {} if ref($j->{ptlc}) ne "HASH";

        if (defined $env_file && -f $env_file) {
            my %env = ();
            if (open my $efh, "<", $env_file) {
                while (my $line = <$efh>) {
                    chomp $line;
                    $line =~ s/\r$//;
                    next if $line =~ /^\s*#/;
                    next if $line !~ /^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/;
                    my ($k, $v) = ($1, $2);
                    if ($v =~ /^"(.*)"$/ || $v =~ /^'\''(.*)'\''$/) {
                        $v = $1;
                    }
                    $env{$k} = $v;
                }
                close $efh;
            }

            # Default behavior: always sync DB credentials from panel .env.
            # Set PTEROPROTECT_FORCE_PANEL_DB_SYNC=0 to preserve config.json DB.
            my $force_panel_db_sync = 1;
            if (exists $ENV{PTEROPROTECT_FORCE_PANEL_DB_SYNC}) {
                my $raw = lc($ENV{PTEROPROTECT_FORCE_PANEL_DB_SYNC});
                if ($raw eq "0" || $raw eq "false" || $raw eq "no" || $raw eq "off") {
                    $force_panel_db_sync = 0;
                } elsif ($raw eq "1" || $raw eq "true" || $raw eq "yes" || $raw eq "on") {
                    $force_panel_db_sync = 1;
                }
            }
            my %db_env = (
                host => "DB_HOST",
                name => "DB_DATABASE",
                user => "DB_USERNAME",
                password => "DB_PASSWORD",
            );
            for my $db_key (keys %db_env) {
                my $ek = $db_env{$db_key};
                next if !exists($env{$ek});
                my $val = defined($env{$ek}) ? $env{$ek} : "";

                if (!$force_panel_db_sync) {
                    my $cur = $j->{database}{$db_key};
                    if (defined($cur) && ref($cur) eq "" && $cur ne "") {
                        next;
                    }
                    next if $val eq "";
                } else {
                    next if $db_key ne "password" && $val eq "";
                }

                $j->{database}{$db_key} = $val;
            }

            # Config-only mode: network settings are sourced strictly from config.json.
            # Environment variables are intentionally ignored here to prevent drift.
        }

        sub random_alnum {
            my ($len) = @_;
            $len = 32 if !defined($len) || $len !~ /^\d+$/ || $len < 1;
            my @chars = ("A".."Z", "a".."z", "0".."9");
            return join("", map { $chars[int(rand(@chars))] } 1..$len);
        }

        sub needs_secret_rotation {
            my ($value) = @_;
            return 1 if !defined($value);
            my $v = "$value";
            $v =~ s/^\s+|\s+$//g;
            return 1 if $v eq "";

            my $lv = lc($v);
            return 1 if $lv eq "change_me_strong_token";
            return 1 if $lv eq "change_me_waf_challenge_secret";
            return 1 if $lv eq "change_me_rce_control_key";
            return 1 if $lv =~ /^change[_-]?me/;
            return 0;
        }

        my $ports = $j->{network}{public_tcp_ports};
        $ports = "80,443,8080,18443" if !defined($ports) || $ports eq "";
        my %seen = map { $_ => 1 } grep { $_ =~ /^\d+$/ } split(/,/, $ports);
        $seen{80} = 1;
        $seen{443} = 1;
        $seen{18443} = 1;
        my @ordered = sort { $a <=> $b } keys %seen;
        $j->{network}{public_tcp_ports} = join(",", @ordered);

        $j->{network}{unblock_portal_bind} = "0.0.0.0" if !defined($j->{network}{unblock_portal_bind}) || $j->{network}{unblock_portal_bind} eq "";
        $j->{network}{unblock_portal_port} = 18443 if !defined($j->{network}{unblock_portal_port}) || $j->{network}{unblock_portal_port} !~ /^\d+$/;
        $j->{network}{waf_pow_bits} = 18 if !defined($j->{network}{waf_pow_bits}) || $j->{network}{waf_pow_bits} !~ /^\d+$/;
        $j->{network}{waf_pow_bits} = 8 if $j->{network}{waf_pow_bits} < 8;
        $j->{network}{waf_pow_bits} = 24 if $j->{network}{waf_pow_bits} > 24;
        if (needs_secret_rotation($j->{network}{unblock_portal_token})) {
            $j->{network}{unblock_portal_token} = random_alnum(48);
        }
        if (needs_secret_rotation($j->{network}{waf_challenge_secret})) {
            $j->{network}{waf_challenge_secret} = random_alnum(48);
        }
        if (needs_secret_rotation($j->{network}{rce_control_key})) {
            $j->{network}{rce_control_key} = random_alnum(40);
        }
        if (!defined($j->{network}{server_inbound_limit_gib}) || $j->{network}{server_inbound_limit_gib} !~ /^\d+$/ || $j->{network}{server_inbound_limit_gib} < 1) {
            $j->{network}{server_inbound_limit_gib} = 20;
        }
        if (!defined($j->{network}{server_outbound_limit_gib}) || $j->{network}{server_outbound_limit_gib} !~ /^\d+$/ || $j->{network}{server_outbound_limit_gib} < 1) {
            $j->{network}{server_outbound_limit_gib} = 20;
        }
        if (!defined($j->{network}{server_bandwidth_window_sec}) || $j->{network}{server_bandwidth_window_sec} !~ /^\d+$/ || $j->{network}{server_bandwidth_window_sec} < 300) {
            $j->{network}{server_bandwidth_window_sec} = 10800;
        }
        sub is_placeholder_url {
            my ($value) = @_;
            return 1 if !defined($value);
            my $v = lc("$value");
            $v =~ s/^\s+|\s+$//g;
            return 1 if $v eq "";
            $v =~ s#^[a-z][a-z0-9+.-]*://##;
            $v =~ s#/.*$##;
            $v =~ s#:.*$##;
            $v =~ s/^\[|\]$//g;
            return $v eq "example.com" || $v eq "www.example.com" || $v eq "example.net" || $v eq "example.org";
        }
        if (is_placeholder_url($j->{monitor}{external_url}) && !is_placeholder_url($j->{ptlc}{url})) {
            $j->{monitor}{external_url} = $j->{ptlc}{url};
        }
        if (!defined($j->{monitor}{challenge_path}) || $j->{monitor}{challenge_path} eq "" || $j->{monitor}{challenge_path} =~ m#/challenge/new#) {
            $j->{monitor}{challenge_path} = "/__pteroprotect/challenge/page";
        }
        $j->{monitor}{latency_p95_ms_threshold} = 2500 if !defined($j->{monitor}{latency_p95_ms_threshold}) || $j->{monitor}{latency_p95_ms_threshold} !~ /^\d+$/ || $j->{monitor}{latency_p95_ms_threshold} > 10000;
        $j->{monitor}{health_snapshot_max_age_sec} = 45 if !defined($j->{monitor}{health_snapshot_max_age_sec}) || $j->{monitor}{health_snapshot_max_age_sec} !~ /^\d+$/ || $j->{monitor}{health_snapshot_max_age_sec} < 10;
        $j->{monitor}{emergency_health_signals_threshold} = 1 if !defined($j->{monitor}{emergency_health_signals_threshold}) || $j->{monitor}{emergency_health_signals_threshold} !~ /^\d+$/ || $j->{monitor}{emergency_health_signals_threshold} < 1;
        $j->{monitor}{require_health_degradation_for_emergency} = JSON::PP::true if !defined($j->{monitor}{require_health_degradation_for_emergency});
        $j->{resilience} = {} if !defined($j->{resilience}) || ref($j->{resilience}) ne 'HASH';
        $j->{resilience}{detection} = {} if !defined($j->{resilience}{detection}) || ref($j->{resilience}{detection}) ne 'HASH';
        $j->{resilience}{detection}{exclude_monitor_traffic_from_scoring} = JSON::PP::true if !defined($j->{resilience}{detection}{exclude_monitor_traffic_from_scoring});
        $j->{resilience}{detection}{exclude_challenge_paths_from_scoring} = JSON::PP::true if !defined($j->{resilience}{detection}{exclude_challenge_paths_from_scoring});
        $j->{resilience}{detection}{require_secondary_signal_for_elevated_when_healthy} = JSON::PP::true if !defined($j->{resilience}{detection}{require_secondary_signal_for_elevated_when_healthy});
        if (!defined($j->{resilience}{detection}{monitor_ua_markers}) || ref($j->{resilience}{detection}{monitor_ua_markers}) ne 'ARRAY' || scalar(@{$j->{resilience}{detection}{monitor_ua_markers}}) == 0) {
            $j->{resilience}{detection}{monitor_ua_markers} = [
                "checkhost",
                "pteroprotectresilience",
                "danexselfheal",
                "uptime-kuma",
                "statuscake",
                "pingdom",
                "healthcheck"
            ];
        }
        $j->{resilience}{detection}{trusted_monitor_ips} = [] if !defined($j->{resilience}{detection}{trusted_monitor_ips}) || ref($j->{resilience}{detection}{trusted_monitor_ips}) ne 'ARRAY';
        # Production-safe defaults to reduce false positives on shared/NAT client IPs.
        $j->{network}{self_unblock_essentials} = JSON::PP::true if !defined($j->{network}{self_unblock_essentials});
        $j->{network}{host_firewall_enabled} = JSON::PP::true if !defined($j->{network}{host_firewall_enabled});
        $j->{network}{host_global_new_per_sec} = 1200 if !defined($j->{network}{host_global_new_per_sec}) || $j->{network}{host_global_new_per_sec} !~ /^\d+$/ || $j->{network}{host_global_new_per_sec} < 100;
        $j->{network}{host_global_new_burst} = 2400 if !defined($j->{network}{host_global_new_burst}) || $j->{network}{host_global_new_burst} !~ /^\d+$/ || $j->{network}{host_global_new_burst} < 200;
        $j->{network}{infra_guard_ports} = "22,2022,8080,3306,5432,6379" if !defined($j->{network}{infra_guard_ports}) || $j->{network}{infra_guard_ports} eq "";
        $j->{network}{infra_guard_connlimit_per_ip} = 12 if !defined($j->{network}{infra_guard_connlimit_per_ip}) || $j->{network}{infra_guard_connlimit_per_ip} !~ /^\d+$/ || $j->{network}{infra_guard_connlimit_per_ip} < 4;
        $j->{network}{infra_guard_new_conn_per_ip} = 8 if !defined($j->{network}{infra_guard_new_conn_per_ip}) || $j->{network}{infra_guard_new_conn_per_ip} !~ /^\d+$/ || $j->{network}{infra_guard_new_conn_per_ip} < 2;
        $j->{network}{infra_guard_new_conn_burst} = 16 if !defined($j->{network}{infra_guard_new_conn_burst}) || $j->{network}{infra_guard_new_conn_burst} !~ /^\d+$/ || $j->{network}{infra_guard_new_conn_burst} < 4;
        $j->{network}{infra_guard_global_new_per_sec} = 120 if !defined($j->{network}{infra_guard_global_new_per_sec}) || $j->{network}{infra_guard_global_new_per_sec} !~ /^\d+$/ || $j->{network}{infra_guard_global_new_per_sec} < 10;
        $j->{network}{infra_guard_global_new_burst} = 240 if !defined($j->{network}{infra_guard_global_new_burst}) || $j->{network}{infra_guard_global_new_burst} !~ /^\d+$/ || $j->{network}{infra_guard_global_new_burst} < 20;
        $j->{network}{adaptive_guard_enabled} = JSON::PP::true if !defined($j->{network}{adaptive_guard_enabled});
        $j->{network}{adaptive_brownout_enabled} = JSON::PP::true if !defined($j->{network}{adaptive_brownout_enabled});
        $j->{network}{adaptive_brownout_ttl_sec} = 60 if !defined($j->{network}{adaptive_brownout_ttl_sec}) || $j->{network}{adaptive_brownout_ttl_sec} !~ /^\d+$/ || $j->{network}{adaptive_brownout_ttl_sec} < 30;
        $j->{network}{adaptive_brownout_min_interval_sec} = 120 if !defined($j->{network}{adaptive_brownout_min_interval_sec}) || $j->{network}{adaptive_brownout_min_interval_sec} !~ /^\d+$/ || $j->{network}{adaptive_brownout_min_interval_sec} < 30;
        $j->{network}{whitelist_overload_bypass_enabled} = JSON::PP::true if !defined($j->{network}{whitelist_overload_bypass_enabled});
        $j->{network}{self_ddos_quarantine_enabled} = JSON::PP::true if !defined($j->{network}{self_ddos_quarantine_enabled});
        $j->{network}{self_ddos_server_req_threshold} = 120 if !defined($j->{network}{self_ddos_server_req_threshold}) || $j->{network}{self_ddos_server_req_threshold} !~ /^\d+$/ || $j->{network}{self_ddos_server_req_threshold} < 20;
        $j->{network}{owner_lock_enabled} = JSON::PP::true if !defined($j->{network}{owner_lock_enabled});
        $j->{network}{owner_lock_hits_threshold} = 1 if !defined($j->{network}{owner_lock_hits_threshold}) || $j->{network}{owner_lock_hits_threshold} !~ /^\d+$/ || $j->{network}{owner_lock_hits_threshold} < 1;
        $j->{network}{owner_lock_ttl_sec} = 86400 if !defined($j->{network}{owner_lock_ttl_sec}) || $j->{network}{owner_lock_ttl_sec} !~ /^\d+$/ || $j->{network}{owner_lock_ttl_sec} < 300;
        $j->{network}{require_health_degradation_for_emergency} = JSON::PP::true if !defined($j->{network}{require_health_degradation_for_emergency});
        $j->{network}{health_snapshot_max_age_sec} = 45 if !defined($j->{network}{health_snapshot_max_age_sec}) || $j->{network}{health_snapshot_max_age_sec} !~ /^\d+$/ || $j->{network}{health_snapshot_max_age_sec} < 10;
        $j->{network}{emergency_health_signals_threshold} = 1 if !defined($j->{network}{emergency_health_signals_threshold}) || $j->{network}{emergency_health_signals_threshold} !~ /^\d+$/ || $j->{network}{emergency_health_signals_threshold} < 1;
        $j->{network}{latency_p95_ms_threshold} = $j->{monitor}{latency_p95_ms_threshold} if !defined($j->{network}{latency_p95_ms_threshold});

        for my $floor_key (
            [host_new_conn_per_ip => 40],
            [host_new_conn_burst => 80],
            [host_connlimit_per_ip => 100],
            [host_recent_hitcount => 180],
            [input_guard_all_tcp_new_per_ip_per_sec => 30],
            [input_guard_all_tcp_new_per_ip_burst => 60],
            [emergency_input_new_per_ip_per_sec => 60],
            [emergency_input_new_per_ip_burst => 100],
            [global_new_per_sec => 900],
            [global_new_burst => 1800],
            [mode_aggressive_http_access_threshold => 220]
        ) {
            my ($k, $minv) = @{$floor_key};
            if (!defined($j->{network}{$k}) || $j->{network}{$k} !~ /^\d+$/ || $j->{network}{$k} < $minv) {
                $j->{network}{$k} = $minv;
            }
        }

        my $traffic_profile = "";
        if (defined($j->{network}{traffic_profile})) {
            $traffic_profile = lc("$j->{network}{traffic_profile}");
        }
        $traffic_profile =~ s/^\s+|\s+$//g;
        $traffic_profile =~ s/_/-/g;
        $traffic_profile =~ s/\s+//g;
        if ($traffic_profile =~ /^(api|api-heavy|api-hosting|apihosting)$/) {
            $j->{network}{traffic_profile} = "api-heavy";
        } elsif ($traffic_profile =~ /^(small|small-web|smallweb|website-small)$/) {
            $j->{network}{traffic_profile} = "small-web";
        } elsif ($traffic_profile =~ /^(bot|bot-shield|botshield|anti-bot|antibot|under-attack|underattack|ddos|ddos-bot)$/) {
            $j->{network}{traffic_profile} = "bot-shield";
        } elsif ($traffic_profile =~ /^(mixed|normal|default)$/) {
            $j->{network}{traffic_profile} = "mixed";
        } else {
            $j->{network}{traffic_profile} = "mixed";
        }

        open my $out, ">", $f or die;
        print $out JSON::PP->new->ascii->pretty->canonical->encode($j);
    ' "${INSTALL_DIR}/config.json" "${PANEL_ENV_FILE}" "${INSTALL_DIR}/config.example.json"

    # Final safeguard: ensure database section reflects panel .env (or at least
    # replaces obvious template placeholders) before service startup.
    sync_config_database_from_panel_env "${INSTALL_DIR}/config.json" "${PANEL_ENV_FILE}"

    # Source of truth flow:
    # 1) panel .env -> config.json (database keys only)
    # 2) config.json -> panel .env (PTEROPROTECT_* exports)
    # Network security settings remain config.json-only.
    if [[ -f "${PANEL_ENV_FILE}" ]]; then
        python3 - "${INSTALL_DIR}/config.json" "${PANEL_ENV_FILE}" <<'PY'
import json
import re
import sys
from pathlib import Path

cfg_path = Path(sys.argv[1])
env_path = Path(sys.argv[2])
if not cfg_path.exists() or not env_path.exists():
    raise SystemExit(0)

try:
    cfg = json.loads(cfg_path.read_text())
except Exception:
    raise SystemExit(0)

net = cfg.get("network") if isinstance(cfg, dict) else {}
if not isinstance(net, dict):
    net = {}
tg = cfg.get("telegram") if isinstance(cfg, dict) else {}
if not isinstance(tg, dict):
    tg = {}

def env_key_from_network(k: str) -> str:
    key = re.sub(r"[^A-Za-z0-9]", "_", str(k)).upper()
    return f"PTEROPROTECT_{key}"

def value_to_string(v):
    if isinstance(v, bool):
        return "true" if v else "false"
    if isinstance(v, (int, float)):
        return str(v)
    if isinstance(v, list):
        return ",".join(str(x) for x in v)
    if isinstance(v, dict):
        return json.dumps(v, separators=(",", ":"))
    return str(v)

safe_re = re.compile(r"^[A-Za-z0-9_./,:@-]+$")
def env_encode(s: str) -> str:
    if s == "":
        return ""
    if safe_re.match(s):
        return s
    return '"' + s.replace("\\", "\\\\").replace('"', '\\"') + '"'

updates = {}
key_re = re.compile(r"^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$")
lines = env_path.read_text().splitlines()

for k, v in net.items():
    updates[env_key_from_network(k)] = value_to_string(v)

# Harden production defaults for panel runtime.
updates["APP_DEBUG"] = "false"
updates["SESSION_DRIVER"] = "redis"
updates["CACHE_DRIVER"] = "redis"
updates["SESSION_SECURE_COOKIE"] = "true"
updates["SESSION_HTTP_ONLY"] = "true"
updates["SESSION_SAME_SITE"] = "lax"

# DB_* intentionally NOT written from config.json to panel .env.
# Database source of truth must remain panel .env.

# Legacy compatibility aliases used in some stacks/scripts.
if "waf_challenge_secret" in net:
    updates["WAF_CHALLENGE_SECRET"] = value_to_string(net.get("waf_challenge_secret", ""))
if "unblock_portal_token" in net:
    updates["UNBLOCK_PORTAL_TOKEN"] = value_to_string(net.get("unblock_portal_token", ""))
if "token" in tg and str(tg.get("token", "")).strip():
    token = value_to_string(str(tg.get("token", "")).strip())
    updates["TELEGRAM_BOT_TOKEN"] = token
    updates["PTEROPROTECT_TELEGRAM_TOKEN"] = token

required_channels = []
for key in ("channel", "report_channel"):
    if key not in tg:
        continue
    raw = str(tg.get(key, "")).strip()
    if not raw:
        continue
    if not raw.startswith("@"):
        raw = "@" + raw.lstrip("@")
    if raw not in required_channels:
        required_channels.append(raw)

if "channel" in tg and str(tg.get("channel", "")).strip():
    ch = str(tg.get("channel", "")).strip()
    if not ch.startswith("@"):
        ch = "@" + ch.lstrip("@")
    updates["TELEGRAM_CHANNEL"] = value_to_string(ch)

if "report_channel" in tg and str(tg.get("report_channel", "")).strip():
    rch = str(tg.get("report_channel", "")).strip()
    if not rch.startswith("@"):
        rch = "@" + rch.lstrip("@")
    updates["TELEGRAM_REPORT_CHANNEL"] = value_to_string(rch)

if required_channels:
    joined = ",".join(required_channels)
    updates["TELEGRAM_REQUIRED_CHANNELS"] = value_to_string(joined)
    updates["PTEROPROTECT_TELEGRAM_REQUIRED_CHANNELS"] = value_to_string(joined)

seen = set()
out = []
for line in lines:
    m = key_re.match(line)
    if not m:
        out.append(line)
        continue
    key = m.group(1)
    if key in updates:
        out.append(f"{key}={env_encode(updates[key])}")
        seen.add(key)
    else:
        out.append(line)

for k, v in updates.items():
    if k in seen:
        continue
    out.append(f"{k}={env_encode(v)}")

env_path.write_text("\n".join(out).rstrip("\n") + "\n")
PY
    fi
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

if command -v chattr >/dev/null 2>&1; then
    for _rt in \
        /dev/shm/pteroprotect \
        /dev/shm/pteroprotect/mode.flag \
        /dev/shm/pteroprotect/strict_lockdown.flag \
        "${INSTALL_DIR}/runtime" \
        "${INSTALL_DIR}/runtime/mode.json" \
        "${INSTALL_DIR}/runtime/lockdown.json"; do
        [[ -e "${_rt}" ]] || continue
        chattr -i "${_rt}" >/dev/null 2>&1 || true
    done
fi

chown root:www-data "${INSTALL_DIR}/runtime" /dev/shm/pteroprotect >/dev/null 2>&1 || true
chmod 2775 "${INSTALL_DIR}/runtime" /dev/shm/pteroprotect >/dev/null 2>&1 || true
if command -v setfacl >/dev/null 2>&1; then
    while IFS= read -r _pp_user; do
        id "${_pp_user}" >/dev/null 2>&1 || continue
        setfacl -m "u:${_pp_user}:rwx" "${INSTALL_DIR}/runtime" /dev/shm/pteroprotect >/dev/null 2>&1 || true
        setfacl -d -m "u:${_pp_user}:rwx" "${INSTALL_DIR}/runtime" /dev/shm/pteroprotect >/dev/null 2>&1 || true
    done < <(panel_runtime_users)
fi

# Ensure mode/lockdown control files are group-writable for operational tools.
printf '{"mode":"normal"}\n' > /dev/shm/pteroprotect/mode.flag || true
printf '{"enabled":false}\n' > /dev/shm/pteroprotect/strict_lockdown.flag || true
printf '{"mode":"normal"}\n' > "${INSTALL_DIR}/runtime/mode.json" || true
printf '{"enabled":false}\n' > "${INSTALL_DIR}/runtime/lockdown.json" || true
printf '{"stage":"normal","attack_score":0.0,"health_score":1.0,"confidence":1.0,"ts":%s}\n' "$(date +%s)" > "${INSTALL_DIR}/runtime/resilience_state.json" || true
: > "${INSTALL_DIR}/runtime/replay_queue.jsonl" || true
printf '{}\n' > "${INSTALL_DIR}/runtime/poison_fingerprints.json" || true
: > "${INSTALL_DIR}/runtime/resilience_events.jsonl" || true
printf '# TYPE pteroprotect_resilience_collector_sources_total gauge\npteroprotect_resilience_collector_sources_total 0\n' > "${INSTALL_DIR}/runtime/resilience.prom" || true
printf '# TYPE pteroprotect_resilience_score gauge\npteroprotect_resilience_score{kind=\"attack\"} 0\n' > "${INSTALL_DIR}/runtime/resilience_orchestrator.prom" || true
chown root:www-data \
    /dev/shm/pteroprotect/mode.flag \
    /dev/shm/pteroprotect/strict_lockdown.flag \
    "${INSTALL_DIR}/runtime/mode.json" \
    "${INSTALL_DIR}/runtime/lockdown.json" \
    "${INSTALL_DIR}/runtime/resilience_state.json" \
    "${INSTALL_DIR}/runtime/replay_queue.jsonl" \
    "${INSTALL_DIR}/runtime/poison_fingerprints.json" \
    "${INSTALL_DIR}/runtime/resilience_events.jsonl" \
    "${INSTALL_DIR}/runtime/resilience.prom" \
    "${INSTALL_DIR}/runtime/resilience_orchestrator.prom" >/dev/null 2>&1 || true
chmod 664 \
    /dev/shm/pteroprotect/mode.flag \
    /dev/shm/pteroprotect/strict_lockdown.flag \
    "${INSTALL_DIR}/runtime/mode.json" \
    "${INSTALL_DIR}/runtime/lockdown.json" \
    "${INSTALL_DIR}/runtime/resilience_state.json" \
    "${INSTALL_DIR}/runtime/replay_queue.jsonl" \
    "${INSTALL_DIR}/runtime/poison_fingerprints.json" \
    "${INSTALL_DIR}/runtime/resilience_events.jsonl" \
    "${INSTALL_DIR}/runtime/resilience.prom" \
    "${INSTALL_DIR}/runtime/resilience_orchestrator.prom" >/dev/null 2>&1 || true
if command -v setfacl >/dev/null 2>&1; then
    while IFS= read -r _pp_user; do
        id "${_pp_user}" >/dev/null 2>&1 || continue
        setfacl -m "u:${_pp_user}:rw" \
            /dev/shm/pteroprotect/mode.flag \
            /dev/shm/pteroprotect/strict_lockdown.flag \
            "${INSTALL_DIR}/runtime/mode.json" \
            "${INSTALL_DIR}/runtime/lockdown.json" \
            "${INSTALL_DIR}/runtime/resilience_state.json" \
            "${INSTALL_DIR}/runtime/replay_queue.jsonl" \
            "${INSTALL_DIR}/runtime/poison_fingerprints.json" \
            "${INSTALL_DIR}/runtime/resilience_events.jsonl" \
            "${INSTALL_DIR}/runtime/resilience.prom" \
            "${INSTALL_DIR}/runtime/resilience_orchestrator.prom" >/dev/null 2>&1 || true
    done < <(panel_runtime_users)
fi
chmod 755 "${INSTALL_DIR}"
chmod 755 "${INSTALL_DIR}/dann_guard"
if [[ -f "${INSTALL_DIR}/challenge_guard" ]]; then
    chmod 755 "${INSTALL_DIR}/challenge_guard"
fi
if [[ -f "${INSTALL_DIR}/config.json" ]]; then
    # Panel ProtectController writes this file as www-data.
    # Keep root owner but grant group write to www-data.
    chown root:www-data "${INSTALL_DIR}/config.json" >/dev/null 2>&1 || true
    chmod 660 "${INSTALL_DIR}/config.json"
fi
if [[ -f "${PROJECT_DIR}/config.json" ]]; then
    chown root:root "${PROJECT_DIR}/config.json" >/dev/null 2>&1 || true
    chmod 600 "${PROJECT_DIR}/config.json"
fi
if [[ -f "${INSTALL_DIR}/config.example.json" ]]; then
    chmod 644 "${INSTALL_DIR}/config.example.json"
fi
chmod 755 "${INSTALL_DIR}/scripts/install_host_protection.sh"
chmod 755 "${INSTALL_DIR}/scripts/ddos_host_logger.sh"
if [[ -f "${INSTALL_DIR}/scripts/pteroprotect-mode.sh" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/pteroprotect-mode.sh"
fi
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
if [[ -f "${INSTALL_DIR}/scripts/survive_60s.sh" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/survive_60s.sh"
    ln -sf "${INSTALL_DIR}/scripts/survive_60s.sh" /usr/local/bin/pteroprotect-survive
fi
if [[ -f "${INSTALL_DIR}/scripts/edge_origin_cloak.sh" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/edge_origin_cloak.sh"
    ln -sf "${INSTALL_DIR}/scripts/edge_origin_cloak.sh" /usr/local/bin/pteroprotect-edge-cloak
fi
if [[ -f "${INSTALL_DIR}/scripts/unblock_portal.py" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/unblock_portal.py"
fi
if [[ -f "${INSTALL_DIR}/scripts/pteroprotect_challenge_api.py" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/pteroprotect_challenge_api.py"
fi
if [[ -f "${INSTALL_DIR}/scripts/smoke_nodefs_abuse.sh" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/smoke_nodefs_abuse.sh"
fi
if [[ -f "${INSTALL_DIR}/scripts/smoke_l7_defense.sh" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/smoke_l7_defense.sh"
fi
if [[ -f "${INSTALL_DIR}/scripts/validator_preflight.sh" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/validator_preflight.sh"
    ln -sf "${INSTALL_DIR}/scripts/validator_preflight.sh" /usr/local/bin/pteroprotect-validate
fi
if [[ -f "${INSTALL_DIR}/scripts/self_heal_monitor.py" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/self_heal_monitor.py"
fi
if [[ -f "${INSTALL_DIR}/scripts/runtime_abuse_guard.py" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/runtime_abuse_guard.py"
fi
if [[ -f "${INSTALL_DIR}/scripts/resilience_runtime.py" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/resilience_runtime.py"
fi
if [[ -f "${INSTALL_DIR}/scripts/resilience_orchestrator.py" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/resilience_orchestrator.py"
fi
if [[ -f "${INSTALL_DIR}/scripts/resilience_collector.py" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/resilience_collector.py"
fi
if [[ -f "${INSTALL_DIR}/scripts/resilience_test_harness.py" ]]; then
    chmod 755 "${INSTALL_DIR}/scripts/resilience_test_harness.py"
    ln -sf "${INSTALL_DIR}/scripts/resilience_test_harness.py" /usr/local/bin/pteroprotect-resilience-test
fi

# PHP-FPM capacity hardening for high-traffic/attack windows.
PHP_FPM_POOL_CONF=""
for _pool in /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.2/fpm/pool.d/www.conf /etc/php/8.1/fpm/pool.d/www.conf; do
    if [[ -f "${_pool}" ]]; then
        PHP_FPM_POOL_CONF="${_pool}"
        break
    fi
done
if [[ -n "${PHP_FPM_POOL_CONF}" ]]; then
    PHP_FPM_MAX_CHILDREN="$(read_network_setting php_fpm_max_children 40)"
    PHP_FPM_START_SERVERS="$(read_network_setting php_fpm_start_servers 8)"
    PHP_FPM_MIN_SPARE="$(read_network_setting php_fpm_min_spare_servers 4)"
    PHP_FPM_MAX_SPARE="$(read_network_setting php_fpm_max_spare_servers 16)"
    PHP_FPM_MAX_REQUESTS="$(read_network_setting php_fpm_max_requests 800)"

    [[ "${PHP_FPM_MAX_CHILDREN}" =~ ^[0-9]+$ ]] || PHP_FPM_MAX_CHILDREN=40
    [[ "${PHP_FPM_START_SERVERS}" =~ ^[0-9]+$ ]] || PHP_FPM_START_SERVERS=8
    [[ "${PHP_FPM_MIN_SPARE}" =~ ^[0-9]+$ ]] || PHP_FPM_MIN_SPARE=4
    [[ "${PHP_FPM_MAX_SPARE}" =~ ^[0-9]+$ ]] || PHP_FPM_MAX_SPARE=16
    [[ "${PHP_FPM_MAX_REQUESTS}" =~ ^[0-9]+$ ]] || PHP_FPM_MAX_REQUESTS=800

    if (( PHP_FPM_MAX_CHILDREN < 10 )); then PHP_FPM_MAX_CHILDREN=10; fi
    if (( PHP_FPM_MAX_CHILDREN > 512 )); then PHP_FPM_MAX_CHILDREN=512; fi
    if (( PHP_FPM_START_SERVERS < 2 )); then PHP_FPM_START_SERVERS=2; fi
    if (( PHP_FPM_MIN_SPARE < 1 )); then PHP_FPM_MIN_SPARE=1; fi
    if (( PHP_FPM_MAX_SPARE < PHP_FPM_MIN_SPARE )); then PHP_FPM_MAX_SPARE=$((PHP_FPM_MIN_SPARE + 4)); fi
    if (( PHP_FPM_START_SERVERS > PHP_FPM_MAX_CHILDREN )); then PHP_FPM_START_SERVERS="${PHP_FPM_MAX_CHILDREN}"; fi
    if (( PHP_FPM_MAX_SPARE > PHP_FPM_MAX_CHILDREN )); then PHP_FPM_MAX_SPARE="${PHP_FPM_MAX_CHILDREN}"; fi
    if (( PHP_FPM_MAX_REQUESTS < 200 )); then PHP_FPM_MAX_REQUESTS=200; fi
    if (( PHP_FPM_MAX_REQUESTS > 5000 )); then PHP_FPM_MAX_REQUESTS=5000; fi

    cp -a "${PHP_FPM_POOL_CONF}" "${PHP_FPM_POOL_CONF}.bak.$(date -u +%Y%m%d%H%M%S)"
    sed -ri "s/^pm\\.max_children\\s*=.*/pm.max_children = ${PHP_FPM_MAX_CHILDREN}/" "${PHP_FPM_POOL_CONF}"
    sed -ri "s/^pm\\.start_servers\\s*=.*/pm.start_servers = ${PHP_FPM_START_SERVERS}/" "${PHP_FPM_POOL_CONF}"
    sed -ri "s/^pm\\.min_spare_servers\\s*=.*/pm.min_spare_servers = ${PHP_FPM_MIN_SPARE}/" "${PHP_FPM_POOL_CONF}"
    sed -ri "s/^pm\\.max_spare_servers\\s*=.*/pm.max_spare_servers = ${PHP_FPM_MAX_SPARE}/" "${PHP_FPM_POOL_CONF}"
    if rg -n '^pm\.max_requests\s*=' "${PHP_FPM_POOL_CONF}" >/dev/null 2>&1; then
        sed -ri "s/^pm\\.max_requests\\s*=.*/pm.max_requests = ${PHP_FPM_MAX_REQUESTS}/" "${PHP_FPM_POOL_CONF}"
    else
        printf '\npm.max_requests = %s\n' "${PHP_FPM_MAX_REQUESTS}" >> "${PHP_FPM_POOL_CONF}"
    fi
fi

# SQL surface hardening: when panel uses local DB host, never expose 3306 publicly.
PANEL_DB_HOST_EFFECTIVE="$(awk -F= '/^DB_HOST=/{print $2}' "${PANEL_ENV_FILE}" 2>/dev/null | tail -n1 | tr -d '"' | tr -d "'" | tr -d '[:space:]')"
if [[ -n "${PANEL_DB_HOST_EFFECTIVE}" ]]; then
    PANEL_DB_HOST_LC="$(printf '%s' "${PANEL_DB_HOST_EFFECTIVE}" | tr '[:upper:]' '[:lower:]')"
    if [[ "${PANEL_DB_HOST_LC}" == "127.0.0.1" || "${PANEL_DB_HOST_LC}" == "localhost" || "${PANEL_DB_HOST_LC}" == "::1" ]]; then
        echo "[setup] hardening local MariaDB exposure (bind localhost + block public 3306)..."
        MYSQL_SERVER_CNF="/etc/mysql/mariadb.conf.d/50-server.cnf"
        if [[ -f "${MYSQL_SERVER_CNF}" ]]; then
            cp -a "${MYSQL_SERVER_CNF}" "${MYSQL_SERVER_CNF}.bak.$(date -u +%Y%m%d%H%M%S)"
            if rg -n '^\s*bind-address\s*=' "${MYSQL_SERVER_CNF}" >/dev/null 2>&1; then
                sed -ri 's/^\s*bind-address\s*=.*/bind-address            = 127.0.0.1/' "${MYSQL_SERVER_CNF}"
            else
                printf '\n[mysqld]\nbind-address            = 127.0.0.1\n' >> "${MYSQL_SERVER_CNF}"
            fi
        fi
        cat > /etc/mysql/mariadb.conf.d/99-pteroprotect-hardening.cnf <<'EOF'
[mysqld]
local_infile=0
skip_name_resolve=1
EOF
        systemctl restart mariadb >/dev/null 2>&1 || true
        if command -v ufw >/dev/null 2>&1; then
            ufw --force delete allow 3306 >/dev/null 2>&1 || true
            ufw --force delete allow 3306/tcp >/dev/null 2>&1 || true
            ufw --force delete allow mysql >/dev/null 2>&1 || true
            ufw --force deny 3306/tcp >/dev/null 2>&1 || true
        fi
        iptables -C INPUT -p tcp --dport 3306 ! -s 127.0.0.1 -j DROP 2>/dev/null || iptables -I INPUT 1 -p tcp --dport 3306 ! -s 127.0.0.1 -j DROP
        ip6tables -C INPUT -p tcp --dport 3306 ! -s ::1 -j DROP 2>/dev/null || ip6tables -I INPUT 1 -p tcp --dport 3306 ! -s ::1 -j DROP
    fi
fi

if [[ -f "${INSTALL_DIR}/systemd/pteroprotect.service" ]]; then
    echo "[setup] installing systemd service..."
    install_rendered_systemd_unit "${INSTALL_DIR}/systemd/pteroprotect.service" "${SYSTEMD_DIR}/pteroprotect.service"
fi
if [[ -f "${INSTALL_DIR}/systemd/pteroprotect-hostguard.service" ]]; then
    echo "[setup] installing host firewall guard service..."
    install_rendered_systemd_unit "${INSTALL_DIR}/systemd/pteroprotect-hostguard.service" "${SYSTEMD_DIR}/pteroprotect-hostguard.service"
fi
if [[ -f "${INSTALL_DIR}/systemd/pteroprotect-ddoslog.service" ]]; then
    echo "[setup] installing ddos ram logger service..."
    install_rendered_systemd_unit "${INSTALL_DIR}/systemd/pteroprotect-ddoslog.service" "${SYSTEMD_DIR}/pteroprotect-ddoslog.service"
fi
if [[ -f "${INSTALL_DIR}/systemd/pteroprotect-unblock-portal.service" ]]; then
    echo "[setup] installing unblock portal service..."
    install_rendered_systemd_unit "${INSTALL_DIR}/systemd/pteroprotect-unblock-portal.service" "${SYSTEMD_DIR}/pteroprotect-unblock-portal.service"
fi
if [[ -f "${INSTALL_DIR}/systemd/pteroprotect-challenge.service" ]]; then
    echo "[setup] installing challenge api service..."
    install_rendered_systemd_unit "${INSTALL_DIR}/systemd/pteroprotect-challenge.service" "${SYSTEMD_DIR}/pteroprotect-challenge.service"
fi
if [[ -f "${INSTALL_DIR}/systemd/pteroprotect-panel-sync.service" ]]; then
    echo "[setup] installing panel override sync service..."
    install_rendered_systemd_unit "${INSTALL_DIR}/systemd/pteroprotect-panel-sync.service" "${SYSTEMD_DIR}/pteroprotect-panel-sync.service"
fi
if [[ -f "${INSTALL_DIR}/systemd/pteroprotect-selfheal.service" ]]; then
    echo "[setup] installing self-heal monitor service..."
    install_rendered_systemd_unit "${INSTALL_DIR}/systemd/pteroprotect-selfheal.service" "${SYSTEMD_DIR}/pteroprotect-selfheal.service"
fi
if [[ -f "${INSTALL_DIR}/systemd/pteroprotect-abuse-guard.service" ]]; then
    echo "[setup] installing runtime abuse guard service..."
    install_rendered_systemd_unit "${INSTALL_DIR}/systemd/pteroprotect-abuse-guard.service" "${SYSTEMD_DIR}/pteroprotect-abuse-guard.service"
fi
if [[ -f "${INSTALL_DIR}/systemd/pteroprotect-log-watch.service" ]]; then
    echo "[setup] installing security log watch service..."
    install_rendered_systemd_unit "${INSTALL_DIR}/systemd/pteroprotect-log-watch.service" "${SYSTEMD_DIR}/pteroprotect-log-watch.service"
fi
if [[ -f "${INSTALL_DIR}/systemd/pteroprotect-resilience.service" ]]; then
    echo "[setup] installing resilience orchestrator service..."
    install_rendered_systemd_unit "${INSTALL_DIR}/systemd/pteroprotect-resilience.service" "${SYSTEMD_DIR}/pteroprotect-resilience.service"
fi
if [[ -f "${INSTALL_DIR}/systemd/pteroprotect-resilience-collector.service" ]]; then
    echo "[setup] installing resilience collector service..."
    install_rendered_systemd_unit "${INSTALL_DIR}/systemd/pteroprotect-resilience-collector.service" "${SYSTEMD_DIR}/pteroprotect-resilience-collector.service"
fi

PANEL_OVERRIDE_SOURCE=""
if [[ -d "${PROJECT_DIR}/panel_overrides" ]]; then
    PANEL_OVERRIDE_SOURCE="${PROJECT_DIR}/panel_overrides"
elif [[ -d "${INSTALL_DIR}/panel_overrides" ]]; then
    PANEL_OVERRIDE_SOURCE="${INSTALL_DIR}/panel_overrides"
fi

PANEL_SYNC_WAS_ACTIVE=0
if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet pteroprotect-panel-sync; then
    PANEL_SYNC_WAS_ACTIVE=1
    systemctl stop pteroprotect-panel-sync >/dev/null 2>&1 || true
fi

if [[ -d "${PANEL_DIR}" && -n "${PANEL_OVERRIDE_SOURCE}" ]]; then
    echo "[setup] applying bundled Pterodactyl overrides from ${PANEL_OVERRIDE_SOURCE} to ${PANEL_DIR}..."
    ensure_panel_runtime_dirs "${PANEL_DIR}"
    PANEL_OVERRIDE_BACKUP_DIR="${BACKUP_DIR}/panel_overrides_$(date -u +%Y%m%d_%H%M%S)"
    backup_panel_override_targets "${PANEL_OVERRIDE_SOURCE}" "${PANEL_DIR}" "${PANEL_OVERRIDE_BACKUP_DIR}"
    copy_tree "${PANEL_OVERRIDE_SOURCE}" "${PANEL_DIR}"
    ensure_panel_autoconfigure_defaults "${PANEL_DIR}" "${PANEL_ENV_FILE}"

    # Reviactyl compatibility bridge:
    # ensure known extra frontend deps and line-clamp plugin are present
    # before dependency install/build is attempted.
    if [[ -f "${PANEL_DIR}/package.json" ]] && command -v python3 >/dev/null 2>&1; then
        python3 - "${PANEL_DIR}/package.json" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
try:
    pkg = json.loads(path.read_text())
except Exception:
    raise SystemExit(0)

deps = pkg.get("dependencies")
if not isinstance(deps, dict):
    deps = {}
    pkg["dependencies"] = deps

required = {
    "react-icons": "^5.5.0",
    "i18next-browser-languagedetector": "^8.2.0",
    "md5": "^2.3.0",
    "flag-icons": "^7.3.2",
}
changed = False
for name, version in required.items():
    if not deps.get(name):
        deps[name] = version
        changed = True

if changed:
    path.write_text(json.dumps(pkg, indent=4) + "\n")
PY
    fi

    if [[ -f "${PANEL_DIR}/tailwind.config.js" ]] && command -v python3 >/dev/null 2>&1; then
        python3 - "${PANEL_DIR}/tailwind.config.js" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

if "require('@tailwindcss/line-clamp')" in text:
    raise SystemExit(0)

marker = "require('@tailwindcss/forms')({\n            strategy: 'class',\n        }),"
if marker in text:
    text = text.replace(
        marker,
        marker + "\n        require('@tailwindcss/line-clamp'),",
        1,
    )
else:
    plugins_open = "plugins: ["
    idx = text.find(plugins_open)
    if idx != -1:
        end_idx = text.find("]", idx)
        if end_idx != -1:
            insertion = "\n        require('@tailwindcss/line-clamp'),"
            text = text[:end_idx] + insertion + text[end_idx:]

path.write_text(text)
PY
    fi

    if command -v php >/dev/null 2>&1 && [[ -f "${PANEL_DIR}/artisan" ]]; then
        lint_php_tree "${PANEL_OVERRIDE_SOURCE}" "${PANEL_DIR}"
        if ! (cd "${PANEL_DIR}" && php artisan migrate --force); then
            echo "[setup] warning: panel migration failed, overrides applied but DB schema may be outdated." >&2
        fi
        if ! (cd "${PANEL_DIR}" && php artisan storage:link >/dev/null 2>&1); then
            echo "[setup] warning: artisan storage:link failed (symlink may already exist or permissions are insufficient)." >&2
        fi
        (cd "${PANEL_DIR}" && php artisan optimize:clear >/dev/null 2>&1 || true)
        ensure_panel_runtime_dirs "${PANEL_DIR}"
    fi

    if [[ -f "${PANEL_DIR}/package.json" ]]; then
        SKIP_FRONTEND_BUILD="$(read_network_setting skip_frontend_build false)"
        if [[ "${SKIP_FRONTEND_BUILD}" == "true" || "${SKIP_FRONTEND_BUILD}" == "1" ]]; then
            echo "[setup] skipping panel frontend build (network.skip_frontend_build=true)."
        else
        echo "[setup] building panel frontend assets..."
        if ! command -v npx >/dev/null 2>&1 && ! command -v yarn >/dev/null 2>&1 && ! command -v yarnpkg >/dev/null 2>&1; then
            echo "[setup] installing node build tooling for panel assets..."
            apt-get install "${APT_INSTALL_OPTS[@]}" nodejs npm || true
        fi

        NODE_MAJOR="$(node_major_version)"
        NODE_VER="$(node -v 2>/dev/null || true)"
        if (( NODE_MAJOR > 0 && NODE_MAJOR < 22 )); then
            echo "[setup] node ${NODE_VER} detected (<22), attempting auto-upgrade to Node 22..."
            ensure_node22_runtime || true
            NODE_MAJOR="$(node_major_version)"
            NODE_VER="$(node -v 2>/dev/null || true)"
        fi

        if (( NODE_MAJOR > 0 && NODE_MAJOR < 22 )); then
            echo "[setup] warning: node ${NODE_VER} still <22 after auto-upgrade attempt; skipping frontend build." >&2
        elif (( NODE_MAJOR == 0 )); then
            echo "[setup] warning: node runtime unavailable; skipping frontend build." >&2
        else
            echo "[setup] installing panel frontend dependencies..."
            PANEL_INSTALL_OK=0
            PANEL_BUILD_OK=0
            PANEL_LOCK_BERRY=0
            PANEL_HAS_YARN_LOCK=0
            PANEL_ALLOW_NPM_FALLBACK=0
            if [[ -f "${PANEL_DIR}/yarn.lock" ]]; then
                PANEL_HAS_YARN_LOCK=1
            fi
            if (( PANEL_HAS_YARN_LOCK == 1 )) && grep -Eq '^[[:space:]]*__metadata:' "${PANEL_DIR}/yarn.lock"; then
                PANEL_LOCK_BERRY=1
            fi
            case "${PTEROPROTECT_ALLOW_NPM_PANEL_BUILD:-}" in
                1|true|TRUE|yes|YES|on|ON) PANEL_ALLOW_NPM_FALLBACK=1 ;;
            esac

            echo "[setup] using yarn-first frontend install path."
            if [[ -d "${PANEL_DIR}/node_modules" ]]; then
                echo "[setup] removing existing node_modules to avoid mixed npm/yarn state..."
                rm -rf "${PANEL_DIR}/node_modules"
            fi

            if command -v corepack >/dev/null 2>&1; then
                echo "[setup] trying corepack yarn..."
                (cd "${PANEL_DIR}" && corepack enable >/dev/null 2>&1 || true)
                if (( PANEL_LOCK_BERRY == 1 )); then
                    (cd "${PANEL_DIR}" && corepack prepare yarn@stable --activate >/dev/null 2>&1 || true)
                else
                    (cd "${PANEL_DIR}" && corepack prepare yarn@1.22.22 --activate >/dev/null 2>&1 || true)
                fi
                if (( PANEL_LOCK_BERRY == 1 )); then
                    if (cd "${PANEL_DIR}" && corepack yarn -s install --immutable); then
                        PANEL_INSTALL_OK=1
                    fi
                else
                    if (cd "${PANEL_DIR}" && corepack yarn -s install --frozen-lockfile); then
                        PANEL_INSTALL_OK=1
                    fi
                fi
                if (( PANEL_INSTALL_OK == 0 )) && (cd "${PANEL_DIR}" && corepack yarn -s install); then
                    PANEL_INSTALL_OK=1
                fi
                if (( PANEL_INSTALL_OK == 1 )); then
                    if (cd "${PANEL_DIR}" && corepack yarn -s build:production); then
                        PANEL_BUILD_OK=1
                    elif (cd "${PANEL_DIR}" && corepack yarn -s build); then
                        PANEL_BUILD_OK=1
                    fi
                fi
            fi

            if (( PANEL_INSTALL_OK == 0 )); then
                if command -v yarn >/dev/null 2>&1; then
                    echo "[setup] corepack yarn failed, trying system yarn..."
                    if (( PANEL_LOCK_BERRY == 1 )); then
                        (cd "${PANEL_DIR}" && yarn -s install --immutable) || true
                    else
                        (cd "${PANEL_DIR}" && yarn -s install --frozen-lockfile) || true
                    fi
                    if (cd "${PANEL_DIR}" && yarn -s install); then
                        PANEL_INSTALL_OK=1
                    fi
                    if (( PANEL_INSTALL_OK == 1 )) && (( PANEL_BUILD_OK == 0 )) && (cd "${PANEL_DIR}" && yarn -s build:production); then
                        PANEL_BUILD_OK=1
                    fi
                elif command -v yarnpkg >/dev/null 2>&1; then
                    echo "[setup] corepack yarn failed, trying yarnpkg..."
                    if (( PANEL_LOCK_BERRY == 1 )); then
                        (cd "${PANEL_DIR}" && yarnpkg -s install --immutable) || true
                    else
                        (cd "${PANEL_DIR}" && yarnpkg -s install --frozen-lockfile) || true
                    fi
                    if (cd "${PANEL_DIR}" && yarnpkg -s install); then
                        PANEL_INSTALL_OK=1
                    fi
                    if (( PANEL_INSTALL_OK == 1 )) && (( PANEL_BUILD_OK == 0 )) && (cd "${PANEL_DIR}" && yarnpkg -s build:production); then
                        PANEL_BUILD_OK=1
                    fi
                elif command -v npx >/dev/null 2>&1; then
                    echo "[setup] corepack yarn failed, trying npx yarn..."
                    if (( PANEL_LOCK_BERRY == 1 )); then
                        (cd "${PANEL_DIR}" && npx --yes yarn -s install --immutable) || true
                    else
                        (cd "${PANEL_DIR}" && npx --yes yarn -s install --frozen-lockfile) || true
                    fi
                    if (cd "${PANEL_DIR}" && npx --yes yarn -s install); then
                        PANEL_INSTALL_OK=1
                    fi
                    if (( PANEL_INSTALL_OK == 1 )) && (( PANEL_BUILD_OK == 0 )) && (cd "${PANEL_DIR}" && npx --yes yarn -s build:production); then
                        PANEL_BUILD_OK=1
                    fi
                fi
            fi

            if (( PANEL_BUILD_OK == 0 )) && command -v npm >/dev/null 2>&1; then
                if (( PANEL_HAS_YARN_LOCK == 0 || PANEL_ALLOW_NPM_FALLBACK == 1 )); then
                    echo "[setup] yarn path failed, trying npm fallback..."
                    rm -rf "${PANEL_DIR}/node_modules" "${PANEL_DIR}/package-lock.json" >/dev/null 2>&1 || true
                    if (cd "${PANEL_DIR}" && npm install --legacy-peer-deps --include=dev --no-audit --no-fund --loglevel=error); then
                        PANEL_INSTALL_OK=1
                        if (cd "${PANEL_DIR}" && npm run build:production --silent); then
                            PANEL_BUILD_OK=1
                        elif (cd "${PANEL_DIR}" && npm run build --silent); then
                            PANEL_BUILD_OK=1
                        fi
                    elif (cd "${PANEL_DIR}" && npm install --legacy-peer-deps --force --include=dev --no-audit --no-fund --loglevel=error); then
                        PANEL_INSTALL_OK=1
                        if (cd "${PANEL_DIR}" && npm run build:production --silent); then
                            PANEL_BUILD_OK=1
                        elif (cd "${PANEL_DIR}" && npm run build --silent); then
                            PANEL_BUILD_OK=1
                        fi
                    fi
                else
                    echo "[setup] warning: yarn.lock exists; npm fallback disabled to avoid mixed dependency tree." >&2
                    echo "[setup]          set PTEROPROTECT_ALLOW_NPM_PANEL_BUILD=1 to force npm fallback." >&2
                fi
            fi

            if (( PANEL_BUILD_OK == 0 )); then
                echo "[setup] warning: frontend build failed after yarn/corepack/npm attempts." >&2
            fi
        fi
        fi
    fi
fi

if (( PANEL_SYNC_WAS_ACTIVE == 1 )); then
    systemctl start pteroprotect-panel-sync >/dev/null 2>&1 || true
fi

if [[ -d "${NGINX_DIR}" && -d "${INSTALL_DIR}/host_overrides/nginx" ]]; then
    echo "[setup] applying bundled nginx host protection..."
    HTTP_CONN_LIMIT="$(read_network_setting http_conn_limit 60)"
    HTTP_REQ_RATE="$(read_network_setting http_req_rate 30)"
    HTTP_REQ_BURST="$(read_network_setting http_req_burst 60)"
    HTTP_AUTH_REQ_RATE_PER_MIN="$(read_network_setting http_auth_req_rate_per_min 20)"
    HTTP_AUTH_REQ_BURST="$(read_network_setting http_auth_req_burst 20)"
    CDN_STATIC_CACHE_TTL="$(read_network_setting cdn_static_cache_ttl 7d)"
    REAL_IP_ENABLED_RAW="$(read_network_setting real_ip_enabled false)"
    REAL_IP_HEADER="$(read_network_setting real_ip_header CF-Connecting-IP)"
    REAL_IP_RECURSIVE_RAW="$(read_network_setting real_ip_recursive true)"
    TRUSTED_PROXY_IPV4_CIDRS="$(read_network_setting trusted_proxy_ipv4_cidrs "")"
    TRUSTED_PROXY_IPV6_CIDRS="$(read_network_setting trusted_proxy_ipv6_cidrs "")"
    PROVIDER_TOKEN_GATE_ENABLED_RAW="$(read_network_setting provider_token_gate_enabled 0)"
    PROVIDER_TOKEN_IPV4_CIDRS="$(read_network_setting provider_token_ipv4_cidrs "")"
    PROVIDER_TOKEN_IPV6_CIDRS="$(read_network_setting provider_token_ipv6_cidrs "")"
    AUTH_CONN_LIMIT=20
    WEBSOCKET_CONN_LIMIT="$(read_network_setting websocket_conn_limit "")"
    WEBSOCKET_GLOBAL_CONN_LIMIT="$(read_network_setting websocket_global_conn_limit "")"
    NGINX_WORKER_CONNECTIONS="$(read_network_setting nginx_worker_connections 4096)"
    NGINX_WORKER_RLIMIT_NOFILE="$(read_network_setting nginx_worker_rlimit_nofile 131072)"
    WAF_MODSEC_ENABLED="$(read_config_path_value waf.modsec.enabled "$(read_network_setting waf_modsec_enabled true)")"
    WAF_CRS_PARANOIA_LEVEL="$(read_config_path_value waf.crs.paranoia_level "$(read_network_setting waf_crs_paranoia_level 1)")"
    WAF_CRS_INBOUND_THRESHOLD="$(read_config_path_value waf.crs.inbound_threshold "$(read_network_setting waf_crs_inbound_threshold 5)")"
    WAF_CRS_OUTBOUND_THRESHOLD="$(read_config_path_value waf.crs.outbound_threshold "$(read_network_setting waf_crs_outbound_threshold 4)")"
    RATE_AUTH_RATE="$(read_config_path_value rate_limits.auth.rate "$(read_network_setting rate_limit_auth_rate 5r/s)")"
    RATE_AUTH_BURST="$(read_config_path_value rate_limits.auth.burst "$(read_network_setting rate_limit_auth_burst 20)")"
    RATE_API_CLIENT_RATE="$(read_config_path_value rate_limits.api_client.rate "$(read_network_setting rate_limit_api_client_rate 30r/s)")"
    RATE_API_APPLICATION_RATE="$(read_config_path_value rate_limits.api_application.rate "$(read_network_setting rate_limit_api_application_rate 60r/s)")"
    RATE_STATIC_RATE="$(read_config_path_value rate_limits.static.rate "$(read_network_setting rate_limit_static_rate 120r/s)")"

    MODSEC_RUNTIME_ENABLED=0
    if [[ "${WAF_MODSEC_ENABLED,,}" == "true" || "${WAF_MODSEC_ENABLED}" == "1" ]]; then
        MODSEC_PACKAGES=()
        
        # Try to install modsecurity module (available in ondrej/nginx PPA for nginx 1.18)
        if apt-cache show libnginx-mod-http-modsecurity >/dev/null 2>&1; then
            MODSEC_PACKAGES+=(libnginx-mod-http-modsecurity)
        elif command -v add-apt-repository >/dev/null 2>&1; then
            # Try ondrej/nginx PPA for modsecurity support
            echo "[setup] attempting to add ondrej/nginx PPA for ModSecurity module..."
            add-apt-repository -y ppa:ondrej/nginx-mainline >/dev/null 2>&1 && apt-get update >/dev/null 2>&1
            if apt-cache show libnginx-mod-http-modsecurity >/dev/null 2>&1; then
                MODSEC_PACKAGES+=(libnginx-mod-http-modsecurity)
            fi
        fi
        
        if apt-cache show modsecurity-crs >/dev/null 2>&1; then
            MODSEC_PACKAGES+=(modsecurity-crs)
        fi
        
        if (( ${#MODSEC_PACKAGES[@]} > 0 )); then
            echo "[setup] installing ModSecurity stack: ${MODSEC_PACKAGES[*]}"
            apt-get install "${APT_INSTALL_OPTS[@]}" "${MODSEC_PACKAGES[@]}"
            
            # Verify module was installed and enable it in nginx
            if [[ -f /usr/share/nginx/modules-available/mod-http-modsecurity.load ]]; then
                echo "[setup] enabling modsecurity module in nginx..."
                ln -sf /usr/share/nginx/modules-available/mod-http-modsecurity.load /etc/nginx/modules-enabled/50-mod-http-modsecurity.load
                MODSEC_RUNTIME_ENABLED=1
            elif dpkg -l | grep -q libnginx-mod-http-modsecurity; then
                # Module installed but symlink doesn't exist, try to find and enable it
                echo "[setup] enabling installed modsecurity module..."
                find /usr/share/nginx/modules-available -name "*modsecurity*" -type f | while read -r mod_file; do
                    ln -sf "$mod_file" "/etc/nginx/modules-enabled/$(basename "$mod_file")" 2>/dev/null
                done
                MODSEC_RUNTIME_ENABLED=1
            else
                echo "[setup] warning: modsecurity module installation failed or module files not found" >&2
            fi
        else
            echo "[setup] warning: no ModSecurity packages found in apt repositories." >&2
        fi
    fi
    if [[ "${HTTP_CONN_LIMIT}" =~ ^[0-9]+$ ]]; then
        if (( HTTP_CONN_LIMIT < AUTH_CONN_LIMIT )); then
            AUTH_CONN_LIMIT="${HTTP_CONN_LIMIT}"
        fi
    fi
    if [[ ! "${WEBSOCKET_CONN_LIMIT}" =~ ^[0-9]+$ ]]; then
        if [[ "${HTTP_CONN_LIMIT}" =~ ^[0-9]+$ ]]; then
            WEBSOCKET_CONN_LIMIT="${HTTP_CONN_LIMIT}"
        else
            WEBSOCKET_CONN_LIMIT=100
        fi
    fi
    if (( WEBSOCKET_CONN_LIMIT < 80 )); then
        WEBSOCKET_CONN_LIMIT=80
    elif (( WEBSOCKET_CONN_LIMIT > 400 )); then
        WEBSOCKET_CONN_LIMIT=400
    fi
    if [[ ! "${WEBSOCKET_GLOBAL_CONN_LIMIT}" =~ ^[0-9]+$ ]]; then
        WEBSOCKET_GLOBAL_CONN_LIMIT=$(( WEBSOCKET_CONN_LIMIT * 20 ))
    fi
    if [[ "${RATE_AUTH_BURST}" =~ ^[0-9]+$ ]]; then
        HTTP_AUTH_REQ_BURST="${RATE_AUTH_BURST}"
    fi
    if (( WEBSOCKET_GLOBAL_CONN_LIMIT < 400 )); then
        WEBSOCKET_GLOBAL_CONN_LIMIT=400
    elif (( WEBSOCKET_GLOBAL_CONN_LIMIT > 5000 )); then
        WEBSOCKET_GLOBAL_CONN_LIMIT=5000
    fi
    if [[ ! "${NGINX_WORKER_CONNECTIONS}" =~ ^[0-9]+$ ]] || (( NGINX_WORKER_CONNECTIONS < 1024 )); then
        NGINX_WORKER_CONNECTIONS=4096
    elif (( NGINX_WORKER_CONNECTIONS > 65535 )); then
        NGINX_WORKER_CONNECTIONS=65535
    fi
    if [[ ! "${NGINX_WORKER_RLIMIT_NOFILE}" =~ ^[0-9]+$ ]] || (( NGINX_WORKER_RLIMIT_NOFILE < 65535 )); then
        NGINX_WORKER_RLIMIT_NOFILE=131072
    elif (( NGINX_WORKER_RLIMIT_NOFILE > 1048576 )); then
        NGINX_WORKER_RLIMIT_NOFILE=1048576
    fi
    python3 - "${NGINX_DIR}/nginx.conf" "${NGINX_WORKER_CONNECTIONS}" "${NGINX_WORKER_RLIMIT_NOFILE}" <<'PY'
import pathlib
import re
import sys

path = pathlib.Path(sys.argv[1])
connections = int(sys.argv[2])
rlimit = int(sys.argv[3])

if not path.exists():
    raise SystemExit(0)

text = path.read_text()

# Ensure worker_rlimit_nofile is present and tuned.
if re.search(r'^\s*worker_rlimit_nofile\s+\d+;\s*$', text, re.M):
    text = re.sub(r'^\s*worker_rlimit_nofile\s+\d+;\s*$',
                  f'worker_rlimit_nofile {rlimit};',
                  text, count=1, flags=re.M)
else:
    text = re.sub(r'^(worker_processes\s+\S+;\s*)$',
                  r'\1\nworker_rlimit_nofile ' + str(rlimit) + ';',
                  text, count=1, flags=re.M)

# Tune events worker_connections.
def patch_events_block(match):
    block = match.group(0)
    if re.search(r'worker_connections\s+\d+;', block):
        block = re.sub(r'worker_connections\s+\d+;', f'worker_connections {connections};', block, count=1)
    else:
        block = block.replace('{', '{\n    worker_connections ' + str(connections) + ';', 1)
    return block

text = re.sub(r'events\s*\{[\s\S]*?\}', patch_events_block, text, count=1)
path.write_text(text)
PY
    mkdir -p "${NGINX_DIR}/conf.d" "${NGINX_DIR}/snippets"
    cp "${INSTALL_DIR}/host_overrides/nginx/conf.d/pteroprotect_http_zones.conf" "${NGINX_DIR}/conf.d/pteroprotect_http_zones.conf"
    cp "${INSTALL_DIR}/host_overrides/nginx/conf.d/pteroprotect_realip.conf" "${NGINX_DIR}/conf.d/pteroprotect_realip.conf"
    cp "${INSTALL_DIR}/host_overrides/nginx/conf.d/pteroprotect_provider_gate.conf" "${NGINX_DIR}/conf.d/pteroprotect_provider_gate.conf"
    cp "${INSTALL_DIR}/host_overrides/nginx/snippets/pteroprotect_server.conf" "${NGINX_DIR}/snippets/pteroprotect_server.conf"
    if [[ "${MODSEC_RUNTIME_ENABLED}" != "1" ]]; then
        echo "[setup] removing modsecurity directives from nginx config (module not available)..."
        perl -0pi -e 's/\s*modsecurity on;?\s*\n?//g; s/\s*modsecurity_rules_file [^;]+;?\s*\n?//g;' "${NGINX_DIR}/snippets/pteroprotect_server.conf"
    fi
    if [[ -d "${INSTALL_DIR}/host_overrides/nginx/modsec" ]]; then
        mkdir -p "${NGINX_DIR}/modsec/rules"
        cp "${INSTALL_DIR}/host_overrides/nginx/modsec/main.conf" "${NGINX_DIR}/modsec/main.conf"
        cp "${INSTALL_DIR}/host_overrides/nginx/modsec/crs-setup.conf" "${NGINX_DIR}/modsec/crs-setup.conf"
        cp "${INSTALL_DIR}/host_overrides/nginx/modsec/local-exclusions.conf" "${NGINX_DIR}/modsec/local-exclusions.conf"
        if [[ -d /usr/share/modsecurity-crs/rules ]]; then
            cp -f /usr/share/modsecurity-crs/rules/*.conf "${NGINX_DIR}/modsec/rules/" 2>/dev/null || true
        elif [[ -d /usr/share/modsecurity-crs/owasp-crs/rules ]]; then
            cp -f /usr/share/modsecurity-crs/owasp-crs/rules/*.conf "${NGINX_DIR}/modsec/rules/" 2>/dev/null || true
        fi
        perl -0pi -e "s/setvar:tx.paranoia_level=\\d+/setvar:tx.paranoia_level=${WAF_CRS_PARANOIA_LEVEL}/g; s/setvar:tx.inbound_anomaly_score_threshold=\\d+/setvar:tx.inbound_anomaly_score_threshold=${WAF_CRS_INBOUND_THRESHOLD}/g; s/setvar:tx.outbound_anomaly_score_threshold=\\d+/setvar:tx.outbound_anomaly_score_threshold=${WAF_CRS_OUTBOUND_THRESHOLD}/g;" "${NGINX_DIR}/modsec/crs-setup.conf"
        mkdir -p /var/log/nginx
        touch /var/log/nginx/modsec_audit.log
    fi
    if [[ ! "${CDN_STATIC_CACHE_TTL}" =~ ^[0-9]+[smhdw]$ ]]; then
        CDN_STATIC_CACHE_TTL="7d"
    fi
    perl -0pi -e "s/__PP_STATIC_TTL__/${CDN_STATIC_CACHE_TTL}/g;" "${NGINX_DIR}/snippets/pteroprotect_server.conf"
    # Keep static cache lane deterministic: never fall back into Laravel.
    python3 - "${NGINX_DIR}/snippets/pteroprotect_server.conf" <<'PY'
import pathlib
import sys
import re
import sys

path = pathlib.Path(sys.argv[1])
text = path.read_text()
pattern = re.compile(
    r'(location ~\* \\\.\(\?:css\|js\|mjs\|map\|jpg\|jpeg\|gif\|png\|webp\|avif\|ico\|svg\|woff\|woff2\|ttf\|eot\)\$ \{\n'
    r'(?:    .*\n)*?)'
    r'(    add_header Cache-Control "public, immutable"(?: always)?;\n)?'
    r'(?:    access_log off;\n)?'
    r'(?:    try_files \$uri \$uri/ /index\.php\?\$query_string;\n|    try_files \$uri =404;\n)?'
    r'(\})',
    re.M,
)
m = pattern.search(text)
if m:
    start = m.group(1)
    end = m.group(3)
    replacement = (
        f"{start}"
        '    add_header Cache-Control "public, immutable" always;\n'
        "    access_log off;\n"
        "    try_files $uri =404;\n"
        f"{end}"
    )
    text = text[:m.start()] + replacement + text[m.end():]
    path.write_text(text)
PY
    if [[ "${RATE_AUTH_RATE}" =~ ^[0-9]+r/s$ ]]; then
        HTTP_AUTH_REQ_RATE_PER_MIN="${RATE_AUTH_RATE}"
    elif [[ "${HTTP_AUTH_REQ_RATE_PER_MIN}" =~ ^[0-9]+$ ]]; then
        HTTP_AUTH_REQ_RATE_PER_MIN="${HTTP_AUTH_REQ_RATE_PER_MIN}r/m"
    else
        HTTP_AUTH_REQ_RATE_PER_MIN="5r/s"
    fi
    [[ "${HTTP_REQ_RATE}" =~ ^[0-9]+$ ]] && HTTP_REQ_RATE="${HTTP_REQ_RATE}r/s"
    [[ "${RATE_API_CLIENT_RATE}" =~ ^[0-9]+r/s$ ]] || RATE_API_CLIENT_RATE="400r/s"
    [[ "${RATE_API_APPLICATION_RATE}" =~ ^[0-9]+r/s$ ]] || RATE_API_APPLICATION_RATE="600r/s"
    [[ "${RATE_STATIC_RATE}" =~ ^[0-9]+r/s$ ]] || RATE_STATIC_RATE="30r/s"
    perl -0pi -e "s#(zone=pteroprotect_req:20m rate=)\\d+r/s;#\${1}${HTTP_REQ_RATE};#g; s#(zone=pteroprotect_auth:10m rate=)\\d+r/[sm];#\${1}${HTTP_AUTH_REQ_RATE_PER_MIN};#g; s#(zone=pteroprotect_api_key_req:20m rate=)\\d+r/s;#\${1}${RATE_API_CLIENT_RATE};#g; s#(zone=pteroprotect_api_global_req:10m rate=)\\d+r/s;#\${1}${RATE_API_APPLICATION_RATE};#g; s#(zone=pteroprotect_global_req:10m rate=)\\d+r/s;#\${1}${RATE_STATIC_RATE};#g;" "${NGINX_DIR}/conf.d/pteroprotect_http_zones.conf"
    python3 - "${NGINX_DIR}/conf.d/pteroprotect_realip.conf" "${REAL_IP_ENABLED_RAW}" "${REAL_IP_HEADER}" "${REAL_IP_RECURSIVE_RAW}" "${TRUSTED_PROXY_IPV4_CIDRS}" "${TRUSTED_PROXY_IPV6_CIDRS}" <<'PY'
import ipaddress
import pathlib
import sys


def as_bool(value: str) -> bool:
    return str(value).strip().lower() in {"1", "true", "yes", "on"}


def parse_csv(value: str):
    out = []
    for item in str(value).split(","):
        item = item.strip()
        if item:
            out.append(item)
    return out


def normalize_cidrs(raw_list, version: int):
    normalized = []
    seen = set()
    for item in raw_list:
        try:
            net = ipaddress.ip_network(item, strict=False)
        except Exception:
            continue
        if net.version != version:
            continue
        text = net.with_prefixlen
        if text not in seen:
            seen.add(text)
            normalized.append(text)
    return normalized


path = pathlib.Path(sys.argv[1])
enabled = as_bool(sys.argv[2])
header = str(sys.argv[3]).strip()
recursive = as_bool(sys.argv[4])
v4_raw = parse_csv(sys.argv[5])
v6_raw = parse_csv(sys.argv[6])

allowed_headers = {"CF-Connecting-IP", "X-Forwarded-For", "X-Real-IP", "True-Client-IP"}
if header not in allowed_headers:
    header = "CF-Connecting-IP"

v4 = normalize_cidrs(v4_raw, 4)
v6 = normalize_cidrs(v6_raw, 6)

lines = [
    "# managed by pteroprotect setup.sh",
    "# real_ip trust chain for CDN / reverse proxy edges",
]
if enabled and (v4 or v6):
    lines.append(f"real_ip_header {header};")
    lines.append(f"real_ip_recursive {'on' if recursive else 'off'};")
    for cidr in v4:
        lines.append(f"set_real_ip_from {cidr};")
    for cidr in v6:
        lines.append(f"set_real_ip_from {cidr};")
else:
    lines.extend(
        [
            "# real_ip disabled (set network.real_ip_enabled=true and provide trusted_proxy_*_cidrs)",
            "# no set_real_ip_from entries were applied",
        ]
    )

path.write_text("\n".join(lines) + "\n")
PY

    python3 - "${NGINX_DIR}/conf.d/pteroprotect_provider_gate.conf" "${PROVIDER_TOKEN_GATE_ENABLED_RAW}" "${PROVIDER_TOKEN_IPV4_CIDRS}" "${PROVIDER_TOKEN_IPV6_CIDRS}" <<'PY'
import ipaddress
import pathlib
import sys


def as_bool(value: str) -> bool:
    return str(value).strip().lower() in {"1", "true", "yes", "on"}


def parse_csv(value: str):
    out = []
    for item in str(value).split(","):
        item = item.strip()
        if item:
            out.append(item)
    return out


def normalize_cidrs(raw_list, version: int):
    normalized = []
    seen = set()
    for item in raw_list:
        try:
            net = ipaddress.ip_network(item, strict=False)
        except Exception:
            continue
        if net.version != version:
            continue
        text = net.with_prefixlen
        if text not in seen:
            seen.add(text)
            normalized.append(text)
    return normalized


path = pathlib.Path(sys.argv[1])
enabled = as_bool(sys.argv[2])
v4 = normalize_cidrs(parse_csv(sys.argv[3]), 4)
v6 = normalize_cidrs(parse_csv(sys.argv[4]), 6)

lines = [
    "# managed by pteroprotect setup.sh",
    "# Provider-range token gate:",
    "# if client IP is in listed provider CIDR and request has no token -> block.",
    "",
    "map $http_authorization $pteroprotect_req_has_bearer {",
    "    default 0;",
    "    ~*^Bearer\\s+.+$ 1;",
    "}",
    "",
    "map $http_x_api_key $pteroprotect_req_has_x_api_key {",
    "    default 0;",
    "    ~.+ 1;",
    "}",
    "",
    "map $arg_token $pteroprotect_req_has_arg_token {",
    "    default 0;",
    "    ~.+ 1;",
    "}",
    "",
    "map \"$pteroprotect_req_has_bearer$pteroprotect_req_has_x_api_key$pteroprotect_req_has_arg_token\" $pteroprotect_req_has_any_token {",
    "    default 0;",
    "    ~1 1;",
    "}",
    "",
    "geo $pteroprotect_provider_token_range {",
    "    default 0;",
]

if enabled:
    for cidr in v4:
        lines.append(f"    {cidr} 1;")
    for cidr in v6:
        lines.append(f"    {cidr} 1;")

lines += [
    "}",
    "",
    "map \"$pteroprotect_provider_token_range:$pteroprotect_req_has_any_token\" $pteroprotect_provider_token_block {",
    "    default 0;",
    "    \"1:0\" 1;",
    "}",
]

if not enabled:
    lines.append("")
    lines.append("# disabled (set network.provider_token_gate_enabled=true and add CIDRs)")

path.write_text("\n".join(lines) + "\n")
PY

    # Resolve the active panel vhost config path across distro/custom layouts.
    PANEL_NGINX_CONF=""
    if [[ -e "${NGINX_DIR}/sites-enabled/pterodactyl.conf" ]]; then
        PANEL_NGINX_CONF="$(readlink -f "${NGINX_DIR}/sites-enabled/pterodactyl.conf" 2>/dev/null || true)"
    fi
    if [[ -z "${PANEL_NGINX_CONF}" && -f "${NGINX_DIR}/sites-available/pterodactyl.conf" ]]; then
        PANEL_NGINX_CONF="${NGINX_DIR}/sites-available/pterodactyl.conf"
    fi
    if [[ -z "${PANEL_NGINX_CONF}" ]]; then
        PANEL_NGINX_CONF="$(grep -Rsl "root ${PANEL_DIR}/public;" "${NGINX_DIR}/sites-available" "${NGINX_DIR}/sites-enabled" "${NGINX_DIR}/conf.d" 2>/dev/null | head -n1 || true)"
    fi
    if [[ -n "${PANEL_NGINX_CONF}" && ! -f "${PANEL_NGINX_CONF}" ]]; then
        PANEL_NGINX_CONF=""
    fi
    python3 - "${NGINX_DIR}/snippets/pteroprotect_server.conf" "${AUTH_CONN_LIMIT}" "${HTTP_AUTH_REQ_BURST}" <<'PY'
import pathlib
import re
import sys

path = pathlib.Path(sys.argv[1])
auth_conn_limit = sys.argv[2]
auth_burst = sys.argv[3]
text = path.read_text()
for block in ("location = /auth/login",):
    pattern = re.compile(
        rf'({re.escape(block)} \{{\n)'
        r'(?:    auth_request /__pteroprotect/challenge/check;\n)?'
        r'(?:    error_page 401 = @pteroprotect_challenge_redirect;\n)?'
        r'(?:    limit_conn pteroprotect_conn \d+;\n)?'
        r'(?:    limit_conn pteroprotect_auth_global_conn \d+;\n)?'
        r'(?:    limit_req zone=pteroprotect_auth_global_req burst=\d+ nodelay;\n)?'
        r'(?:    limit_req zone=pteroprotect_auth burst=\d+ nodelay;\n)?'
        r'    try_files \$uri \$uri/ /index\.php\?\$query_string;\n'
        r'\}\n'
    )
    replacement = (
        f"{block} {{\n"
        "    auth_request /__pteroprotect/challenge/check;\n"
        "    error_page 401 = @pteroprotect_challenge_redirect;\n"
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
    "    auth_request /__pteroprotect/challenge/check;\n"
    "    error_page 401 = @pteroprotect_challenge_redirect;\n"
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
    r'(?:    auth_request /__pteroprotect/challenge/check_token;\n)?'
    r'(?:    error_page 401 = @pteroprotect_challenge_redirect;\n)?'
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
    r'(?:    auth_request /__pteroprotect/challenge/check_token;\n)?'
    r'(?:    error_page 401 = @pteroprotect_challenge_redirect;\n)?'
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

    python3 - "${NGINX_DIR}/snippets/pteroprotect_server.conf" "${CHALLENGE_PORT:-18444}" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
challenge_port = (sys.argv[2] if len(sys.argv) > 2 else "18444").strip() or "18444"
challenge_upstream = f"127.0.0.1:{challenge_port}"
text = path.read_text()

text = text.replace(
    "error_page 461 = @pteroprotect_provider_token_required;\n",
    "error_page 461 = @pteroprotect_provider_token_required;\nerror_page 463 = @pteroprotect_provider_web_block;\n",
)
if "location @pteroprotect_provider_web_block {" not in text:
    marker = "location @pteroprotect_provider_token_required {\n"
    start = text.find(marker)
    if start != -1:
        end = text.find("}\n", start)
        if end != -1:
            end += 2
            block = (
                "\nlocation @pteroprotect_provider_web_block {\n"
                "    internal;\n"
                "    default_type text/html;\n"
                "    add_header Cache-Control \"no-store\" always;\n"
                "    add_header X-PteroProtect-Code \"PP-WAF-463\" always;\n"
                "    add_header X-PteroProtect-Reason \"provider-web-blocked\" always;\n"
                "    return 463 '<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>PteroProtect WAF</title><style>:root{--bg:#0f172a;--card:#111827;--line:#243245;--text:#e5e7eb;--muted:#9ca3af;--accent:#22d3ee}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:radial-gradient(1000px 500px at 20% 10%,#1f3b58 0,#0f172a 55%);font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;color:var(--text)}.card{width:min(92vw,640px);background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.01));border:1px solid var(--line);border-radius:16px;padding:28px 24px;box-shadow:0 18px 45px rgba(0,0,0,.45)}.badge{display:inline-block;padding:6px 10px;border-radius:999px;background:rgba(34,211,238,.15);color:#67e8f9;font-weight:700;font-size:12px;letter-spacing:.4px}h1{margin:14px 0 10px;font-size:28px;line-height:1.2}.sub{color:var(--muted);line-height:1.6;margin:0 0 14px}.row{display:flex;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:rgba(15,23,42,.55)}.k{color:#93c5fd}.v{font-weight:700}small{display:block;color:#64748b;margin-top:14px}</style></head><body><main class=\"card\"><span class=\"badge\">PteroProtect WAF</span><h1>Akses Web Ditolak</h1><p class=\"sub\">Akses panel/web dari range IP provider diblok. Jalur yang diizinkan hanya API dengan bearer token panel yang valid.</p><div class=\"row\"><span class=\"k\">Error Code</span><span class=\"v\">PP-WAF-463</span></div><small>Gunakan API resmi dengan header <code>Authorization: Bearer ptlc_...</code> atau <code>ptla_...</code>.</small></main></body></html>';\n"
                "}\n"
            )
            text = text[:end] + block + text[end:]

check_location = f"""location = /__pteroprotect/challenge/check {{\n    internal;\n    proxy_pass http://{challenge_upstream}/check;\n    proxy_http_version 1.1;\n    proxy_set_header Host $host;\n    proxy_set_header X-Real-IP $remote_addr;\n    proxy_set_header X-Forwarded-For $remote_addr;\n    proxy_set_header CF-Connecting-IP $remote_addr;\n    proxy_set_header X-PteroProtect-Internal 1;\n    proxy_set_header User-Agent $http_user_agent;\n    proxy_set_header Content-Length \"\";\n    proxy_pass_request_body off;\n    proxy_intercept_errors on;\n    error_page 500 502 503 504 = @pteroprotect_challenge_deny;\n    proxy_connect_timeout 300ms;\n    proxy_send_timeout 1s;\n    proxy_read_timeout 1s;\n}}\n"""
if "location = /__pteroprotect/challenge/check_web {" not in text and check_location in text:
    insert = check_location + f"\nlocation = /__pteroprotect/challenge/check_web {{\n    internal;\n    proxy_pass http://{challenge_upstream}/check-web;\n    proxy_http_version 1.1;\n    proxy_set_header Host $host;\n    proxy_set_header X-Real-IP $remote_addr;\n    proxy_set_header X-Forwarded-For $remote_addr;\n    proxy_set_header CF-Connecting-IP $remote_addr;\n    proxy_set_header X-PteroProtect-Internal 1;\n    proxy_set_header User-Agent $http_user_agent;\n    proxy_set_header Cookie $http_cookie;\n    proxy_set_header Content-Length \"\";\n    proxy_pass_request_body off;\n    proxy_intercept_errors on;\n    error_page 500 502 503 504 = @pteroprotect_challenge_deny;\n    proxy_connect_timeout 300ms;\n    proxy_send_timeout 1s;\n    proxy_read_timeout 1s;\n}}\n"
    text = text.replace(check_location, insert)

check_token_location = f"""location = /__pteroprotect/challenge/check_token {{\n    internal;\n    proxy_pass http://{challenge_upstream}/check-token;\n    proxy_http_version 1.1;\n    proxy_set_header Host $host;\n    proxy_set_header X-Real-IP $remote_addr;\n    proxy_set_header X-Forwarded-For $remote_addr;\n    proxy_set_header CF-Connecting-IP $remote_addr;\n    proxy_set_header X-PteroProtect-Internal 1;\n    proxy_set_header User-Agent $http_user_agent;\n    proxy_set_header Authorization $http_authorization;\n    proxy_set_header X-API-Key $http_x_api_key;\n    proxy_set_header Content-Length \"\";\n    proxy_pass_request_body off;\n    proxy_intercept_errors on;\n    error_page 500 502 503 504 = @pteroprotect_challenge_deny;\n    proxy_connect_timeout 300ms;\n    proxy_send_timeout 1s;\n    proxy_read_timeout 1s;\n}}\n"""
if "location = /__pteroprotect/challenge/check_provider_api {" not in text and check_token_location in text:
    insert = check_token_location + f"\nlocation = /__pteroprotect/challenge/check_provider_api {{\n    internal;\n    proxy_pass http://{challenge_upstream}/check-provider-api;\n    proxy_http_version 1.1;\n    proxy_set_header Host $host;\n    proxy_set_header X-Real-IP $remote_addr;\n    proxy_set_header X-Forwarded-For $remote_addr;\n    proxy_set_header CF-Connecting-IP $remote_addr;\n    proxy_set_header X-PteroProtect-Internal 1;\n    proxy_set_header User-Agent $http_user_agent;\n    proxy_set_header Authorization $http_authorization;\n    proxy_set_header X-API-Key $http_x_api_key;\n    proxy_set_header Content-Length \"\";\n    proxy_pass_request_body off;\n    proxy_intercept_errors on;\n    error_page 500 502 503 504 = @pteroprotect_challenge_deny;\n    proxy_connect_timeout 300ms;\n    proxy_send_timeout 1s;\n    proxy_read_timeout 1s;\n}}\n"
    text = text.replace(check_token_location, insert)

for old, new in [
    ("auth_request /__pteroprotect/challenge/check;\n    error_page 401 = @pteroprotect_challenge_redirect;\n", "auth_request /__pteroprotect/challenge/check_web;\n    error_page 401 = @pteroprotect_challenge_redirect;\n    error_page 403 = @pteroprotect_provider_web_block;\n"),
    ("if ($pteroprotect_provider_token_block) { return 461; }\n", "auth_request /__pteroprotect/challenge/check_provider_api;\n    error_page 401 = @pteroprotect_provider_token_required;\n"),
]:
    text = text.replace(old, new)

for marker in [
    "location ~* ^/api/client/servers/[^/]+/websocket$ {\n    if (-f /dev/shm/pteroprotect/brownout.flag) { return 503; }\n",
    "location ~* ^/api/client/servers/[^/]+/resources$ {\n    if (-f /dev/shm/pteroprotect/brownout.flag) { return 503; }\n",
    "location /api/application/ {\n",
    "location /api/ {\n",
]:
    if marker in text and "check_provider_api" not in text[text.find(marker):text.find(marker)+220]:
        replacement = marker + "    auth_request /__pteroprotect/challenge/check_provider_api;\n    error_page 401 = @pteroprotect_provider_token_required;\n"
        text = text.replace(marker, replacement, 1)

path.write_text(text)
PY

    if [[ -n "${PANEL_NGINX_CONF}" ]]; then
    python3 - "${PANEL_NGINX_CONF}" <<'PY'
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
    # Keep this idempotent: remove existing challenge directives anywhere
    # inside the location block before re-inserting canonical lines.
    block_body = re.sub(
        r'^\s*auth_request\s+/__pteroprotect/challenge/check;\s*\n',
        '',
        block_body,
        flags=re.MULTILINE,
    )
    block_body = re.sub(
        r'^\s*error_page\s+401\s*=\s*@pteroprotect_challenge_redirect;\s*\n',
        '',
        block_body,
        flags=re.MULTILINE,
    )
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
    fi

    if [[ -n "${PANEL_NGINX_CONF}" && -f "${PANEL_NGINX_CONF}" ]] && ! grep -q "pteroprotect_server.conf" "${PANEL_NGINX_CONF}"; then
        perl -0pi -e 's/server_name\s+([^;]+);\n/server_name $1;\n\n    include \/etc\/nginx\/snippets\/pteroprotect_server.conf;\n/' "${PANEL_NGINX_CONF}"
    fi

    if [[ -n "${PANEL_NGINX_CONF}" && -f "${PANEL_NGINX_CONF}" ]]; then
        perl -0pi -e 's/access_log\s+off;/access_log \/var\/log\/nginx\/pteroprotect.access.log combined;/g;' "${PANEL_NGINX_CONF}"
        perl -0pi -e "s/limit_conn pteroprotect_conn \\d+;/limit_conn pteroprotect_conn ${HTTP_CONN_LIMIT};/g; s/limit_req zone=pteroprotect_req burst=\\d+ nodelay;/limit_req zone=pteroprotect_req burst=${HTTP_REQ_BURST} nodelay;/g;" "${PANEL_NGINX_CONF}"
        if ! grep -q "limit_conn pteroprotect_conn ${HTTP_CONN_LIMIT};" "${PANEL_NGINX_CONF}"; then
            python3 - "${PANEL_NGINX_CONF}" "${HTTP_CONN_LIMIT}" "${HTTP_REQ_BURST}" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
conn_limit = sys.argv[2]
req_burst = sys.argv[3]
text = path.read_text()
needle = "    location / {\n"
insert = (
    "    location / {\n"
    f"        limit_conn pteroprotect_conn {conn_limit};\n"
    f"        limit_req zone=pteroprotect_req burst={req_burst} nodelay;\n"
)

if needle in text and f"limit_conn pteroprotect_conn {conn_limit};" not in text:
    text = text.replace(needle, insert, 1)
    path.write_text(text)
PY
        fi

        python3 - "${PANEL_NGINX_CONF}" "${HTTP_CONN_LIMIT}" "${HTTP_REQ_BURST}" <<'PY'
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
    "        auth_request /__pteroprotect/challenge/check;\n"
    "        error_page 401 = @pteroprotect_challenge_redirect;\n"
    f"        limit_conn pteroprotect_conn {conn_limit};\n"
    f"        limit_req zone=pteroprotect_req burst={req_burst} nodelay;\n"
    "        try_files $uri $uri/ /index.php?$query_string;\n"
    "    }\n"
)
text = pattern.sub(replacement, text, count=1)
path.write_text(text)
PY

    python3 - "${PANEL_NGINX_CONF}" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
text = path.read_text()
text = text.replace(
    "        auth_request /__pteroprotect/challenge/check;\n        error_page 401 = @pteroprotect_challenge_redirect;\n",
    "        auth_request /__pteroprotect/challenge/check_web;\n        error_page 401 = @pteroprotect_challenge_redirect;\n        error_page 403 = @pteroprotect_provider_web_block;\n",
)
path.write_text(text)
PY
    else
        echo "[setup] warning: panel nginx vhost not found; skip vhost patching." >&2
    fi

    # Normalize duplicated challenge directives from previous/manual edits
    # so repeated setup runs remain nginx-safe.
    DEDUPE_TARGETS=("${NGINX_DIR}/snippets/pteroprotect_server.conf")
    if [[ -n "${PANEL_NGINX_CONF}" ]]; then
        DEDUPE_TARGETS=("${PANEL_NGINX_CONF}" "${DEDUPE_TARGETS[@]}")
    fi
    if [[ -e "${NGINX_DIR}/sites-enabled/pterodactyl.conf" ]]; then
        DEDUPE_TARGETS+=("${NGINX_DIR}/sites-enabled/pterodactyl.conf")
    fi
    python3 - "${DEDUPE_TARGETS[@]}" <<'PY'
from pathlib import Path
import re
import sys

targets = [Path(p) for p in sys.argv[1:] if p]

def line_key(line: str) -> str:
    # Strip comments for matching while keeping original line content in output.
    body = line.split("#", 1)[0].strip()
    if ";" not in body:
        return ""
    body = body[: body.find(";") + 1]
    body = re.sub(r"\s+", " ", body).strip()
    if not body:
        return ""

    # Deduplicate all auth_request directives in same context, regardless target.
    if body.startswith("auth_request "):
        return "auth_request"

    # Deduplicate relevant challenge error_page mappings in same context.
    if re.fullmatch(r"error_page 401 = @pteroprotect_challenge_redirect;", body):
        return "error_page_challenge_401"
    if re.fullmatch(r"error_page 401 403 = @drop_cto;", body):
        return "error_page_drop_cto_401_403"

    return ""

for path in targets:
    if not path.exists():
        continue
    try:
        lines = path.read_text().splitlines(keepends=True)
    except Exception:
        continue

    stack = []
    out = []
    changed = False
    for line in lines:
        key = line_key(line)
        if key and stack:
            seen = stack[-1]
            if key in seen:
                changed = True
                continue
            seen.add(key)

        out.append(line)
        for ch in line:
            if ch == "{":
                stack.append(set())
            elif ch == "}":
                if stack:
                    stack.pop()

    if changed:
        path.write_text("".join(out))
PY

    WINGS_GUARD_PREPARED=0
    WINGS_CONFIG_BACKUP=""
    WINGS_GUARD_CONF_BACKUP=""
    WINGS_GUARD_LINK_PRESENT=0
    if [[ -f /etc/pterodactyl/config.yml ]]; then
        echo "[setup] preparing wings reverse-proxy guard on :8080..."
        CHALLENGE_PORT="$(read_network_setting waf_challenge_port 18444)"
        WINGS_REPLICAS_RAW="$(read_network_setting wings_guard_replicas "")"
        WINGS_ROOTLESS_ENABLED="$(read_config_path_value wings.isolation.rootless_enabled "$(read_network_setting wings_rootless_enabled true)")"
        WINGS_ROOTLESS_CONTAINER_UID="1000"
        WINGS_ROOTLESS_CONTAINER_GID="1000"
        WINGS_DISABLE_REMOTE_DOWNLOAD="$(read_network_setting wings_disable_remote_download true)"
        WINGS_USERNS_MODE="$(read_config_path_value wings.isolation.userns_mode "$(read_network_setting wings_userns_mode private)")"
        WINGS_ENABLE_ICC="$(read_config_path_value wings.isolation.icc_enabled "$(read_network_setting wings_enable_icc false)")"
        WINGS_API_BIND="$(read_config_path_value wings.api.bind "$(read_network_setting wings_api_bind 127.0.0.1)")"
        WINGS_IGNORE_PANEL_CONFIG_UPDATES="$(read_network_setting wings_ignore_panel_config_updates true)"
        # wings-guard requires Wings to stay on localhost:18080; allowing panel-driven
        # config overwrites can revert host/port and break all daemon endpoints with 502.
        if [[ "${WINGS_IGNORE_PANEL_CONFIG_UPDATES,,}" != "true" && "${WINGS_IGNORE_PANEL_CONFIG_UPDATES}" != "1" ]]; then
            echo "[setup] forcing wings_ignore_panel_config_updates=true for wings-guard stability."
            WINGS_IGNORE_PANEL_CONFIG_UPDATES="true"
        fi
        WINGS_OPENAT_MODE="compat"
        [[ "${CHALLENGE_PORT}" =~ ^[0-9]+$ ]] || CHALLENGE_PORT="18444"
        [[ "${WINGS_ROOTLESS_CONTAINER_UID}" =~ ^[0-9]+$ ]] || WINGS_ROOTLESS_CONTAINER_UID="1000"
        [[ "${WINGS_ROOTLESS_CONTAINER_GID}" =~ ^[0-9]+$ ]] || WINGS_ROOTLESS_CONTAINER_GID="1000"

        WINGS_NODE_FQDN=""
        if command -v php >/dev/null 2>&1 && [[ -f "${PANEL_DIR}/artisan" ]]; then
            echo "[setup] syncing wings daemon token from matching panel node..."
            _WINGS_TOKEN_TMP="$(mktemp)"
            if (cd "${PANEL_DIR}" && php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$remoteHost = "";
if (is_file("/etc/pterodactyl/config.yml")) {
    $lines = @file("/etc/pterodactyl/config.yml");
    if (is_array($lines)) {
        foreach ($lines as $line) {
            if (preg_match("/^\s*remote:\s*(.+)\s*$/", $line, $m)) {
                $remoteRaw = trim($m[1], " \"'\''\t\r\n");
                $parts = @parse_url($remoteRaw);
                if (is_array($parts) && !empty($parts["host"])) {
                    $remoteHost = strtolower((string) $parts["host"]);
                }
                break;
            }
        }
    }
}
$nodes = Pterodactyl\Models\Node::query()->get();
if (!$nodes || $nodes->isEmpty()) exit(1);
$n = null;
if ($remoteHost !== "") {
    foreach ($nodes as $candidate) {
        $fqdn = strtolower(trim((string) $candidate->fqdn));
        if ($fqdn !== "" && $fqdn === $remoteHost) {
            $n = $candidate;
            break;
        }
    }
}
if (!$n) {
    $n = $nodes->first();
}
if (!$n) exit(1);
echo trim((string) $n->daemon_token_id), PHP_EOL;
echo trim((string) $n->getDecryptedKey()), PHP_EOL;
echo trim((string) $n->fqdn), PHP_EOL;
') >"${_WINGS_TOKEN_TMP}" 2>/dev/null; then
                WINGS_TOKEN_ID="$(sed -n '1p' "${_WINGS_TOKEN_TMP}" | tr -d '\r\n')"
                WINGS_TOKEN_KEY_RAW="$(sed -n '2p' "${_WINGS_TOKEN_TMP}" | tr -d '\r\n')"
                WINGS_NODE_FQDN="$(sed -n '3p' "${_WINGS_TOKEN_TMP}" | tr -d '\r\n' | tr -d "\"'[:space:]")"
                WINGS_TOKEN_KEY="${WINGS_TOKEN_KEY_RAW}"
                # Some panel builds return daemon key as "token_id.token".
                # Normalize to separate token_id + token so Wings auth is valid.
                if [[ "${WINGS_TOKEN_KEY_RAW}" == *.* ]]; then
                    _left="${WINGS_TOKEN_KEY_RAW%%.*}"
                    _right="${WINGS_TOKEN_KEY_RAW#*.}"
                    if [[ -n "${_left}" && -n "${_right}" ]]; then
                        WINGS_TOKEN_ID="${_left}"
                        WINGS_TOKEN_KEY="${_right}"
                    fi
                fi
                if [[ -n "${WINGS_TOKEN_ID}" && -n "${WINGS_TOKEN_KEY}" ]]; then
                    python3 - /etc/pterodactyl/config.yml "${WINGS_TOKEN_ID}" "${WINGS_TOKEN_KEY}" <<'PY'
import sys
from pathlib import Path

cfg = Path(sys.argv[1])
tid = sys.argv[2].strip()
tkey = sys.argv[3].strip()
if not cfg.exists():
    raise SystemExit(0)

lines = cfg.read_text().splitlines()
has_tid = False
has_tkey = False
for i, line in enumerate(lines):
    if line.startswith("token_id:"):
        lines[i] = f"token_id: {tid}"
        has_tid = True
    elif line.startswith("token:"):
        lines[i] = f"token: {tkey}"
        has_tkey = True

if not has_tid:
    lines.append(f"token_id: {tid}")
if not has_tkey:
    lines.append(f"token: {tkey}")

cfg.write_text("\n".join(lines) + "\n")
PY
                fi
            fi
            rm -f "${_WINGS_TOKEN_TMP}" >/dev/null 2>&1 || true
        fi

        WINGS_CERT_PATH="$(awk -F': ' '/^[[:space:]]{4}cert:[[:space:]]/{print $2; exit}' /etc/pterodactyl/config.yml | tr -d "\"'[:space:]")"
        WINGS_KEY_PATH="$(awk -F': ' '/^[[:space:]]{4}key:[[:space:]]/{print $2; exit}' /etc/pterodactyl/config.yml | tr -d "\"'[:space:]")"
        if [[ -n "${WINGS_NODE_FQDN}" ]]; then
            _node_cert="/etc/letsencrypt/live/${WINGS_NODE_FQDN}/fullchain.pem"
            _node_key="/etc/letsencrypt/live/${WINGS_NODE_FQDN}/privkey.pem"
            if [[ -f "${_node_cert}" && -f "${_node_key}" ]]; then
                WINGS_CERT_PATH="${_node_cert}"
                WINGS_KEY_PATH="${_node_key}"
            fi
        fi
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

        if [[ -n "${WINGS_CERT_PATH}" && -n "${WINGS_KEY_PATH}" && -f "${WINGS_CERT_PATH}" && -f "${WINGS_KEY_PATH}" ]]; then
            echo "[setup] staging nginx wings-guard with TLS (${WINGS_CERT_PATH})..."
            WINGS_UPSTREAM_SERVERS_BLOCK="    server 127.0.0.1:18080 max_fails=3 fail_timeout=3s;"
            if [[ -n "${WINGS_REPLICAS_RAW}" ]]; then
                for _replica in ${WINGS_REPLICAS_RAW//,/ }; do
                    _replica="$(printf '%s' "${_replica}" | tr -d '[:space:]')"
                    [[ -n "${_replica}" ]] || continue
                    if [[ "${_replica}" =~ ^[A-Za-z0-9._-]+:[0-9]+$ ]]; then
                        if [[ "${_replica}" != "127.0.0.1:18080" ]]; then
                            WINGS_UPSTREAM_SERVERS_BLOCK+=$'\n'"    server ${_replica} max_fails=3 fail_timeout=3s;"
                        fi
                    fi
                done
            fi
            WINGS_CONFIG_BACKUP="$(mktemp)"
            cp /etc/pterodactyl/config.yml "${WINGS_CONFIG_BACKUP}"
            if [[ -f "${NGINX_DIR}/sites-available/wings-guard.conf" ]]; then
                WINGS_GUARD_CONF_BACKUP="$(mktemp)"
                cp "${NGINX_DIR}/sites-available/wings-guard.conf" "${WINGS_GUARD_CONF_BACKUP}"
            fi
            if [[ -L "${NGINX_DIR}/sites-enabled/wings-guard.conf" || -e "${NGINX_DIR}/sites-enabled/wings-guard.conf" ]]; then
                WINGS_GUARD_LINK_PRESENT=1
            fi

            python3 - /etc/pterodactyl/config.yml \
                "${WINGS_ROOTLESS_ENABLED}" \
                "${WINGS_ROOTLESS_CONTAINER_UID}" \
                "${WINGS_ROOTLESS_CONTAINER_GID}" \
                "${WINGS_DISABLE_REMOTE_DOWNLOAD}" \
                "${WINGS_USERNS_MODE}" \
                "${WINGS_ENABLE_ICC}" \
                "${WINGS_API_BIND}" \
                "${WINGS_IGNORE_PANEL_CONFIG_UPDATES}" \
                "${WINGS_OPENAT_MODE}" <<'PY'
import re
import sys
from pathlib import Path

p = Path(sys.argv[1])
if not p.exists():
    raise SystemExit(0)

def as_bool(value: str, default: bool) -> bool:
    v = str(value or "").strip().lower()
    if v in ("1", "true", "yes", "on"):
        return True
    if v in ("0", "false", "no", "off"):
        return False
    return default

rootless_enabled = as_bool(sys.argv[2] if len(sys.argv) > 2 else "false", False)
rootless_uid = str(sys.argv[3] if len(sys.argv) > 3 else "1000").strip() or "1000"
rootless_gid = str(sys.argv[4] if len(sys.argv) > 4 else "1000").strip() or "1000"
disable_remote_download = as_bool(sys.argv[5] if len(sys.argv) > 5 else "true", True)
userns_mode = str(sys.argv[6] if len(sys.argv) > 6 else "host").strip() or "host"
enable_icc = as_bool(sys.argv[7] if len(sys.argv) > 7 else "false", False)
api_bind_host = str(sys.argv[8] if len(sys.argv) > 8 else "127.0.0.1").strip() or "127.0.0.1"
ignore_panel_cfg_updates = as_bool(sys.argv[9] if len(sys.argv) > 9 else "true", True)
openat_mode = str(sys.argv[10] if len(sys.argv) > 10 else "compat").strip() or "compat"

lines = p.read_text().splitlines()
out = []
in_api = False
in_ssl = False
in_system = False
in_user = False
in_rootless = False
in_docker = False
in_docker_network = False

for line in lines:
    if re.match(r'^\s*remote:\s*', line):
        raw = re.sub(r'^\s*remote:\s*', '', line).strip().strip('"').strip("'")
        if raw.startswith("http://"):
            raw = "https://" + raw[len("http://"):]
        elif not raw.startswith("https://"):
            raw = "https://" + raw
        out.append(f"remote: {raw}")
        continue

    if re.match(r'^api:\s*$', line):
        in_api = True
        in_ssl = False
        out.append(line)
        continue

    if re.match(r'^system:\s*$', line):
        in_system = True
        in_user = False
        in_rootless = False
        out.append(line)
        continue

    if in_system and re.match(r'^\s{2}user:\s*$', line):
        in_user = True
        in_rootless = False
        out.append(line)
        continue

    if in_user and re.match(r'^\s{4}rootless:\s*$', line):
        in_rootless = True
        out.append(line)
        continue

    if re.match(r'^docker:\s*$', line):
        in_docker = True
        in_docker_network = False
        out.append(line)
        continue

    if in_docker and re.match(r'^\s{2}network:\s*$', line):
        in_docker_network = True
        out.append(line)
        continue

    if re.match(r'^[^\s]', line):
        in_api = False
        in_ssl = False
        in_system = False
        in_user = False
        in_rootless = False
        in_docker = False
        in_docker_network = False

    if in_api and re.match(r'^\s{2}[a-zA-Z_][a-zA-Z0-9_]*:\s*', line) and not re.match(r'^\s{2}(host|port|ssl|disable_remote_download):', line):
        in_ssl = False
    if in_user and re.match(r'^\s{2}[a-zA-Z_][a-zA-Z0-9_]*:\s*', line):
        in_user = False
        in_rootless = False
    if in_rootless and re.match(r'^\s{4}[a-zA-Z_][a-zA-Z0-9_]*:\s*', line):
        in_rootless = False
    if in_docker and re.match(r'^\s{2}[a-zA-Z_][a-zA-Z0-9_]*:\s*', line) and not re.match(r'^\s{2}(network|userns_mode):', line):
        in_docker_network = False
    if in_docker_network and re.match(r'^\s{2}[a-zA-Z_][a-zA-Z0-9_]*:\s*', line):
        in_docker_network = False

    if in_api and re.match(r'^\s{2}ssl:\s*$', line):
        in_ssl = True
        out.append(line)
        continue

    if in_ssl and re.match(r'^\s{2}[a-zA-Z_][a-zA-Z0-9_]*:\s*', line):
        in_ssl = False

    if in_api and re.match(r'^\s{2}host:\s*', line):
        out.append(f'  host: {api_bind_host}')
        continue

    if in_api and re.match(r'^\s{2}port:\s*', line):
        out.append('  port: 18080')
        continue

    if in_api and re.match(r'^\s{2}disable_remote_download:\s*', line):
        out.append(f"  disable_remote_download: {'true' if disable_remote_download else 'false'}")
        continue

    if in_ssl and re.match(r'^\s{4}enabled:\s*', line):
        out.append('    enabled: false')
        continue

    if in_rootless and re.match(r'^\s{6}enabled:\s*', line):
        out.append(f"      enabled: {'true' if rootless_enabled else 'false'}")
        continue
    if in_rootless and re.match(r'^\s{6}container_uid:\s*', line):
        out.append(f"      container_uid: {rootless_uid}")
        continue
    if in_rootless and re.match(r'^\s{6}container_gid:\s*', line):
        out.append(f"      container_gid: {rootless_gid}")
        continue

    if in_docker and re.match(r'^\s{2}userns_mode:\s*', line):
        out.append(f'  userns_mode: "{userns_mode}"')
        continue
    if in_docker_network and re.match(r'^\s{4}enable_icc:\s*', line):
        out.append(f"    enable_icc: {'true' if enable_icc else 'false'}")
        continue

    if re.match(r'^ignore_panel_config_updates:\s*', line):
        out.append(f"ignore_panel_config_updates: {'true' if ignore_panel_cfg_updates else 'false'}")
        continue
    if re.match(r'^allowed_mounts:\s*', line):
        out.append("allowed_mounts: []")
        continue
    if re.match(r'^\s{2}openat_mode:\s*', line):
        out.append(f"  openat_mode: {openat_mode}")
        continue
    out.append(line)

p.write_text('\n'.join(out) + '\n')
PY

            cat > "${NGINX_DIR}/sites-available/wings-guard.conf" <<EOF
upstream pteroprotect_wings_pool {
    least_conn;
${WINGS_UPSTREAM_SERVERS_BLOCK}
    keepalive 128;
}

server {
    listen 8080 ssl;
    listen [::]:8080 ssl;
    server_name _;
    client_max_body_size 256m;
    client_body_timeout 600s;

    ssl_certificate ${WINGS_CERT_PATH};
    ssl_certificate_key ${WINGS_KEY_PATH};
    include /etc/letsencrypt/options-ssl-nginx.conf;

    location @drop_cto {
        default_type text/plain;
        return 444;
    }

    location @challenge_deny {
        internal;
        return 503;
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
        proxy_next_upstream error timeout http_502 http_503 http_504;
        proxy_next_upstream_tries 2;
        proxy_connect_timeout 2s;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location = /__pteroprotect/challenge/check_token {
        internal;
        proxy_pass http://127.0.0.1:${CHALLENGE_PORT}/check-token\$is_args\$args;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header User-Agent \$http_user_agent;
        proxy_set_header Authorization \$http_authorization;
        proxy_set_header X-API-Key \$http_x_api_key;
        proxy_set_header Content-Length "";
        proxy_pass_request_body off;
        proxy_intercept_errors on;
        error_page 500 502 503 504 = @challenge_deny;
        proxy_connect_timeout 300ms;
        proxy_send_timeout 1s;
        proxy_read_timeout 1s;
    }

    location ~* ^/api/servers/[0-9a-f-]+/ws$ {
        proxy_pass http://pteroprotect_wings_pool;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_next_upstream error timeout http_502 http_503 http_504;
        proxy_next_upstream_tries 2;
        proxy_connect_timeout 2s;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location ~* ^/(api/servers/[0-9a-f-]+/files/|upload/file|download/file) {
        limit_req_status 444;
        limit_conn_status 444;
        limit_conn pteroprotect_api_key_conn 80;
        limit_conn pteroprotect_api_global_conn 300;
        limit_req zone=pteroprotect_api_key_req burst=80;
        limit_req zone=pteroprotect_api_global_req burst=120;

        if (\$request_method = OPTIONS) { return 418; }
        if (\$http_user_agent ~* "^GuzzleHttp/") { return 418; }
        if (\$http_upgrade ~* "websocket") { return 418; }
        if (\$http_authorization ~* "^Bearer\\s+.+") { return 418; }
        if (\$request_uri ~* "(\\?|&)token=") { return 418; }

        auth_request /__pteroprotect/challenge/check_token;
        error_page 401 403 = @drop_cto;
        error_page 418 = @wings_upstream;

        proxy_request_buffering off;
        proxy_pass http://pteroprotect_wings_pool;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_next_upstream error timeout http_502 http_503 http_504;
        proxy_next_upstream_tries 2;
        proxy_connect_timeout 2s;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location / {
        if (\$request_method = OPTIONS) { return 418; }
        if (\$http_user_agent ~* "^GuzzleHttp/") { return 418; }
        if (\$http_upgrade ~* "websocket") { return 418; }
        if (\$http_authorization ~* "^Bearer\\s+.+") { return 418; }
        if (\$request_uri ~* "(\\?|&)token=") { return 418; }

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
        proxy_next_upstream error timeout http_502 http_503 http_504;
        proxy_next_upstream_tries 2;
        proxy_connect_timeout 2s;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}
EOF
            ln -sfn "${NGINX_DIR}/sites-available/wings-guard.conf" "${NGINX_DIR}/sites-enabled/wings-guard.conf"
            WINGS_GUARD_PREPARED=1
        else
            echo "[setup] warning: TLS cert/key for wings-guard not found, keep existing Wings binding (no forced localhost switch)." >&2
        fi
    fi

    if command -v nginx >/dev/null 2>&1; then
        if nginx -t; then
            if [[ "${WINGS_GUARD_PREPARED}" == "1" ]]; then
                if ! command -v systemctl >/dev/null 2>&1; then
                    echo "[setup] error: systemctl is required to switch Wings to localhost mode safely." >&2
                    exit 1
                fi
                if ! systemctl restart wings; then
                    echo "[setup] error: wings restart failed after staging guard, rolling back config..." >&2
                    [[ -n "${WINGS_CONFIG_BACKUP}" && -f "${WINGS_CONFIG_BACKUP}" ]] && cp "${WINGS_CONFIG_BACKUP}" /etc/pterodactyl/config.yml
                    if [[ -n "${WINGS_GUARD_CONF_BACKUP}" && -f "${WINGS_GUARD_CONF_BACKUP}" ]]; then
                        cp "${WINGS_GUARD_CONF_BACKUP}" "${NGINX_DIR}/sites-available/wings-guard.conf"
                    else
                        rm -f "${NGINX_DIR}/sites-available/wings-guard.conf"
                    fi
                    if [[ "${WINGS_GUARD_LINK_PRESENT}" == "1" ]]; then
                        ln -sfn "${NGINX_DIR}/sites-available/wings-guard.conf" "${NGINX_DIR}/sites-enabled/wings-guard.conf"
                    else
                        rm -f "${NGINX_DIR}/sites-enabled/wings-guard.conf"
                    fi
                    systemctl restart wings >/dev/null 2>&1 || true
                    exit 1
                fi
            fi
            if ! systemctl reload nginx; then
                if [[ "${WINGS_GUARD_PREPARED}" == "1" ]]; then
                    echo "[setup] error: nginx reload failed after Wings switch; rolling back..." >&2
                    [[ -n "${WINGS_CONFIG_BACKUP}" && -f "${WINGS_CONFIG_BACKUP}" ]] && cp "${WINGS_CONFIG_BACKUP}" /etc/pterodactyl/config.yml
                    if [[ -n "${WINGS_GUARD_CONF_BACKUP}" && -f "${WINGS_GUARD_CONF_BACKUP}" ]]; then
                        cp "${WINGS_GUARD_CONF_BACKUP}" "${NGINX_DIR}/sites-available/wings-guard.conf"
                    else
                        rm -f "${NGINX_DIR}/sites-available/wings-guard.conf"
                    fi
                    if [[ "${WINGS_GUARD_LINK_PRESENT}" == "1" ]]; then
                        ln -sfn "${NGINX_DIR}/sites-available/wings-guard.conf" "${NGINX_DIR}/sites-enabled/wings-guard.conf"
                    else
                        rm -f "${NGINX_DIR}/sites-enabled/wings-guard.conf"
                    fi
                    nginx -t >/dev/null 2>&1 || true
                    systemctl reload nginx >/dev/null 2>&1 || true
                    systemctl restart wings >/dev/null 2>&1 || true
                fi
                exit 1
            fi
        else
            if [[ "${WINGS_GUARD_PREPARED}" == "1" ]]; then
                echo "[setup] error: nginx config test failed; rolling back staged wings-guard changes..." >&2
                [[ -n "${WINGS_CONFIG_BACKUP}" && -f "${WINGS_CONFIG_BACKUP}" ]] && cp "${WINGS_CONFIG_BACKUP}" /etc/pterodactyl/config.yml
                if [[ -n "${WINGS_GUARD_CONF_BACKUP}" && -f "${WINGS_GUARD_CONF_BACKUP}" ]]; then
                    cp "${WINGS_GUARD_CONF_BACKUP}" "${NGINX_DIR}/sites-available/wings-guard.conf"
                else
                    rm -f "${NGINX_DIR}/sites-available/wings-guard.conf"
                fi
                if [[ "${WINGS_GUARD_LINK_PRESENT}" == "1" ]]; then
                    ln -sfn "${NGINX_DIR}/sites-available/wings-guard.conf" "${NGINX_DIR}/sites-enabled/wings-guard.conf"
                else
                    rm -f "${NGINX_DIR}/sites-enabled/wings-guard.conf"
                fi
            fi
            echo "[setup] error: nginx config test failed; installer stopped to prevent broken deploy." >&2
            exit 1
        fi
    elif [[ "${WINGS_GUARD_PREPARED}" == "1" ]]; then
        echo "[setup] error: nginx binary not found while wings-guard is staged; rolling back..." >&2
        [[ -n "${WINGS_CONFIG_BACKUP}" && -f "${WINGS_CONFIG_BACKUP}" ]] && cp "${WINGS_CONFIG_BACKUP}" /etc/pterodactyl/config.yml
        if [[ -n "${WINGS_GUARD_CONF_BACKUP}" && -f "${WINGS_GUARD_CONF_BACKUP}" ]]; then
            cp "${WINGS_GUARD_CONF_BACKUP}" "${NGINX_DIR}/sites-available/wings-guard.conf"
        else
            rm -f "${NGINX_DIR}/sites-available/wings-guard.conf"
        fi
        if [[ "${WINGS_GUARD_LINK_PRESENT}" == "1" ]]; then
            ln -sfn "${NGINX_DIR}/sites-available/wings-guard.conf" "${NGINX_DIR}/sites-enabled/wings-guard.conf"
        else
            rm -f "${NGINX_DIR}/sites-enabled/wings-guard.conf"
        fi
        exit 1
    fi
    rm -f "${WINGS_CONFIG_BACKUP}" "${WINGS_GUARD_CONF_BACKUP}" >/dev/null 2>&1 || true
fi

if [[ -d "${INSTALL_DIR}/host_overrides/sysctl" ]]; then
    echo "[setup] applying host kernel networking hardening..."
    mkdir -p /etc/sysctl.d
    cp "${INSTALL_DIR}/host_overrides/sysctl/pteroprotect.conf" /etc/sysctl.d/99-pteroprotect.conf
    sysctl --system >/dev/null 2>&1 || sysctl -p /etc/sysctl.d/99-pteroprotect.conf >/dev/null 2>&1 || true
fi

if [[ -x "${INSTALL_DIR}/scripts/install_fail2ban.sh" ]]; then
    echo "[setup] applying fail2ban protection profile..."

    if ! command -v fail2ban-client >/dev/null 2>&1; then
        echo "[setup] fail2ban not found, auto-installing..."
        apt_update_resilient || true
        apt-get install "${APT_INSTALL_OPTS[@]}" fail2ban || true
    fi

    "${INSTALL_DIR}/scripts/install_fail2ban.sh" "${INSTALL_DIR}" || true

    FAIL2BAN_REQUIRED_RAW="$(read_network_setting fail2ban_required true)"
    FAIL2BAN_REQUIRED=0
    case "${FAIL2BAN_REQUIRED_RAW,,}" in
        1|true|yes|on) FAIL2BAN_REQUIRED=1 ;;
    esac
    case "${PTEROPROTECT_FAIL2BAN_REQUIRED:-}" in
        0|false|FALSE|no|NO|off|OFF) FAIL2BAN_REQUIRED=0 ;;
        1|true|TRUE|yes|YES|on|ON) FAIL2BAN_REQUIRED=1 ;;
    esac

    if (( FAIL2BAN_REQUIRED == 1 )); then
        if ! command -v fail2ban-client >/dev/null 2>&1; then
            echo "[setup] error: fail2ban-client is missing but fail2ban is required." >&2
            echo "[setup] hint: apt-get install -y fail2ban" >&2
            exit 1
        fi
        if ! fail2ban-client -d >/dev/null 2>&1; then
            echo "[setup] error: fail2ban configuration is invalid." >&2
            fail2ban-client -d || true
            exit 1
        fi
        if command -v systemctl >/dev/null 2>&1; then
            if ! systemctl is-active --quiet fail2ban; then
                echo "[setup] error: fail2ban is not active after setup." >&2
                systemctl --no-pager --full status fail2ban || true
                journalctl -u fail2ban -n 120 --no-pager || true
                exit 1
            fi
        else
            echo "[setup] warning: systemctl not found; cannot assert fail2ban service state." >&2
        fi
    fi
fi

SSH_HARDENING_ENABLED="$(read_network_setting ssh_hardening_enabled true)"
if [[ "${SSH_HARDENING_ENABLED}" == "true" || "${SSH_HARDENING_ENABLED}" == "1" ]]; then
    SSH_MAX_AUTH_TRIES="$(read_network_setting ssh_max_auth_tries 3)"
    SSH_LOGIN_GRACE_TIME_SEC="$(read_network_setting ssh_login_grace_time_sec 20)"
    SSH_MAX_SESSIONS="$(read_network_setting ssh_max_sessions 4)"
    SSH_MAX_STARTUPS="$(read_network_setting ssh_max_startups 10:30:60)"
    SSH_CLIENT_ALIVE_INTERVAL_SEC="$(read_network_setting ssh_client_alive_interval_sec 300)"
    SSH_CLIENT_ALIVE_COUNT_MAX="$(read_network_setting ssh_client_alive_count_max 2)"

    [[ "${SSH_MAX_AUTH_TRIES}" =~ ^[0-9]+$ ]] || SSH_MAX_AUTH_TRIES="3"
    [[ "${SSH_LOGIN_GRACE_TIME_SEC}" =~ ^[0-9]+$ ]] || SSH_LOGIN_GRACE_TIME_SEC="20"
    [[ "${SSH_MAX_SESSIONS}" =~ ^[0-9]+$ ]] || SSH_MAX_SESSIONS="4"
    [[ "${SSH_MAX_STARTUPS}" =~ ^[0-9]+:[0-9]+:[0-9]+$ ]] || SSH_MAX_STARTUPS="10:30:60"
    [[ "${SSH_CLIENT_ALIVE_INTERVAL_SEC}" =~ ^[0-9]+$ ]] || SSH_CLIENT_ALIVE_INTERVAL_SEC="300"
    [[ "${SSH_CLIENT_ALIVE_COUNT_MAX}" =~ ^[0-9]+$ ]] || SSH_CLIENT_ALIVE_COUNT_MAX="2"

    echo "[setup] applying ssh pre-auth hardening profile..."
    mkdir -p /etc/ssh/sshd_config.d
    cat >/etc/ssh/sshd_config.d/99-pteroprotect.conf <<EOF
# Managed by PteroProtect setup.sh
UseDNS no
PubkeyAuthentication yes
PermitEmptyPasswords no
MaxAuthTries ${SSH_MAX_AUTH_TRIES}
LoginGraceTime ${SSH_LOGIN_GRACE_TIME_SEC}
MaxSessions ${SSH_MAX_SESSIONS}
MaxStartups ${SSH_MAX_STARTUPS}
ClientAliveInterval ${SSH_CLIENT_ALIVE_INTERVAL_SEC}
ClientAliveCountMax ${SSH_CLIENT_ALIVE_COUNT_MAX}
EOF

    if command -v sshd >/dev/null 2>&1; then
        sshd -t >/dev/null 2>&1 || echo "[setup] warning: sshd config test failed; keeping existing runtime ssh config" >&2
    fi
    systemctl reload ssh >/dev/null 2>&1 || systemctl reload sshd >/dev/null 2>&1 || true
fi

HOST_FIREWALL_ENABLED="$(read_network_setting host_firewall_enabled true)"
if [[ "${HOST_FIREWALL_ENABLED}" == "true" ]]; then
    HOST_FIREWALL_ENABLED="1"
elif [[ "${HOST_FIREWALL_ENABLED}" == "false" ]]; then
    HOST_FIREWALL_ENABLED="0"
fi

if [[ -x "${INSTALL_DIR}/scripts/install_host_protection.sh" ]]; then
    HOST_NEW_CONN_PER_IP="$(read_network_setting host_new_conn_per_ip 40)"
    HOST_NEW_CONN_BURST="$(read_network_setting host_new_conn_burst 80)"
    HOST_CONNLIMIT_PER_IP="$(read_network_setting host_connlimit_per_ip 100)"
    HOST_RECENT_HITCOUNT="$(read_network_setting host_recent_hitcount 180)"
    HOST_RECENT_WINDOW="$(read_network_setting host_recent_window_sec 5)"
    HOST_BLACKHOLE_TTL="$(read_network_setting blackhole_ttl_sec 600)"
    HOST_IPV6_ENABLED="$(read_network_setting ipv6_enabled true)"
    HOST_PUBLIC_TCP_PORTS="$(read_config_path_value firewall.allowed_ports "$(read_network_setting public_tcp_ports 80,443,8080,18443)")"
    HOST_WINGS_RESTRICTED_SOURCE="$(read_config_path_value firewall.restricted_sources.wings_api 127.0.0.1/32)"
    HOST_EGRESS_GUARD_ENABLED="$(read_network_setting egress_guard_enabled true)"
    HOST_DOCKER_STRICT_ISOLATION_ENABLED="$(read_network_setting docker_strict_isolation_enabled true)"
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
    HOST_INFRA_GUARD_PORTS="$(read_network_setting infra_guard_ports 22,2022,8080,3306,5432,6379)"
    HOST_INFRA_CONNLIMIT_PER_IP="$(read_network_setting infra_guard_connlimit_per_ip 12)"
    HOST_INFRA_NEW_CONN_RATE="$(read_network_setting infra_guard_new_conn_per_ip 8)"
    HOST_INFRA_NEW_CONN_BURST="$(read_network_setting infra_guard_new_conn_burst 16)"
    HOST_INFRA_GLOBAL_NEW_PER_SEC="$(read_network_setting infra_guard_global_new_per_sec 120)"
    HOST_INFRA_GLOBAL_NEW_BURST="$(read_network_setting infra_guard_global_new_burst 240)"
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
    if [[ "${HOST_DOCKER_STRICT_ISOLATION_ENABLED}" == "true" ]]; then
        HOST_DOCKER_STRICT_ISOLATION_ENABLED="1"
    elif [[ "${HOST_DOCKER_STRICT_ISOLATION_ENABLED}" == "false" ]]; then
        HOST_DOCKER_STRICT_ISOLATION_ENABLED="0"
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
            PTEROPROTECT_DOCKER_STRICT_ISOLATION_ENABLED="${HOST_DOCKER_STRICT_ISOLATION_ENABLED}" \
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
            PTEROPROTECT_INFRA_GUARD_PORTS="${HOST_INFRA_GUARD_PORTS}" \
            PTEROPROTECT_WINGS_API_RESTRICTED_SOURCE="${HOST_WINGS_RESTRICTED_SOURCE}" \
            PTEROPROTECT_INFRA_CONNLIMIT_PER_IP="${HOST_INFRA_CONNLIMIT_PER_IP}" \
            PTEROPROTECT_INFRA_NEW_CONN_RATE="${HOST_INFRA_NEW_CONN_RATE}" \
            PTEROPROTECT_INFRA_NEW_CONN_BURST="${HOST_INFRA_NEW_CONN_BURST}" \
            PTEROPROTECT_INFRA_GLOBAL_NEW_PER_SEC="${HOST_INFRA_GLOBAL_NEW_PER_SEC}" \
            PTEROPROTECT_INFRA_GLOBAL_NEW_BURST="${HOST_INFRA_GLOBAL_NEW_BURST}" \
            "${INSTALL_DIR}/scripts/install_host_protection.sh"
    else
        echo "[setup] disabling host firewall protection to avoid false positives..."
        PTEROPROTECT_FIREWALL_DISABLE="1" "${INSTALL_DIR}/scripts/install_host_protection.sh"
    fi
fi

WINGS_NOFILE_LIMIT="$(read_network_setting wings_nofile_limit 262144)"
if [[ ! "${WINGS_NOFILE_LIMIT}" =~ ^[0-9]+$ ]]; then
    WINGS_NOFILE_LIMIT=262144
fi
if (( WINGS_NOFILE_LIMIT < 65535 )); then
    WINGS_NOFILE_LIMIT=65535
fi
if (( WINGS_NOFILE_LIMIT > 1048576 )); then
    WINGS_NOFILE_LIMIT=1048576
fi

if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files 2>/dev/null | grep -q '^wings\.service'; then
    echo "[setup] applying wings NOFILE override (${WINGS_NOFILE_LIMIT})..."
    mkdir -p /etc/systemd/system/wings.service.d
    cat > /etc/systemd/system/wings.service.d/override.conf <<EOF
[Service]
LimitNOFILE=${WINGS_NOFILE_LIMIT}
EOF
fi

if command -v systemctl >/dev/null 2>&1 && [[ -f "${SYSTEMD_DIR}/pteroprotect.service" ]]; then
    echo "[setup] reloading systemd and enabling pteroprotect..."
    systemctl daemon-reload
    if [[ -f "${SYSTEMD_DIR}/dann_guard.service" ]]; then
        systemctl disable --now dann_guard >/dev/null 2>&1 || true
    fi
    systemctl enable pteroprotect
    if ! systemctl restart pteroprotect; then
        if ! systemctl start pteroprotect; then
            echo "[setup] error: failed to start pteroprotect service." >&2
            exit 1
        fi
    fi
    if ! systemctl is-active --quiet pteroprotect; then
        echo "[setup] error: pteroprotect service is not active after setup." >&2
        exit 1
    fi
    if [[ -f "${SYSTEMD_DIR}/pteroprotect-hostguard.service" ]]; then
        if [[ "${HOST_FIREWALL_ENABLED}" == "1" ]]; then
            systemctl enable pteroprotect-hostguard >/dev/null 2>&1
            if ! systemctl restart pteroprotect-hostguard >/dev/null 2>&1; then
                systemctl start pteroprotect-hostguard >/dev/null 2>&1
            fi
            if ! systemctl is-active --quiet pteroprotect-hostguard; then
                echo "[setup] error: pteroprotect-hostguard is not active after setup." >&2
                exit 1
            fi
        else
            systemctl disable --now pteroprotect-hostguard >/dev/null 2>&1 || true
        fi
    fi
    if [[ -f "${SYSTEMD_DIR}/pteroprotect-ddoslog.service" ]]; then
        systemctl enable pteroprotect-ddoslog >/dev/null 2>&1
        if ! systemctl restart pteroprotect-ddoslog >/dev/null 2>&1; then
            systemctl start pteroprotect-ddoslog >/dev/null 2>&1
        fi
        if ! systemctl is-active --quiet pteroprotect-ddoslog; then
            echo "[setup] error: pteroprotect-ddoslog is not active after setup." >&2
            exit 1
        fi
    fi
    if [[ -f "${SYSTEMD_DIR}/pteroprotect-unblock-portal.service" ]]; then
        systemctl enable pteroprotect-unblock-portal >/dev/null 2>&1
        if ! systemctl restart pteroprotect-unblock-portal >/dev/null 2>&1; then
            systemctl start pteroprotect-unblock-portal >/dev/null 2>&1
        fi
        if ! systemctl is-active --quiet pteroprotect-unblock-portal; then
            echo "[setup] error: pteroprotect-unblock-portal is not active after setup." >&2
            exit 1
        fi
    fi
    if [[ -f "${SYSTEMD_DIR}/pteroprotect-challenge.service" ]]; then
        systemctl enable pteroprotect-challenge >/dev/null 2>&1
        if ! systemctl restart pteroprotect-challenge >/dev/null 2>&1; then
            systemctl start pteroprotect-challenge >/dev/null 2>&1
        fi
        if ! systemctl is-active --quiet pteroprotect-challenge; then
            echo "[setup] error: pteroprotect-challenge is not active after setup." >&2
            exit 1
        fi
    fi
    if [[ -f "${SYSTEMD_DIR}/pteroprotect-panel-sync.service" ]]; then
        systemctl enable pteroprotect-panel-sync >/dev/null 2>&1
        if ! systemctl restart pteroprotect-panel-sync >/dev/null 2>&1; then
            systemctl start pteroprotect-panel-sync >/dev/null 2>&1
        fi
        if ! systemctl is-active --quiet pteroprotect-panel-sync; then
            echo "[setup] error: pteroprotect-panel-sync is not active after setup." >&2
            exit 1
        fi
    fi
    if [[ -f "${SYSTEMD_DIR}/pteroprotect-selfheal.service" ]]; then
        systemctl enable pteroprotect-selfheal >/dev/null 2>&1
        if ! systemctl restart pteroprotect-selfheal >/dev/null 2>&1; then
            systemctl start pteroprotect-selfheal >/dev/null 2>&1
        fi
        if ! systemctl is-active --quiet pteroprotect-selfheal; then
            echo "[setup] error: pteroprotect-selfheal is not active after setup." >&2
            exit 1
        fi
    fi
    if [[ -f "${SYSTEMD_DIR}/pteroprotect-abuse-guard.service" ]]; then
        systemctl enable pteroprotect-abuse-guard >/dev/null 2>&1
        if ! systemctl restart pteroprotect-abuse-guard >/dev/null 2>&1; then
            systemctl start pteroprotect-abuse-guard >/dev/null 2>&1
        fi
        if ! systemctl is-active --quiet pteroprotect-abuse-guard; then
            echo "[setup] error: pteroprotect-abuse-guard is not active after setup." >&2
            exit 1
        fi
    fi
    if [[ -f "${SYSTEMD_DIR}/pteroprotect-log-watch.service" ]]; then
        systemctl enable pteroprotect-log-watch >/dev/null 2>&1
        if ! systemctl restart pteroprotect-log-watch >/dev/null 2>&1; then
            systemctl start pteroprotect-log-watch >/dev/null 2>&1
        fi
        if ! systemctl is-active --quiet pteroprotect-log-watch; then
            echo "[setup] error: pteroprotect-log-watch is not active after setup." >&2
            exit 1
        fi
    fi
    if [[ -f "${SYSTEMD_DIR}/pteroprotect-resilience.service" ]]; then
        systemctl enable pteroprotect-resilience >/dev/null 2>&1
        if ! systemctl restart pteroprotect-resilience >/dev/null 2>&1; then
            systemctl start pteroprotect-resilience >/dev/null 2>&1
        fi
        if ! systemctl is-active --quiet pteroprotect-resilience; then
            echo "[setup] error: pteroprotect-resilience is not active after setup." >&2
            exit 1
        fi
    fi
    if [[ -f "${SYSTEMD_DIR}/pteroprotect-resilience-collector.service" ]]; then
        systemctl enable pteroprotect-resilience-collector >/dev/null 2>&1
        if ! systemctl restart pteroprotect-resilience-collector >/dev/null 2>&1; then
            systemctl start pteroprotect-resilience-collector >/dev/null 2>&1
        fi
        if ! systemctl is-active --quiet pteroprotect-resilience-collector; then
            echo "[setup] error: pteroprotect-resilience-collector is not active after setup." >&2
            exit 1
        fi
    fi
    if systemctl list-unit-files 2>/dev/null | grep -q '^wings\.service'; then
        systemctl enable wings >/dev/null 2>&1
        if ! systemctl restart wings >/dev/null 2>&1; then
            systemctl start wings >/dev/null 2>&1
        fi
        if ! systemctl is-active --quiet wings; then
            echo "[setup] error: wings is not active after applying NOFILE override." >&2
            exit 1
        fi
    fi
fi

if command -v sudo >/dev/null 2>&1; then
    echo "[setup] configuring sudoers for panel protect controls..."
    {
        while IFS= read -r _pp_user; do
            id "${_pp_user}" >/dev/null 2>&1 || continue
            printf 'Defaults:%s !requiretty\n' "${_pp_user}"
            printf '%s ALL=(ALL) NOPASSWD: ALL\n' "${_pp_user}"
        done < <(panel_runtime_users)
    } >/etc/sudoers.d/pteroprotect-panel
    chmod 0440 /etc/sudoers.d/pteroprotect-panel
fi

# Fail-safe cleanup: never leave production web locked after setup run.
if [[ -x "${INSTALL_DIR}/scripts/survive_60s.sh" ]]; then
    bash "${INSTALL_DIR}/scripts/survive_60s.sh" off >/dev/null 2>&1 || true
fi
if [[ -x "${INSTALL_DIR}/scripts/edge_origin_cloak.sh" ]]; then
    bash "${INSTALL_DIR}/scripts/edge_origin_cloak.sh" clear 80,443 >/dev/null 2>&1 || true
fi

echo "[setup] running post-install connectivity smoke checks..."
post_install_service_smoke_check "${PANEL_ENV_FILE}"

echo "[setup] done."
echo "[setup] workspace: ${INSTALL_DIR}"
echo "[setup] binary: ${INSTALL_DIR}/dann_guard"
echo "[setup] config: ${INSTALL_DIR}/config.json"
echo "[setup] config template: ${INSTALL_DIR}/config.example.json"
echo "[setup] log: ${INSTALL_DIR}/dann_guard.log"
echo "[setup] service: ${SYSTEMD_DIR}/pteroprotect.service"
echo "[setup] panel sync service: ${SYSTEMD_DIR}/pteroprotect-panel-sync.service"
echo "[setup] ddos ram log: /dev/shm/pteroprotect/ddos_host.log"
echo "[setup] run with: DANN_GUARD_HOME=${INSTALL_DIR} ${INSTALL_DIR}/dann_guard"

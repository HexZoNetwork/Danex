#!/usr/bin/env bash
set -euo pipefail

# Script buat deploy hasil editan panel_overrides ke panel asli
PANEL_DIR="/var/www/pterodactyl"
PROJECT_DIR="/root/Danex"
OVERRIDES_DIR="${PROJECT_DIR}/panel_overrides"

echo "[deploy-panel] Start deploying overrides to ${PANEL_DIR}..."

if [[ ! -d "${PANEL_DIR}" ]]; then
    echo "[deploy-panel] ERROR: panel directory not found at ${PANEL_DIR}" >&2
    exit 1
fi

if [[ ! -d "${OVERRIDES_DIR}" ]]; then
    echo "[deploy-panel] ERROR: overrides folder not found at ${OVERRIDES_DIR}" >&2
    exit 1
fi

# Backup panel asli buat jaga-jaga (cuma sekali aja)
if [[ ! -d "${PANEL_DIR}.bak_pteroprotect" ]]; then
    echo "[deploy-panel] Creating initial backup of ${PANEL_DIR}..."
    cp -rp "${PANEL_DIR}" "${PANEL_DIR}.bak_pteroprotect"
fi

# Sync file per file kecuali assets (kita pake rsync buat assets)
while IFS= read -r rel; do
    [[ -z "${rel}" ]] && continue
    # Skip assets karena bakal di-handle rsync
    if [[ "${rel}" == public/assets/* ]]; then
        continue
    fi
    if [[ "${rel}" == node_modules/* ]]; then
        continue
    fi

    src="${OVERRIDES_DIR}/${rel}"
    dst="${PANEL_DIR}/${rel}"

    if [[ -f "${src}" ]]; then
        # Buat directory tujuan kalau belum ada
        mkdir -p "$(dirname "${dst}")"
        # Copy file
        cp -f "${src}" "${dst}"
        echo "[deploy-panel] Deployed: ${rel}"
    fi
done < <(cd "${OVERRIDES_DIR}" && find . -type f | sed 's#^\./##' | sort)

# Sync public/assets (biasanya hasil build webpack/yarn)
if [[ -d "${OVERRIDES_DIR}/public/assets" ]]; then
    echo "[deploy-panel] Syncing public/assets..."
    if command -v rsync >/dev/null 2>&1; then
        rsync -a "${OVERRIDES_DIR}/public/assets/" "${PANEL_DIR}/public/assets/"
    else
        cp -rp "${OVERRIDES_DIR}/public/assets/." "${PANEL_DIR}/public/assets/"
    fi
fi

# Fix permissions
echo "[deploy-panel] Setting permissions (chown -R www-data:www-data)..."
chown -R www-data:www-data "${PANEL_DIR}" || true

echo "[deploy-panel] Deployment finished! 🚀"

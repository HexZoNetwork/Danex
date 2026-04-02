#!/usr/bin/env bash
set -euo pipefail

PANEL_DIR="${PANEL_DIR:-/var/www/pterodactyl}"
OVERRIDES_DIR="${OVERRIDES_DIR:-/pteroprotect/panel_overrides}"

if [[ ! -d "${PANEL_DIR}" ]]; then
    echo "[panel-sync] panel directory not found: ${PANEL_DIR}" >&2
    exit 1
fi

if [[ ! -d "${OVERRIDES_DIR}" ]]; then
    echo "[panel-sync] overrides directory not found: ${OVERRIDES_DIR}" >&2
    exit 1
fi

changed=0
while IFS= read -r rel; do
    [[ -z "${rel}" ]] && continue
    if [[ "${rel}" == public/assets/* ]]; then
        continue
    fi

    src="${PANEL_DIR}/${rel}"
    dst="${OVERRIDES_DIR}/${rel}"

    if [[ ! -f "${src}" ]]; then
        continue
    fi

    if [[ ! -f "${dst}" ]] || ! cmp -s "${src}" "${dst}"; then
        mkdir -p "$(dirname "${dst}")"
        cp -f "${src}" "${dst}"
        echo "[panel-sync] synced ${rel}"
        changed=1
    fi
done < <(cd "${OVERRIDES_DIR}" && find . -type f | sed 's#^\./##' | sort)

if [[ -d "${PANEL_DIR}/public/assets" && -d "${OVERRIDES_DIR}/public/assets" ]]; then
    if command -v rsync >/dev/null 2>&1; then
        rsync -a --delete "${PANEL_DIR}/public/assets/" "${OVERRIDES_DIR}/public/assets/"
        echo "[panel-sync] synced public/assets (rsync --delete)"
        changed=1
    else
        cp -a "${PANEL_DIR}/public/assets/." "${OVERRIDES_DIR}/public/assets/"
        echo "[panel-sync] synced public/assets (cp -a)"
        changed=1
    fi
fi

if [[ "${changed}" -eq 0 ]]; then
    echo "[panel-sync] no changes"
fi

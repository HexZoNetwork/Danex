#!/usr/bin/env bash
set -euo pipefail

PANEL_DIR="${PANEL_DIR:-/var/www/pterodactyl}"
OVERRIDES_DIR="${OVERRIDES_DIR:-/pteroprotect/panel_overrides}"
SYNC_SCRIPT="${SYNC_SCRIPT:-/pteroprotect/scripts/sync_panel_overrides.sh}"

if [[ ! -d "${PANEL_DIR}" ]]; then
    echo "[panel-sync] panel directory not found: ${PANEL_DIR}" >&2
    exit 1
fi

if [[ ! -d "${OVERRIDES_DIR}" ]]; then
    echo "[panel-sync] overrides directory not found: ${OVERRIDES_DIR}" >&2
    exit 1
fi

if ! command -v inotifywait >/dev/null 2>&1; then
    echo "[panel-sync] inotifywait not installed (install inotify-tools)" >&2
    exit 1
fi

if [[ ! -x "${SYNC_SCRIPT}" ]]; then
    echo "[panel-sync] sync script missing or not executable: ${SYNC_SCRIPT}" >&2
    exit 1
fi

"${SYNC_SCRIPT}" || true

echo "[panel-sync] watching ${PANEL_DIR} for override file changes..."

watch_paths=()
while IFS= read -r rel; do
    [[ -z "${rel}" ]] && continue
    dir="$(dirname "${PANEL_DIR}/${rel}")"
    watch_paths+=("${dir}")
done < <(cd "${OVERRIDES_DIR}" && find . -type f | sed 's#^\./##' | sort)

if [[ "${#watch_paths[@]}" -eq 0 ]]; then
    echo "[panel-sync] no override files to watch"
    exit 0
fi

mapfile -t uniq_watch_paths < <(printf '%s\n' "${watch_paths[@]}" | sort -u)

debounce=0
while true; do
    if inotifywait -qq -e close_write,move,create,delete "${uniq_watch_paths[@]}"; then
        now="$(date +%s)"
        if (( now - debounce < 1 )); then
            continue
        fi
        debounce="${now}"
        "${SYNC_SCRIPT}" || true
    fi
done

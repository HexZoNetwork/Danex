#!/usr/bin/env bash
set -euo pipefail

VROOT="${PTERO_VOLUME_ROOT:-/var/lib/pterodactyl/volumes}"
[[ -d "${VROOT}" ]] || exit 0

# Keep the volume roots and top-level app directories traversable/writable for
# the container runtime without walking every server file on every timer tick.
# Rootless/idmapped Docker can show these as nobody:nogroup on the host while
# still mapping correctly inside the container.
find "${VROOT}" -mindepth 1 -maxdepth 2 -type d -exec chmod 0777 {} \; 2>/dev/null || true

# Package managers commonly fail server startup if an old/manual recovery path
# left their cache directories owned by root. Fix the known hot paths every run
# so newly-created servers are corrected shortly after the volume appears.
find "${VROOT}" -type d \( \
    -name .npm -o \
    -name .cache -o \
    -name .pnpm-store -o \
    -name .yarn -o \
    -path '*/node_modules/.cache' -o \
    -path '*/node_modules/.bin' \
\) -exec chmod -R 0777 {} \; 2>/dev/null || true

if command -v setfacl >/dev/null 2>&1; then
  setfacl -R -m u::rwx,g::rwx,o::rwx "${VROOT}" 2>/dev/null || true
  setfacl -R -d -m u::rwx,g::rwx,o::rwx "${VROOT}" 2>/dev/null || true
fi

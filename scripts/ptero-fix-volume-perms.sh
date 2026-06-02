#!/usr/bin/env bash
set -euo pipefail

VROOT="${PTERO_VOLUME_ROOT:-/var/lib/pterodactyl/volumes}"
[[ -d "${VROOT}" ]] || exit 0

# Keep the volume roots and top-level app directories traversable/writable for
# the container runtime without walking every server file on every timer tick.
# Rootless/idmapped Docker can show these as nobody:nogroup on the host while
# still mapping correctly inside the container.
find "${VROOT}" -mindepth 1 -maxdepth 2 -type d -exec chmod 0777 {} + 2>/dev/null || true

# Package managers and Node entrypoints commonly fail server startup if
# old/manual recovery left root-owned files as 0600/0660. Keep manifests and
# common runtime source files readable so Node can open /home/container/index.js
# and similar entrypoints even before a deep ownership repair runs.
find "${VROOT}" -maxdepth 4 -type f \( \
    -name package.json -o \
    -name package-lock.json -o \
    -name npm-shrinkwrap.json -o \
    -name pnpm-lock.yaml -o \
    -name yarn.lock -o \
    -name '*.js' -o \
    -name '*.cjs' -o \
    -name '*.mjs' -o \
    -name '*.ts' -o \
    -name '*.json' \
\) -exec chmod u+rw,go+r {} + 2>/dev/null || true

find "${VROOT}" -type d \( \
    -name .npm -o \
    -name .cache -o \
    -name .pnpm-store -o \
    -name .yarn -o \
    -path '*/node_modules/.cache' -o \
    -path '*/node_modules/.bin' \
\) -exec chmod -R 0777 {} \; 2>/dev/null || true

if command -v setfacl >/dev/null 2>&1; then
  setfacl -m u::rwx,g::rwx,o::rwx "${VROOT}" 2>/dev/null || true
  setfacl -d -m u::rwx,g::rwx,o::rwx "${VROOT}" 2>/dev/null || true
  find "${VROOT}" -mindepth 1 -maxdepth 2 -type d -exec setfacl -m u::rwx,g::rwx,o::rwx {} + 2>/dev/null || true
  find "${VROOT}" -mindepth 1 -maxdepth 2 -type d -exec setfacl -d -m u::rwx,g::rwx,o::rwx {} + 2>/dev/null || true
fi

if [[ "${PTERO_FIX_VOLUME_PERMS_DEEP:-0}" == "1" ]]; then
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
fi

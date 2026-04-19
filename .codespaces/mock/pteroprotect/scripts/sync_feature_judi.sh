#!/usr/bin/env bash
set -euo pipefail

# Sync hanya file fitur Judi / DanexCoin (bukan full project).
LIVE_DIR="/var/www/pterodactyl"
OVERRIDE_DIR="/root/porn/panel_overrides"

copy_file() {
  local rel="$1"
  mkdir -p "${OVERRIDE_DIR}/$(dirname "$rel")"
  cp -f "${LIVE_DIR}/${rel}" "${OVERRIDE_DIR}/${rel}"
  echo "synced: ${rel}"
}

copy_file "app/Http/Controllers/Api/Client/DanexCoinController.php"
copy_file "app/Http/Controllers/Admin/DanexCoinController.php"
copy_file "database/migrations/2026_03_26_000100_add_danex_coin_to_users_table.php"
copy_file "database/migrations/2026_03_26_000200_create_danexcoin_spin_logs_table.php"
copy_file "resources/scripts/components/elements/SubNavigation.tsx"
copy_file "resources/scripts/components/dashboard/DanexCoinPage.tsx"
copy_file "resources/scripts/api/danexcoin.ts"
copy_file "resources/scripts/assets/danexcoin/seven.svg"
copy_file "resources/scripts/assets/danexcoin/bar.svg"
copy_file "resources/scripts/assets/danexcoin/cherry.svg"
copy_file "resources/scripts/assets/danexcoin/diamond.svg"
copy_file "resources/scripts/assets/danexcoin/bell.svg"
copy_file "resources/scripts/assets/danexcoin/star.svg"
copy_file "resources/scripts/routers/DashboardRouter.tsx"
copy_file "resources/views/layouts/admin.blade.php"
copy_file "resources/views/admin/danexcoin/index.blade.php"
copy_file "routes/admin.php"
copy_file "routes/api-client.php"

echo "done: synced only Judi/DanexCoin feature files."

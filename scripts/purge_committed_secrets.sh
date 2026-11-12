#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="${PTEROPROTECT_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
REPORT="${ROOT_DIR}/runtime/secret-rotation-report.txt"
MODE="report"

usage() {
    cat <<'USAGE'
Usage: purge_committed_secrets.sh [--report] [--purge-history]

  --report          Identify tracked history that contains mock/committed credentials (default).
  --purge-history   Show the history-rewrite command. Requires PTEROPROTECT_CONFIRM_PURGE=1
                    and git-filter-repo. This script never rotates live credentials.

Rotate every exposed credential BEFORE rewriting history:
  - Telegram bot token: revoke via @BotFather, then update telegram.token.
  - Database password: ALTER USER/panel .env, then update database.password.
  - PteroProtect control secrets: run scripts/security_startup_policy.py --generate-missing.
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --report) MODE="report" ;;
        --purge-history) MODE="purge" ;;
        -h|--help) usage; exit 0 ;;
        *) echo "unknown argument: $1" >&2; usage >&2; exit 2 ;;
    esac
    shift
done

cd "$ROOT_DIR"
if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "not a git repository: $ROOT_DIR" >&2
    exit 1
fi

mkdir -p "$(dirname "$REPORT")"
{
    echo "PteroProtect committed-secret rotation report"
    echo "generated: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo
    echo "Suspicious paths present in git history:"
    git log --all --name-only --pretty=format: -- \
        '.codespaces' 'config.json' 'runtime/real-panel-db-password.txt' \
        'runtime/real-pterodactyl.conf' 'runtime/codespaces.env' 2>/dev/null \
        | sed '/^$/d' | sort -u | sed 's/^/  - /'
    echo
    echo "Commits touching those paths:"
    git log --all --oneline -- \
        '.codespaces' 'config.json' 'runtime/real-panel-db-password.txt' \
        'runtime/real-pterodactyl.conf' 'runtime/codespaces.env' 2>/dev/null \
        | sed 's/^/  /'
} > "$REPORT"

cat "$REPORT"
echo
echo "Report written to $REPORT"

if [[ "$MODE" == "purge" ]]; then
    if [[ "${PTEROPROTECT_CONFIRM_PURGE:-0}" != "1" ]]; then
        echo "Refusing history rewrite. Re-run with PTEROPROTECT_CONFIRM_PURGE=1 after rotating credentials." >&2
        exit 1
    fi
    if ! command -v git-filter-repo >/dev/null 2>&1; then
        echo "git-filter-repo is required for a safe history rewrite." >&2
        echo "Install it, make a fresh mirror clone, then run:" >&2
        echo "  git filter-repo --path .codespaces --path config.json --invert-paths" >&2
        exit 1
    fi
    echo "Rewriting history in a temporary mirror clone (originals are not touched)..."
    TMP_DIR="$(mktemp -d)"
    trap 'rm -rf "$TMP_DIR"' EXIT
    git clone --mirror "$ROOT_DIR" "$TMP_DIR/repo.git"
    git -C "$TMP_DIR/repo.git" filter-repo --force \
        --path .codespaces --path config.json --invert-paths
    echo "Scrubbed mirror created at $TMP_DIR/repo.git"
    echo "Review it, then force-push intentionally: git push --mirror <remote>"
fi

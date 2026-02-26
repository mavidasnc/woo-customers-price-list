#!/usr/bin/env bash
# =============================================================================
# deploy.sh — Upload the plugin to the production FTP server
#
# Requirements:
#   lftp must be installed on your system.
#     Debian/Ubuntu : sudo apt install lftp
#     macOS         : brew install lftp
#     Windows (WSL) : sudo apt install lftp
#
# Usage:
#   ./deploy.sh              # Full mirror — overwrites remote, deletes orphans
#   ./deploy.sh --dry-run    # Simulate without touching the server
#
# Credentials are read from .env.deploy (never committed to git).
# Copy .env.deploy.example → .env.deploy and fill in your values.
# =============================================================================

set -euo pipefail

# ── Resolve script directory ─────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Load credentials from .env.deploy ────────────────────────────────────────
ENV_FILE="$SCRIPT_DIR/.env.deploy"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Error: .env.deploy not found."
  echo "Copy .env.deploy.example to .env.deploy and fill in your credentials."
  exit 1
fi

# shellcheck source=.env.deploy
source "$ENV_FILE"

# ── Validate required variables ───────────────────────────────────────────────
: "${FTP_HOST:?FTP_HOST is not set in .env.deploy}"
: "${FTP_USER:?FTP_USER is not set in .env.deploy}"
: "${FTP_PASS:?FTP_PASS is not set in .env.deploy}"
: "${FTP_REMOTE_PATH:?FTP_REMOTE_PATH is not set in .env.deploy}"
: "${FTP_PORT:=21}"

# ── Paths ─────────────────────────────────────────────────────────────────────
# The remote plugin directory is: $FTP_REMOTE_PATH/<plugin-slug>/
PLUGIN_SLUG="woo-dynamic-pricelist-pro"
LOCAL_DIR="$SCRIPT_DIR"
REMOTE_DIR="$FTP_REMOTE_PATH/$PLUGIN_SLUG"

# ── Parse arguments ───────────────────────────────────────────────────────────
DRY_RUN=false

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=true  ;;
    *)
      echo "Unknown argument: $arg"
      echo "Usage: ./deploy.sh [--dry-run]"
      exit 1
      ;;
  esac
done

# ── Banner ────────────────────────────────────────────────────────────────────
echo "========================================================"
echo " WooCommerce Dynamic Price List Pro — FTP Deploy"
echo "========================================================"
echo " Host   : $FTP_HOST:$FTP_PORT"
echo " Remote : $REMOTE_DIR"
echo " Local  : $LOCAL_DIR"
if [[ "$DRY_RUN" == true ]]; then
  echo " Mode   : DRY-RUN (no changes will be made on the server)"
else
  echo " Mode   : LIVE"
fi
echo "--------------------------------------------------------"

# ── Confirm before live deploy ────────────────────────────────────────────────
if [[ "$DRY_RUN" == false ]]; then
  read -rp "Proceed with live deploy? [y/N] " confirm
  case "$confirm" in
    [yY][eE][sS]|[yY]) : ;;
    *)
      echo "Deploy cancelled."
      exit 0
      ;;
  esac
fi

# ── Run lftp mirror ───────────────────────────────────────────────────────────
# Files excluded from the deploy (dev/meta files with no purpose on production):
#   CLAUDE.md, CHANGES.md, README.md  — documentation for developers
#   .gitignore                         — git configuration
#   deploy.sh, .env.deploy*            — deployment infrastructure
#   *.log                              — any stray log files in the project root
#   .gitkeep                           — empty-folder placeholders

MIRROR_FLAGS="--reverse --delete --verbose --parallel=4"
[[ "$DRY_RUN" == true ]] && MIRROR_FLAGS="$MIRROR_FLAGS --dry-run"

lftp -u "$FTP_USER","$FTP_PASS" "ftp://$FTP_HOST:$FTP_PORT" <<LFTP_COMMANDS
# Disable SSL/TLS: the server offers AUTH TLS but uses a self-signed certificate.
# Plain FTP on port 21 — no encryption needed for this deployment.
set ftp:ssl-allow no
set ftp:passive-mode yes
set net:timeout 30
set net:max-retries 3
set net:reconnect-interval-base 5

mirror $MIRROR_FLAGS \
  --exclude-glob CLAUDE.md \
  --exclude-glob CHANGES.md \
  --exclude-glob README.md \
  --exclude-glob .gitignore \
  --exclude-glob deploy.sh \
  --exclude-glob .env.deploy \
  --exclude-glob .env.deploy.example \
  --exclude-glob '*.log' \
  --exclude-glob .gitkeep \
  "$LOCAL_DIR" "$REMOTE_DIR"

bye
LFTP_COMMANDS

# ── Result ────────────────────────────────────────────────────────────────────
echo "--------------------------------------------------------"
if [[ "$DRY_RUN" == true ]]; then
  echo " Dry-run complete. No files were modified on the server."
else
  echo " Deploy complete."
fi
echo "========================================================"

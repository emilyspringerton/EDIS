#!/usr/bin/env bash
# ops/deploy.sh — deploy/update EDIS WordPress plugins + theme
#
# Run after pulling new commits from git.
# Safe to run repeatedly (idempotent).
#
# Usage:
#   sudo bash ops/deploy.sh                   # full deploy
#   sudo bash ops/deploy.sh --plugins-only    # skip cert + nginx
#   sudo bash ops/deploy.sh --nginx-only      # only reload nginx
#
# Requirements:
#   - WordPress installed at /var/www/edis
#   - WP-CLI available as `wp`

set -euo pipefail

WP_PATH="/var/www/edis"
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGINS_SRC="${REPO_DIR}/plugins"
THEMES_SRC="${REPO_DIR}/themes"
WP_PLUGINS="${WP_PATH}/wp-content/plugins"
WP_THEMES="${WP_PATH}/wp-content/themes"

PLUGINS_ONLY=false
NGINX_ONLY=false
for arg in "$@"; do
    case $arg in
        --plugins-only) PLUGINS_ONLY=true ;;
        --nginx-only)   NGINX_ONLY=true ;;
    esac
done

echo "=== EDIS deploy (repo: ${REPO_DIR}) ==="

if [ "${NGINX_ONLY}" = true ]; then
    nginx -t && systemctl reload nginx
    echo "nginx reloaded"
    exit 0
fi

# ── Sync plugins ──────────────────────────────────────────────────────────────
echo "Syncing plugins..."
for plugin_dir in "${PLUGINS_SRC}"/edis-*/; do
    plugin_name=$(basename "${plugin_dir}")
    rsync -a --delete "${plugin_dir}" "${WP_PLUGINS}/${plugin_name}/"
    echo "  ✓ ${plugin_name}"
done

# ── Sync theme ────────────────────────────────────────────────────────────────
echo "Syncing theme..."
rsync -a --delete "${THEMES_SRC}/edis/" "${WP_THEMES}/edis/"
echo "  ✓ edis theme"

# ── Activate plugins if not already active ────────────────────────────────────
if command -v wp &>/dev/null; then
    echo "Activating plugins..."
    wp --path="${WP_PATH}" plugin activate \
        edis-core edis-signals edis-ask-emily edis-earnings edis-dis \
        --allow-root 2>/dev/null || true
    wp --path="${WP_PATH}" theme activate edis --allow-root 2>/dev/null || true
    wp --path="${WP_PATH}" rewrite flush --allow-root 2>/dev/null || true
    echo "  ✓ plugins activated, rewrites flushed"
fi

# ── Fix file ownership ────────────────────────────────────────────────────────
if id www-data &>/dev/null; then
    chown -R www-data:www-data "${WP_PATH}/wp-content/plugins/edis-"*
    chown -R www-data:www-data "${WP_PATH}/wp-content/themes/edis"
fi

if [ "${PLUGINS_ONLY}" = true ]; then
    echo "=== Done (plugins only) ==="
    exit 0
fi

# ── Reload nginx ──────────────────────────────────────────────────────────────
if systemctl is-active --quiet nginx; then
    nginx -t && systemctl reload nginx
    echo "nginx reloaded"
fi

echo "=== EDIS deploy complete ==="

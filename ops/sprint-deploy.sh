#!/usr/bin/env bash
# ops/sprint-deploy.sh — S23-01 full deploy sprint for iduna.farthq.com
#
# Phases:
#   1. WordPress + EDIS plugins (ops/install.sh)
#   2. DIS collector daemon install
#   3. Let's Encrypt SSL (ops/certbot/setup.sh)
#   4. WordPress URL upgrade to HTTPS
#   5. Smoke tests
#
# Usage: sudo bash /home/fatbaby/EDIS/ops/sprint-deploy.sh
# Idempotent: safe to re-run. Each phase checks its own state.

set -euo pipefail

DOMAIN="iduna.farthq.com"
WP_PATH="/var/www/edis"
REPO_DIR="/home/fatbaby/EDIS"
DIS_BIN_SRC="/tmp/edis-dis"
DIS_BIN="/usr/local/bin/edis-dis"

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  EDIS Sprint Deploy — S23-01: WordPress Live                ║"
echo "╠══════════════════════════════════════════════════════════════╣"
echo "║  Domain: ${DOMAIN}                           ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

# ── Phase 1: WordPress + EDIS stack ──────────────────────────────────────────
echo "━━━ Phase 1: WordPress + EDIS stack ━━━"
bash "${REPO_DIR}/ops/install.sh"

# ── Phase 2: DIS collector daemon ────────────────────────────────────────────
echo ""
echo "━━━ Phase 2: DIS collector daemon ━━━"

# Install binary from pre-built or build now.
if [ -f "${DIS_BIN_SRC}" ]; then
    cp "${DIS_BIN_SRC}" "${DIS_BIN}"
    echo "    ✓ edis-dis installed from ${DIS_BIN_SRC}"
else
    echo "    Building edis-dis from source..."
    (cd "${REPO_DIR}" && go build -o "${DIS_BIN}" ./cmd/dis)
    echo "    ✓ edis-dis built + installed"
fi
chmod +x "${DIS_BIN}"

# Install systemd unit.
cp "${REPO_DIR}/ops/dis.service" /etc/systemd/system/edis-dis.service
systemctl daemon-reload
systemctl enable --now edis-dis
sleep 1
systemctl is-active edis-dis && echo "    ✓ edis-dis running" || echo "    ⚠ edis-dis failed to start — check: journalctl -u edis-dis"

# ── Phase 3: Let's Encrypt SSL ───────────────────────────────────────────────
echo ""
echo "━━━ Phase 3: SSL certificate (Let's Encrypt) ━━━"
echo "    Obtaining cert for ${DOMAIN}..."
bash "${REPO_DIR}/ops/certbot/setup.sh"
echo "    ✓ SSL certificate obtained"

# ── Phase 4: Update WordPress URLs to HTTPS ──────────────────────────────────
echo ""
echo "━━━ Phase 4: Upgrade WordPress site URL to HTTPS ━━━"
CURRENT_URL=$(wp option get siteurl --path="${WP_PATH}" --allow-root 2>/dev/null || echo "")
if [[ "${CURRENT_URL}" == "http://"* ]]; then
    wp option update siteurl "https://${DOMAIN}" --path="${WP_PATH}" --allow-root
    wp option update home   "https://${DOMAIN}" --path="${WP_PATH}" --allow-root
    echo "    ✓ WordPress siteurl + home updated to https://${DOMAIN}"
else
    echo "    ✓ WordPress already using HTTPS (${CURRENT_URL})"
fi

# Fix any mixed-content issues in post content.
wp search-replace "http://${DOMAIN}" "https://${DOMAIN}" \
    --path="${WP_PATH}" --allow-root --skip-columns=guid 2>/dev/null || true

# ── Phase 5: Smoke tests ─────────────────────────────────────────────────────
echo ""
echo "━━━ Phase 5: Smoke tests ━━━"

check() {
    local label="$1"
    local url="$2"
    local expected="$3"
    local code
    code=$(curl -sk -o /dev/null -w "%{http_code}" "${url}" 2>/dev/null || echo "000")
    if [ "${code}" = "${expected}" ]; then
        echo "    ✓ ${label} → HTTP ${code}"
    else
        echo "    ✗ ${label} → HTTP ${code} (expected ${expected})"
    fi
}

check "Homepage HTTPS"          "https://${DOMAIN}/"                  "200"
check "WordPress admin"         "https://${DOMAIN}/wp-admin/"         "302"
check "Ask Emily page"          "https://${DOMAIN}/ask/"              "200"
check "Ticker page /AAPL"       "https://${DOMAIN}/ticker/AAPL"       "200"
check "IDUNA API health"        "https://${DOMAIN}/api/v1/health"     "200"
check "HTTP→HTTPS redirect"     "http://${DOMAIN}/"                   "301"
check "DIS health (localhost)"  "http://127.0.0.1:9099/dis/health"    "200"

# Read creds for summary.
CRED_FILE="/root/.edis-deploy-creds"
WP_ADMIN_PASS=""
[ -f "${CRED_FILE}" ] && source "${CRED_FILE}"

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  EDIS S23-01 COMPLETE — iduna.farthq.com is LIVE            ║"
echo "╠══════════════════════════════════════════════════════════════╣"
printf "║  URL:        https://%-41s║\n" "${DOMAIN}"
printf "║  WP Admin:   https://%-41s║\n" "${DOMAIN}/wp-admin/"
printf "║  WP User:    %-47s║\n" "emily"
[ -n "${WP_ADMIN_PASS}" ] && printf "║  WP Pass:    %-47s║\n" "${WP_ADMIN_PASS}"
echo "╠══════════════════════════════════════════════════════════════╣"
echo "║  IDUNA API:  https://${DOMAIN}/api/v1/*            ║"
echo "║  Ticker:     https://${DOMAIN}/ticker/AAPL         ║"
echo "║  Ask Emily:  https://${DOMAIN}/ask/                ║"
echo "╠══════════════════════════════════════════════════════════════╣"
echo "║  NEXT: start FatBaby signalapi for live signal data         ║"
echo "║    emily start --signalapi                                  ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

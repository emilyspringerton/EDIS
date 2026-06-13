#!/usr/bin/env bash
# ops/certbot/setup.sh — obtain + install Let's Encrypt certificate for edis.fatbaby.io
#
# Run ONCE after DNS is pointing at this server and nginx is serving port 80.
# Certbot rewrites ops/nginx/edis.conf in-place with the managed cert paths
# and sets up auto-renewal via systemd timer or cron.
#
# Requirements:
#   - nginx running (systemctl start nginx)
#   - DNS A record: edis.fatbaby.io → this server's public IP
#   - Port 80 open in firewall (UFW: ufw allow 80/tcp && ufw allow 443/tcp)
#
# Usage:
#   sudo bash ops/certbot/setup.sh
#
# Renewal (automatic — no action needed after first run):
#   certbot renews automatically via /etc/cron.d/certbot or systemd timer.
#   Test renewal: sudo certbot renew --dry-run

set -euo pipefail

DOMAIN="edis.fatbaby.io"
EMAIL="emilyspringerton@gmail.com"
NGINX_CONF="/etc/nginx/sites-available/edis"
WEBROOT="/var/www/html"

echo "=== EDIS certbot setup for ${DOMAIN} ==="

# ── 1. Install certbot if missing ─────────────────────────────────────────────
if ! command -v certbot &>/dev/null; then
    echo "Installing certbot..."
    apt-get update -qq
    apt-get install -y -qq certbot python3-certbot-nginx
fi

# ── 2. Ensure nginx site config is deployed ───────────────────────────────────
if [ ! -f "${NGINX_CONF}" ]; then
    echo "Deploying nginx config..."
    cp "$(dirname "$0")/../nginx/edis.conf" "${NGINX_CONF}"
    ln -sf "${NGINX_CONF}" /etc/nginx/sites-enabled/edis
fi

# ── 3. Test nginx config before proceeding ────────────────────────────────────
nginx -t

# ── 4. Ensure nginx is running so the ACME HTTP-01 challenge can complete ─────
systemctl enable nginx
systemctl start nginx || systemctl reload nginx

# ── 5. Obtain certificate using the nginx plugin ──────────────────────────────
# The --nginx flag rewrites the config file with managed cert paths and
# sets up an HTTPS server block automatically.
certbot --nginx \
    --non-interactive \
    --agree-tos \
    --email "${EMAIL}" \
    --domains "${DOMAIN},www.${DOMAIN}" \
    --redirect

# ── 6. Reload nginx with the new TLS config ───────────────────────────────────
systemctl reload nginx

# ── 7. Verify auto-renewal ────────────────────────────────────────────────────
echo ""
echo "=== Verifying auto-renewal ==="
certbot renew --dry-run

echo ""
echo "=== Done. Certificate issued for ${DOMAIN} ==="
echo "    Cert path: /etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
echo "    Auto-renewal: check with: systemctl status certbot.timer"
echo "    Manual renewal: sudo certbot renew"

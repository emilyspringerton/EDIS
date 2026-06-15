#!/usr/bin/env bash
# ops/install.sh — full EDIS WordPress stack install
#
# Run as fatbaby with sudo access:
#   sudo bash /home/fatbaby/EDIS/ops/install.sh
#
# What this does:
#   1. Install nginx, PHP 8.3-FPM, certbot, WP-CLI
#   2. Create MySQL database + user for WordPress
#   3. Download + configure + install WordPress
#   4. Sync EDIS plugins + theme
#   5. Configure nginx on port 80 (HTTP only, ACME-ready)
#   6. Start services
#
# After DNS is set (iduna.farthq.com → <server-ip>), run:
#   sudo bash /home/fatbaby/EDIS/ops/certbot/setup.sh
#
# Idempotent: safe to re-run.

set -euo pipefail

DOMAIN="iduna.farthq.com"
WP_PATH="/var/www/edis"
WP_ADMIN_USER="emily"
WP_ADMIN_EMAIL="emilyspringerton@gmail.com"
REPO_DIR="/home/fatbaby/EDIS"
PHP_VER="8.3"
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"

# Stable generated credentials — re-running the script reuses these.
CRED_FILE="/root/.edis-deploy-creds"
if [ -f "${CRED_FILE}" ]; then
    source "${CRED_FILE}"
else
    WP_DB_PASS="$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 32)"
    WP_ADMIN_PASS="$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 32)"
    cat > "${CRED_FILE}" <<CREDS
WP_DB_PASS="${WP_DB_PASS}"
WP_ADMIN_PASS="${WP_ADMIN_PASS}"
CREDS
    chmod 600 "${CRED_FILE}"
    echo "Generated credentials saved to ${CRED_FILE}"
fi

echo ""
echo "=== EDIS WordPress install — ${DOMAIN} ==="
echo "    WP path:    ${WP_PATH}"
echo "    Admin user: ${WP_ADMIN_USER}"
echo "    Admin pass: ${WP_ADMIN_PASS}   ← save this"
echo ""

# ── 1. Dependencies ───────────────────────────────────────────────────────────
echo "[1/6] Installing dependencies..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq \
    nginx \
    "php${PHP_VER}-fpm" \
    "php${PHP_VER}-mysql" \
    "php${PHP_VER}-mbstring" \
    "php${PHP_VER}-curl" \
    "php${PHP_VER}-xml" \
    "php${PHP_VER}-zip" \
    "php${PHP_VER}-gd" \
    "php${PHP_VER}-intl" \
    "php${PHP_VER}-bcmath" \
    certbot \
    python3-certbot-nginx \
    rsync \
    curl \
    unzip

# Install WP-CLI if missing.
if ! command -v wp &>/dev/null; then
    curl -sL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp
    chmod +x /usr/local/bin/wp
fi
echo "    ✓ packages installed  php=$(php --version | head -1 | awk '{print $2}')  nginx=$(nginx -v 2>&1 | awk -F/ '{print $2}')"

# ── 2. MySQL: create DB + user ────────────────────────────────────────────────
echo "[2/6] Configuring MySQL..."
mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS edis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'edis'@'localhost' IDENTIFIED BY '${WP_DB_PASS}';
GRANT ALL PRIVILEGES ON edis.* TO 'edis'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "    ✓ database 'edis' ready"

# ── 3. Download + configure WordPress ────────────────────────────────────────
echo "[3/6] Setting up WordPress..."
mkdir -p "${WP_PATH}"
if [ ! -f "${WP_PATH}/wp-load.php" ]; then
    wp core download --path="${WP_PATH}" --locale=en_US --allow-root
fi

if [ ! -f "${WP_PATH}/wp-config.php" ]; then
    wp config create \
        --path="${WP_PATH}" \
        --dbname=edis \
        --dbuser=edis \
        --dbpass="${WP_DB_PASS}" \
        --dbhost=127.0.0.1 \
        --allow-root \
        --extra-php <<'WPPHP'
define( 'EDIS_SIGNALAPI_URL',  getenv('EDIS_SIGNALAPI_URL')  ?: 'http://localhost:9091' );
define( 'EDIS_EMILY_URL',      getenv('EDIS_EMILY_URL')      ?: 'http://localhost:8086' );
define( 'EDIS_IDUNA_BASE_URL', getenv('EDIS_IDUNA_BASE_URL') ?: 'http://localhost:8080' );
define( 'EDIS_CACHE_TTL',      60 );
define( 'WP_DEBUG',            false );
define( 'DISALLOW_FILE_EDIT',  true );
WPPHP
fi

if ! wp core is-installed --path="${WP_PATH}" --allow-root 2>/dev/null; then
    wp core install \
        --path="${WP_PATH}" \
        --url="http://${DOMAIN}" \
        --title="IDUNA Intelligence Platform" \
        --admin_user="${WP_ADMIN_USER}" \
        --admin_password="${WP_ADMIN_PASS}" \
        --admin_email="${WP_ADMIN_EMAIL}" \
        --skip-email \
        --allow-root
fi
echo "    ✓ WordPress installed"

# ── 4. Sync EDIS plugins + theme ──────────────────────────────────────────────
echo "[4/6] Deploying EDIS plugins + theme..."
for plugin_dir in "${REPO_DIR}"/plugins/edis-*/; do
    name=$(basename "${plugin_dir}")
    rsync -a --delete "${plugin_dir}" "${WP_PATH}/wp-content/plugins/${name}/"
    echo "    ✓ ${name}"
done
rsync -a --delete "${REPO_DIR}/themes/edis/" "${WP_PATH}/wp-content/themes/edis/"
echo "    ✓ edis theme"

# Activate plugins + theme, configure rewrites.
wp --path="${WP_PATH}" plugin activate \
    edis-core edis-signals edis-ask-emily edis-earnings edis-dis --allow-root 2>/dev/null || true
wp --path="${WP_PATH}" theme activate edis --allow-root 2>/dev/null || true
wp --path="${WP_PATH}" rewrite structure '/%postname%/' --hard --allow-root 2>/dev/null || true
wp --path="${WP_PATH}" rewrite flush --allow-root 2>/dev/null || true

# Create the /ticker/ page with the Ticker Page template if it doesn't exist.
TICKER_PAGE_ID=$(wp --path="${WP_PATH}" post list --post_type=page --field=ID \
    --name=ticker --allow-root 2>/dev/null | head -1 || true)
if [ -z "${TICKER_PAGE_ID}" ]; then
    wp --path="${WP_PATH}" post create \
        --post_type=page \
        --post_title="Ticker" \
        --post_status=publish \
        --post_name=ticker \
        --page_template="page-ticker.php" \
        --allow-root 2>/dev/null || true
fi

# Fix ownership.
chown -R www-data:www-data "${WP_PATH}"
find "${WP_PATH}" -type d -exec chmod 755 {} \;
find "${WP_PATH}" -type f -exec chmod 644 {} \;
chmod 600 "${WP_PATH}/wp-config.php"
echo "    ✓ permissions set"

# ── 5. nginx — HTTP-only bootstrap config ─────────────────────────────────────
echo "[5/6] Configuring nginx (HTTP, ACME-ready)..."
cat > /etc/nginx/sites-available/edis <<NGINXCONF
# iduna.farthq.com bootstrap — HTTP only until certbot runs
# Generated by ops/install.sh — do not edit manually.
# Run ops/certbot/setup.sh to upgrade to HTTPS.

limit_req_zone \$binary_remote_addr zone=edis_rl:10m rate=10r/s;
limit_req_zone \$binary_remote_addr zone=edis_admin_rl:10m rate=2r/s;

server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN} www.${DOMAIN};

    root  ${WP_PATH};
    index index.php;
    charset utf-8;

    gzip on;
    gzip_types text/html text/css application/json application/javascript text/xml;
    gzip_min_length 1024;

    location /.well-known/acme-challenge/ { root /var/www/html; }

    location ~* /(?:xmlrpc\\.php|wp-config\\.php|readme\\.html|license\\.txt) { deny all; return 404; }
    location ~ /\\.(?!well-known\\/) { deny all; }
    location ~* /wp-content/uploads/.*\\.php\$ { deny all; return 404; }

    location ~ ^/wp-(admin|login) {
        limit_req zone=edis_admin_rl burst=5 nodelay;
        try_files \$uri \$uri/ /index.php?\$args;
        location ~ \\.php\$ {
            include fastcgi_params;
            fastcgi_pass unix:${PHP_SOCK};
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        }
    }

    location ~* \\.(css|js|png|jpg|jpeg|gif|ico|webp|svg|woff2|woff|ttf|eot)\$ {
        expires 1y; add_header Cache-Control "public, immutable"; access_log off;
        try_files \$uri =404;
    }

    location / {
        limit_req zone=edis_rl burst=40 nodelay;
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \\.php\$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTP_PROXY "";
        fastcgi_read_timeout 60s;
    }

    location = /robots.txt  { log_not_found off; access_log off; }
    location = /favicon.ico { log_not_found off; access_log off; }
}
NGINXCONF

# Remove default site, enable edis.
rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/edis /etc/nginx/sites-enabled/edis

# Update php-fpm config for better performance.
PHP_FPM_POOL="/etc/php/${PHP_VER}/fpm/pool.d/www.conf"
if grep -q "^pm = dynamic" "${PHP_FPM_POOL}" 2>/dev/null; then
    sed -i 's/^pm.max_children = .*/pm.max_children = 10/' "${PHP_FPM_POOL}"
    sed -i 's/^pm.start_servers = .*/pm.start_servers = 2/' "${PHP_FPM_POOL}"
    sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 1/' "${PHP_FPM_POOL}"
    sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 4/' "${PHP_FPM_POOL}"
fi

nginx -t
echo "    ✓ nginx config OK"

# ── 6. Start / reload services ────────────────────────────────────────────────
echo "[6/6] Starting services..."
systemctl enable "php${PHP_VER}-fpm" nginx
systemctl restart "php${PHP_VER}-fpm"
systemctl restart nginx

# Quick smoke test — expect 200 or 301 from localhost.
sleep 1
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/ -H "Host: ${DOMAIN}" || echo "000")
echo "    ✓ nginx smoke test: HTTP ${HTTP_CODE}"

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  IDUNA WordPress is LIVE on HTTP                            ║"
echo "╠══════════════════════════════════════════════════════════════╣"
printf "║  URL:        http://%-42s║\n" "${DOMAIN}"
printf "║  WP Admin:   http://%-42s║\n" "${DOMAIN}/wp-admin/"
printf "║  User:       %-47s║\n" "${WP_ADMIN_USER}"
printf "║  Pass:       %-47s║\n" "${WP_ADMIN_PASS}"
echo "╠══════════════════════════════════════════════════════════════╣"
echo "║  Creds saved: /root/.edis-deploy-creds                     ║"
echo "╠══════════════════════════════════════════════════════════════╣"
echo "║  NEXT: point DNS then run:                                  ║"
echo "║    sudo bash /home/fatbaby/EDIS/ops/certbot/setup.sh        ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""
echo "  DNS A record needed:"
echo "    iduna.farthq.com     →  <server-public-ip>"
echo "    www.iduna.farthq.com →  <server-public-ip>"
echo ""

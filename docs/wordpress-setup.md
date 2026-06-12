# EDIS WordPress Setup Guide

*Requirements: WordPress 6.5+, PHP 8.1+, FatBaby signalapi running*

---

## 1. WordPress Installation

```bash
# Install WP-CLI
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x wp-cli.phar && sudo mv wp-cli.phar /usr/local/bin/wp

# Create database
mysql -u root -p -e "CREATE DATABASE edis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE USER 'edis'@'localhost' IDENTIFIED BY 'CHANGE_ME';"
mysql -u root -p -e "GRANT ALL ON edis.* TO 'edis'@'localhost';"

# Download and configure WordPress
cd /var/www
wp core download --path=edis
wp config create --path=edis --dbname=edis --dbuser=edis --dbpass=CHANGE_ME \
    --extra-php <<EOF
define('EDIS_SIGNALAPI_URL', 'https://api.fatbaby.io');
define('EDIS_EMILY_URL',     'https://emily.fatbaby.io');
define('EDIS_CACHE_TTL',     60);
EOF
wp core install --path=edis --url=https://edis.fatbaby.io \
    --title="EDIS Financial Intelligence" \
    --admin_user=admin --admin_email=emilyspringerton@gmail.com \
    --admin_password=CHANGE_ME
```

## 2. Install EDIS Plugins

```bash
# Copy plugins from this repo into WordPress
cd /var/www/edis
cp -r /home/fatbaby/EDIS/plugins/edis-core      wp-content/plugins/
cp -r /home/fatbaby/EDIS/plugins/edis-signals   wp-content/plugins/
cp -r /home/fatbaby/EDIS/plugins/edis-ask-emily wp-content/plugins/

# Activate (order matters: core first)
wp plugin activate edis-core edis-signals edis-ask-emily
```

## 3. Install EDIS Theme

```bash
cp -r /home/fatbaby/EDIS/themes/edis wp-content/themes/
wp theme activate edis
```

## 4. Configure Permalinks

```bash
# Enables /ticker/AAPL rewrite rules
wp rewrite structure '/%postname%/' --hard
wp rewrite flush
```

## 5. Create the /ask Page

```bash
wp post create --post_type=page --post_title='Ask Emily' \
    --post_content='[ask_emily]' --post_status=publish --post_name=ask
```

## 6. Verify Connection

Visit Settings → EDIS in the admin. The Connection Test should show:
```
✓ signalapi OK (HTTP 200)
```

If not, check `EDIS_SIGNALAPI_URL` in wp-config.php and confirm signalapi is running:
```bash
curl https://api.fatbaby.io/healthz
```

## 7. Add Ask Emily to Sidebar

Appearance → Widgets → Primary Sidebar → Add "EDIS: Ask Emily" widget.

## 8. Add Governance Signals to Sidebar

Add "EDIS: Governance Signals" widget to Primary Sidebar. Set ticker (e.g. "AAPL").

---

## Shortcode Reference

| Shortcode | Example | Output |
|-----------|---------|--------|
| `[edis_signals]` | `[edis_signals ticker="JPM" limit="5"]` | Governance signal list |
| `[edis_entity]` | `[edis_entity ticker="AAPL"]` | Board + auditor card |
| `[edis_eps]` | `[edis_eps ticker="MSFT" periods="8"]` | EPS history table |
| `[ask_emily]` | `[ask_emily ticker="GS"]` | Ask Emily chat widget |

---

## Recommended Plugins

| Plugin | Purpose |
|--------|---------|
| Yoast SEO | Sitemap, OpenGraph, meta tags |
| WP Super Cache | Full-page cache (reduces signalapi calls further) |
| WooCommerce | Emily+ subscription billing (S23-05) |
| Mailchimp for WP | Waitlist collection → Mailchimp list |

---

## Nginx Config for WordPress + EDIS

Add to your nginx server block:

```nginx
location / {
    try_files $uri $uri/ /index.php?$args;
}
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
# Block direct PHP access to plugin files (security).
location ~* /(?:uploads|files)/.*\.php$ { deny all; }
```

# EDIS — Claude Code Context

## What this is

EDIS (Emily Distributed Intelligence System) is a WordPress plugin suite that puts a
public-facing product front-end on top of the FatBaby financial signal pipeline.

WordPress handles content management, SEO, user accounts, and community features.
EDIS plugins handle all data fetching from FatBaby signalapi and Emily Prime.

## Repo structure

```
plugins/
  edis-core/           # API client + settings + transient cache
  edis-signals/        # Governance signal display (shortcodes + widgets)
  edis-ask-emily/      # Ask Emily chat (shortcode + widget + WP REST proxy)
themes/
  edis/                # Minimal starter theme
docs/
  architecture.md      # Full system design
  wordpress-setup.md   # Installation + configuration guide
  plugin-dev-guide.md  # How to extend EDIS
```

## Key env / wp-config constants

```php
define('EDIS_SIGNALAPI_URL', 'https://api.fatbaby.io');   // no trailing slash
define('EDIS_EMILY_URL',     'https://emily.fatbaby.io'); // Emily Prime :8086
define('EDIS_CACHE_TTL',     60);                          // default transient TTL seconds
```

## Stack

- PHP 8.1+
- WordPress 6.5+
- WP HTTP API for all external calls (no curl or guzzle)
- WP Transients API for caching (no Redis required at MVP)
- WP REST API for the /wp-json/edis/v1/* endpoints used by JS widgets

## Conventions

- All external calls go through `EDIS_Core_API_Client` — never call signalapi directly
- Cache every GET via `EDIS_Core_Cache::get/set` wrappers (uses WP transients)
- Plugin activation hooks must be idempotent (safe to run twice)
- No direct DB queries outside edis-core — other plugins call the API client
- PHP errors go through `error_log()` prefixed `[EDIS]`
- JS errors surfaced in widget UI, not console-only

## Testing

```bash
# Install WordPress with WP-CLI, activate plugins, run unit tests
cd plugins/edis-core && composer install && ./vendor/bin/phpunit
```

## Key dependencies (external API)

- `EDIS_SIGNALAPI_URL/v1/governance-signals` — GET signals by ticker
- `EDIS_SIGNALAPI_URL/v1/entities/{ticker}` — GET entity document
- `EDIS_SIGNALAPI_URL/v1/eps/{ticker}` — GET EPS history
- `EDIS_EMILY_URL/chat` — POST chat message to Emily Prime

## Update CHANGELOG.md for every meaningful change.

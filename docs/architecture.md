# EDIS Architecture

*Written: 2026-06-12*

---

## What EDIS Is

EDIS (Emily Distributed Intelligence System) is the **public WordPress product** built on top
of the FatBaby signal pipeline. It is not a fork of the FatBaby newssite — it is a clean
WordPress installation with purpose-built plugins that call signalapi for data.

```
FatBaby Pipeline (Go, internal)          EDIS (WordPress, public)
────────────────────────────────         ─────────────────────────
secwatch → processor → entity-graph  →   signalapi (:9091)
                                              ↑ HTTP GET
                                         edis-core plugin
                                              ↓ WP transients cache
                                         edis-signals    edis-ask-emily
                                              ↓                ↓
                                         [shortcodes]    /wp-json/edis/v1/ask
                                              ↓                ↓
                                         WordPress pages  Emily Prime (:8086)
                                              ↓
                                         Browser (SEO, community, comments)
```

---

## Plugin Dependency Graph

```
edis-ask-emily ──┐
                  ├── edis-core (required)
edis-signals  ───┘
```

Both `edis-signals` and `edis-ask-emily` require `edis-core` to be active. They call
`edis_api()` (the global singleton in edis-core) and `edis_get_*()` cached accessors.

---

## Data Flow

### Governance signals (edis-signals)

1. WordPress page loads → `[edis_signals ticker="JPM" limit="5"]` shortcode fires
2. `edis_get_signals("JPM", 5)` checks WP transient `edis_<hash>` (60s TTL)
3. Cache miss → `EDIS_Core_API_Client::get_governance_signals("JPM", 5)` → GET `/v1/governance-signals?ticker=JPM&limit=5`
4. signalapi queries MySQL `governance_signals` table → JSON response
5. Result cached in WP transients, rendered via `templates/signals-list.php`

### Ask Emily (edis-ask-emily)

1. User types question in `[ask_emily]` widget, clicks "Ask Emily"
2. JS `fetch()` → POST `/wp-json/edis/v1/ask` (WP REST endpoint)
3. Rate limit check: WP transient `edis_ask_{ip_hash}_{date}` (5/day)
4. Context enrichment: `edis_ask_build_ticker_context()` calls edis-core for signals + entity
5. POST to `EDIS_EMILY_URL/chat` with `{ message, session_id }`
6. JSON `{ answer }` returned to browser, displayed in widget

---

## Caching Strategy

| Data | Cache key | TTL | Why |
|------|-----------|-----|-----|
| Governance signals | `edis_<hash(ticker/limit/type)>` | 60s | Fresh enough for live trading |
| Entity document | `edis_<hash(entity/ticker)>` | 300s | Directors change rarely |
| EPS history | `edis_<hash(eps/ticker/periods)>` | 300s | Historical, stable |
| Ask Emily rate | `edis_ask_{ip}_{date}` | 86400s | Daily reset |

All caching uses WP Transients API (works with object cache (Redis/Memcached) if installed).

---

## URL Structure

| URL | Template | Data source |
|-----|----------|-------------|
| `/` | `index.php` | WordPress posts (editorial) |
| `/ticker/AAPL` | `page-ticker.php` | signalapi (signals + entity + eps) |
| `/ask` | WordPress page + `[ask_emily]` shortcode | Emily Prime |
| `/wp-json/edis/v1/ask` | REST handler | Emily Prime |
| `/wp-json/edis/v1/waitlist` | REST handler | WP options + admin email |

Ticker pages use rewrite rules (see `themes/edis/functions.php`):
`/ticker/([A-Z]{1,6})` → `index.php?edis_ticker=$1`

---

## Configuration

All connection settings live in `wp-config.php` (preferred) or WordPress admin:

```php
// wp-config.php
define('EDIS_SIGNALAPI_URL', 'https://api.fatbaby.io');
define('EDIS_EMILY_URL',     'https://emily.fatbaby.io');
define('EDIS_CACHE_TTL',     60);
```

Or via Settings → EDIS in WordPress admin (stored in `wp_options`).

---

## Why Not Fork the FatBaby Newssite?

The FatBaby newssite is a Go HTTP server with server-rendered HTML — ideal for ops:
fast, dependency-free, always on. But it lacks:

- SEO tooling (Yoast, sitemap generation, OpenGraph)
- User accounts and authentication for Emily+ subscriptions
- Editorial workflow (draft/publish, scheduled posts)
- Comments and community features
- WooCommerce for subscription billing

WordPress provides all of these out of the box. The tradeoff is PHP overhead, but:
- Nginx proxy cache at 60s TTL means WordPress is hit once per minute per URL, not every request
- WP Transients mean signalapi is hit at most once per 60s per ticker
- The Go pipeline never touches WordPress — it only serves signalapi

---

## Future: Emily+ Subscription Gate

When Emily+ ships (S23-05):
1. WooCommerce subscription product: "Emily+" at $29/month
2. On successful purchase: IDUNA API call to provision `cap.query.full` capability
3. `edis-ask-emily` checks IDUNA session cookie before rate-limiting
4. Unlimited Ask Emily questions for paying users
5. Ticker pages show full signal history (not just last 30 days)

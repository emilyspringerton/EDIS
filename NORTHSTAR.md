# EDIS — Northstar

*Last updated: 2026-06-12*

---

## Three-Sentence Version

EDIS (Emily Distributed Intelligence System) is the public-facing WordPress product built on top
of the FatBaby signal pipeline. It gives the world a clean, SEO-optimized, community-ready
interface to governance intelligence — board changes, EPS surprises, insider transactions, director
networks — powered by simple API calls to the FatBaby signalapi. WordPress handles content,
community, and discovery; the plugins handle data; the pipeline handles intelligence.

---

## What It Is

A WordPress installation with three purpose-built plugins wired to FatBaby signalapi:

| Plugin | Job |
|--------|-----|
| `edis-core` | Settings, API client, transient cache layer |
| `edis-signals` | Shortcodes + widgets for governance signal display |
| `edis-ask-emily` | Ask Emily chat widget; proxies to Emily Prime `/chat` |

The `edis` theme provides minimal structure. WordPress does SEO, permalinks, user accounts,
and comments. Plugins do data fetching. Signalapi does intelligence.

---

## Architecture

```
Browser
  ↓
WordPress (EDIS install)
  ├── edis-core (reads EDIS_SIGNALAPI_URL, EDIS_EMILY_URL from wp-config)
  │     API client → signalapi (:9091)
  │     Transient cache (60s for signals, 300s for entity docs)
  │
  ├── edis-signals
  │     [edis_signals ticker="JPM" limit="5"] shortcode
  │     [edis_entity ticker="AAPL"] shortcode
  │     Governance Signals sidebar widget
  │
  └── edis-ask-emily
        [ask_emily] shortcode
        POST /wp-json/edis/v1/ask → Emily Prime /chat
        Ask Emily sidebar widget
```

---

## Data Sources

All data comes from FatBaby signalapi. No direct DB access from WordPress.

| Endpoint | Used by | Cache TTL |
|----------|---------|-----------|
| `GET /v1/governance-signals?ticker=&limit=` | edis-signals | 60s |
| `GET /v1/entities/{ticker}` | edis-signals | 300s |
| `GET /v1/eps/{ticker}` | edis-signals | 300s |
| `POST /chat` (Emily Prime :8086) | edis-ask-emily | none |

---

## Why WordPress

- **SEO**: WordPress handles sitemap, canonical URLs, meta tags, OpenGraph out of the box
- **Community**: Comments, user accounts, editorial workflow — no custom build needed
- **Plugins**: WooCommerce for Emily+ subscriptions, Mailchimp for waitlist, Yoast for SEO
- **Separation of concerns**: WordPress = content + community. Plugins = data. Pipeline = intelligence.
- **Speed to market**: Themes, hosting, CDN, caching (WP Super Cache) all exist

---

## Relationship to FatBaby Newssite

The FatBaby newssite (`:8082`) is the **internal ops tool** — raw, fast, used by Emily Prime
and operators. EDIS is the **public product** — polished, SEO-optimized, community-ready.

Both read from the same signalapi. Neither shares a codebase.

---

## What "Done" Looks Like

- WordPress install with all three plugins active
- Ticker pages at `/ticker/AAPL` render governance signals from signalapi
- Ask Emily widget appears in sidebar, proxies to Emily Prime
- Email waitlist collected via WooCommerce/Mailchimp or edis-ask-emily plugin
- Google-indexable, OpenGraph-tagged, RSS-fed
- Emily+ subscription gate via IDUNA (S23-05, deferred)

# EDIS Digital Immune System (DIS)

*Written: 2026-06-12*

---

## What It Is

The Digital Immune System is a zero-cost ops posturing layer that wraps the EDIS WordPress site.
It is **not analytics** — it is a survival layer that makes health-state-aware decisions about
ad rendering and request handling without ever blocking availability.

Core axioms:
1. **Fail open always.** Telemetry failure must never affect site availability.
2. **Local-first.** No external calls, no cloud analytics. All state lives on the server.
3. **No blocking.** Harvester never waits; ring buffer overwrites oldest on full.
4. **Drop not crash.** Under sustained attack the site continues serving; it just sheds ads.

---

## Components

```
nginx access log ──▶ dis collector (Go daemon :9099)
                          │
                    ring buffer (16k slots)
                    posture engine
                          │
                    /dis/health   ── WordPress edis-dis plugin
                    /dis/admode         │
                    /dis/posture        ▼
                                  ad mode selector
                                  [edis_dis_ad] shortcode
```

### 1. Ring Buffer (`internal/dis/ring.go`)

Fixed-size circular buffer (16k slots, lock-free atomic push).
Each slot holds a `Record`: timestamp, method, path hash, status, bytes, latency, IP prefix,
TLS flag, threat score. Overwrites oldest slot on full — no blocking, zero allocations after warmup.

### 2. Fingerprinter (`internal/dis/fingerprint.go`)

Three signals:
- **Header order hash** (FNV-1a over header names in arrival order) — bots typically send headers in alphabetical order; humans do not.
- **Session seed** (HMAC-SHA256 with daily rotating key) — privacy-safe session tracking without cookies or user IDs.
- **Threat score** (0–100) — weighted sum of fingerprint signals.

Scoring weights from [golden.md spec](../golden.md):
| Signal | Score |
|--------|-------|
| JA3 known-bad fingerprint | +50 |
| Alphabetically-ordered headers | +20 |
| Inter-request delta < 20ms | +30 |
| Only GETs (no POST/PUT) | +10 |

Score ≥ 60 = hostile session.

### 3. Harvester Middleware (`internal/dis/harvester.go`)

Go `http.Handler` wrapper. Captures all request telemetry and pushes to the ring buffer.
Designed to wrap any Go HTTP server (FatBaby signalapi, Emily Prime, etc.).
Panic-safe: a deferred `recover()` means a telemetry panic never kills the request.

### 4. Posture Engine (`internal/dis/posture.go`)

Reads rolling metrics over 30-second windows. Four health states:

| State | Hostile ratio | Effect |
|-------|--------------|--------|
| `healthy` | < 20% | Full operation |
| `elevated` | 20–50% | Text-only ads; scanner requests throttled |
| `attack` | > 50% | PoW/CAPTCHA gate; most requests throttled |
| `degraded` | CPU/queue pressure | All ads shed; POST requests blocked |

Decisions: Allow / Throttle (429) / Challenge (403 + CAPTCHA) / Block (403).

### 5. Ad Engine (`internal/dis/adengine.go`)

Maps health state to ad mode in < 1ms. No external calls.

| Health state | Ad mode | Render |
|-------------|---------|--------|
| healthy | `svg` | Full image/video ad |
| elevated | `text` | Text link only |
| attack | `pow_captcha` | PoW challenge gate |
| degraded | `none` | No ad rendered |

The ad slot is a **pressure valve**: when under attack, eliminating ad asset loads (CDN calls, scripts)
reduces bandwidth and external dependencies — monetization doubles as load shedding.

### 6. DIS Collector (`cmd/dis/main.go`)

Standalone Go daemon that tails the nginx access log, pushes records into the ring buffer,
maintains posture state, and exposes three endpoints on `127.0.0.1:9099`:

- `GET /dis/health` → JSON: `{state, ad_mode, updated}`
- `GET /dis/posture` → JSON: detailed metrics
- `GET /dis/admode` → plain text: `svg | text | pow_captcha | none`

### 7. WordPress Plugin (`plugins/edis-dis/edis-dis.php`)

Reads health state from the DIS collector (cached 10s via WP transients, fail-open to `svg`).
Provides:
- `edis_dis_health_state()` — current health state string
- `edis_dis_ad_mode()` — current ad mode string
- `[edis_dis_ad]` shortcode — renders ad appropriate to current state
- Admin panel at Settings → EDIS DIS showing live posture + configuration

---

## Deployment (for WordPress site)

### Step 1 — Build and install the DIS collector

```bash
cd /home/fatbaby/EDIS
go build -o /usr/local/bin/edis-dis ./cmd/dis
systemctl enable --now edis-dis
```

### Step 2 — Install the edis-dis WordPress plugin

```bash
# Already handled by emily install --edis
wp plugin activate edis-dis --path=/var/www/edis --allow-root
```

### Step 3 — Add ad shortcode to theme or posts

```php
// In a widget or page:
[edis_dis_ad slot="sidebar" href="https://example.com" text="Emily+ — Ask unlimited questions"]
```

---

## What to build first (for the WordPress site)

Priority order for the EDIS deployment:

1. **Deploy and validate WordPress + edis-core/signals/ask-emily** (S23-01) — the data layer must work before DIS makes any difference.
2. **Build and run `edis-dis` collector** — once nginx logs are flowing, the DIS starts learning traffic patterns immediately.
3. **Activate `edis-dis` plugin** — flip the ad mode selector on.
4. **Replace any hardcoded ad slots with `[edis_dis_ad]` shortcodes** — this is the only user-visible change.
5. **Wire `ForceState` to an admin button** — gives operator manual override during incidents.
6. **Add nginx `limit_req_zone`** for `/wp-json/` (already in the install's generated vhost) — DIS + nginx rate limits are complementary.

The JA3 fingerprinting and inter-request delta scoring require access to TLS session data
(JA3) and per-IP state (deltas). These are best added in a second pass via:
- OpenResty/lua-nginx-module for JA3 extraction at the nginx layer
- A per-IP session map in the DIS collector (small, bounded memory)

---

## Privacy

The DIS stores:
- IP prefix (first 3 octets only — never the full IP)
- Path hash (FNV-1a — the original path is not stored)
- Session seed (HMAC — cannot be reversed to identify the user)
- No body, no cookies, no user IDs, no PII

Raw records are held for 24 hours maximum (ring buffer wraps). Aggregates are kept for 30 days.

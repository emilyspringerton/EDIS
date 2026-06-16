# EDIS Changelog

## 2026-06-16
- S35-03: per-IP /24 delta scoring in DIS log tailer (ipTracker, applyDeltaScore, 5 new tests)
- feat(dis): S35-01 ForceState endpoint (POST /dis/force, Bearer auth, admin panel button) + S35-02 lazy edis_dis_collector_url() replacing constant-at-boot
- fix(dis): rewrite parseNginxCombined to locate $request by its surrounding quotes instead of fixed space-index split; the old approach shifted all field indices when nginx logged "-" for malformed requests (no internal spaces), silently dropping all 400-class lines — exactly the scanner/bot traffic DIS most needs to score
- fix(dis): swap hostileN before totalN in Posture.recompute() so goroutines racing the window boundary inflate the denominator rather than the numerator, keeping hostile_ratio conservative
- feat(dis): add hostile_ratio to /dis/health JSON response; show hostile_ratio in WordPress admin posture panel
- test(dis): add TestParseNginxCombined (8 cases incl. bad-request dash), TestScoreFromLogTail, TestPostureIngest_HostileRatio, TestPostureRecompute_StateEscalation, TestPostureRecompute_SwapOrder, TestPostureForceState, TestHealthStateString [task-1781616390102456285]
- S30-04: /signals/ nginx proxy block in edis.conf → iduna.farthq.com/signals/ routes to signalapi :9091 with 60s GET cache

- fix(ops): nginx/edis.conf — php8.1-fpm.sock corrected to php8.3-fpm.sock (matches install.sh PHP_VER=8.3)
- feat(ops): ops/dis.service — systemd unit for edis-dis collector daemon; ExecStart with nginx log path + :9099 addr
- feat(ops): ops/sprint-deploy.sh — S23-01 full-stack deploy runner; phases: WordPress+EDIS → DIS daemon → certbot SSL → HTTPS URL migration → git sync wiring → smoke tests
- feat(ops): ops/edis-sudoers — NOPASSWD sudoers fragment for fatbaby to run deploy.sh; installed by sprint-deploy.sh
- feat(ops): .git/hooks/post-merge — auto-runs deploy.sh --plugins-only after git pull

## 2026-06-15

- ops: domain migrated to iduna.farthq.com; nginx adds /api/ proxy to IDUNA :8080 + CORS + JWKS; EDIS_IDUNA_BASE_URL constant added to install.sh + docs; certbot updated for new domain


## 2026-06-14
- feat(mailchimp): mailchimp-waitlist.php — replaces wp_options waitlist with Mailchimp API v3 PUT upsert; auto-detects data center from API key suffix; tag support; falls back to wp_options when not configured; admin settings fields for API key + list ID + tag; override registration at rest_api_init priority 20
- feat(emily-plus): emily-plus-woocommerce.php — WooCommerce hook provisions cap.query.full in IDUNA on order completion; POST /wp-json/edis/v1/set-iduna-user stores IDUNA user_id in WC session + WP usermeta; order meta ties buyer to IDUNA user; EDIS-WOOCOMMERCE agent authenticates with subscriptions.admin; JWT transient cache (50 min); graceful fallback by email if session not set

## 2026-06-13
- feat(ops): ops/install.sh — single-shot idempotent deploy script; installs nginx+PHP8.3-FPM+certbot+WP-CLI, creates MySQL edis DB, downloads+installs WordPress, rsyncs EDIS plugins+theme, configures nginx HTTP bootstrap (ACME-ready), starts services with smoke-test; creds persisted to /root/.edis-deploy-creds; follow-up with ops/certbot/setup.sh once DNS propagates


- feat(edis-earnings): earnings calendar plugin — [edis_earnings_calendar] shortcode + EDIS_Earnings_Widget; calls signalapi /v1/earnings-calendar with ticker/days/limit attrs; calendar-table.php template groups by report_date with today/tomorrow labels, BMO/AMC timing, confidence badges (confirmed/announced/approx); edis-earnings.css
- feat(edis-signals): press releases — [edis_press_releases ticker="AAPL" limit="10"] shortcode; calls signalapi /v1/press-releases/{ticker}; press-releases-list.php renders date, linked title (first line of body), 240-char snippet; edis-signals.css added
- feat(ticker-page): Corporate Signals section — limit raised to 15, ordered by source_published_at DESC (original source document date) via updated /v1/governance-signals endpoint; Press Releases section added above Ask Emily
- feat(ops): nginx SSL config for edis.fatbaby.io on port 443 — ops/nginx/edis.conf (HTTP→HTTPS redirect, PHP-FPM, WP admin rate limit, DIS passthrough, uploads PHP block, HSTS); ops/certbot/setup.sh automated Let's Encrypt cert provisioning via certbot --nginx; ops/deploy.sh rsync deploy + WP-CLI plugin activation
- chore(edis-core): get_press_releases(ticker, limit) API client method; edis_get_press_releases() cached accessor (120s TTL)
- fix(dis-collector): parseNginxCombined read wrong field index for HTTP status (parts[6]=path, not status) — collector was ingesting zero records; fixed to read parts[8] tail tokens
- fix(dis-collector): replace time.Now() with parsed nginx log timestamp — inter-request delta scoring was poisoned by batch-read timing
- fix(dis): posture window recompute race — resetAt data race fixed with atomic.Int64; inRecompute CAS guard prevents multiple goroutines from double-firing recompute at window boundaries
- feat(dis): HostileRatio() method on Posture; wired into /dis/posture response (was always 0.0)
- feat(dis-collector): scoreFromLogTail — UA-based scoring for log-tailing path (zgrab, masscan, nuclei, sqlmap, nikto, python-requests etc.)

## 2026-06-12
- init: repo scaffolded — CLAUDE.md, NORTHSTAR.md, three plugins, starter theme, docs
- feat(edis-core): API client, transient cache, admin settings page
- feat(edis-signals): [edis_signals], [edis_entity], [edis_eps] shortcodes + sidebar widget
- feat(edis-ask-emily): Ask Emily shortcode + widget + WP REST proxy to Emily Prime
- feat(theme): edis starter theme — homepage, ticker page, single post
- docs: architecture.md, wordpress-setup.md, plugin-dev-guide.md
- feat(dis): Digital Immune System — go.mod, internal/dis/{ring,fingerprint,harvester,posture,adengine}.go
- feat(dis-collector): cmd/dis/main.go — nginx log-tailing daemon, /dis/health + /dis/posture + /dis/admode
- feat(edis-dis): WordPress plugin — reads collector health, [edis_dis_ad] shortcode, admin posture panel
- docs(dis): digital-immune-system.md — full spec, deployment guide, what-to-build-first
- chore: go.work updated to include EDIS module

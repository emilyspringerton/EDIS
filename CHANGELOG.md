# EDIS Changelog

## 2026-06-13
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

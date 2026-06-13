# DIS Deployment Analysis — What to Build First

*Written: 2026-06-13 | Task: task-7028146271343963862*

---

## 1. Ranked Deployment Order

### #1 — DIS Collector Daemon (`cmd/dis`)

Start this first, before WordPress even goes live. Point it at the nginx access log from day one. The 30-second rolling window begins learning traffic baseline immediately — even from crawlers and pre-launch scanners.

**Why first**: Zero WordPress dependency. Zero risk (it only reads logs and serves a localhost endpoint). The ring buffer costs ~1.5 MB. After 30 seconds you have a baseline. The WordPress plugin fails open to `svg` if the collector is absent, so deploying WordPress before the collector is fine — but starting the collector before WordPress means you don't waste the first-day data window.

```bash
go build -o /usr/local/bin/edis-dis ./cmd/dis
systemctl enable --now edis-dis
```

### #2 — WordPress Plugin `edis-dis`

Activate immediately after WordPress comes up. Wire `[edis_dis_ad]` shortcodes into the theme sidebar and any monetization slots. Set the collector URL in Settings → EDIS DIS (default `http://127.0.0.1:9099` is correct for co-located deployment).

**Why second**: All the intelligence is already live (collector is running). The plugin is purely a consumer with 10s transient cache and 1s HTTP timeout. No blocking path. Fail-open means the worst case on plugin misconfiguration is that ads always render as `svg`.

### #3 — Nginx `limit_req_zone` + Manual ForceState Button

The nginx rate limits (already specced in ops-runbook as `5 req/s/IP, burst 20`) are **complementary** to DIS — DIS makes ad-mode decisions, nginx makes traffic decisions. They should both be live.

Add a one-click admin button to call `ForceState(StateDegraded)` during incidents. The `ForceState` API exists in the Go code; it just needs an authenticated HTTP endpoint on the collector and a button in the admin panel. This is a 30-minute add.

**Why third**: Not blocking launch, but it closes the incident response loop. Without a manual override, the only way to force degraded mode is to kill the collector (which fails open to healthy — the wrong direction under a real attack).

### What Can Wait

- **Harvester middleware** (for signalapi/emily-agent): adds per-request fingerprinting at the Go HTTP layer. Useful for API endpoints. Not needed for WordPress launch — nginx log tailing covers all WordPress traffic.
- **JA3 fingerprinting**: requires OpenResty/lua-nginx-module. Second pass, adds +50 score signal but complex to deploy.
- **Per-IP session state for delta scoring**: requires bounded per-IP map in the collector. Currently the `DeltaMs` scoring in `ScoreRequest` is never populated from the log tailer (no inter-request delta available in a log line). This is a known gap; the posture engine works without it.

---

## 2. Is the Collector Log-Tailing Approach Right?

**Yes, for WordPress+nginx at launch scale.** Reasoning:

- All WordPress traffic already flows through nginx. The log contains exactly the data the DIS needs (IP prefix, method, status, UA, latency).
- No code changes to WordPress, PHP-FPM, or any plugin are required to get telemetry.
- Log rotation is handled by logrotate; the collector detects inode changes and reopens cleanly (see §5 below for the fix applied).
- The ~100ms poll lag is irrelevant against a 30-second posture window.

**Where log-tailing falls short** (second-pass problems):

- Header order is not in nginx combined logs. The fingerprint's `IsAlphabetical` signal only fires via the Harvester middleware (live request path), not the log tailer.
- Per-IP inter-request delta is not available without keeping per-IP state in the collector.
- TLS session data (JA3) requires nginx-side extraction.

These gaps mean the log-tailing path delivers ~30–40% of the theoretical fingerprinting signal. That is sufficient for a posture layer at launch — the UA-based threat scoring in `scoreFromLogTail` catches the most common scanner signatures reliably.

---

## 3. Top 3 Hidden Failure Modes

### FM-1: Log Lines Lost Between EOF and Reopen (FIXED)

**What was wrong**: `tailFile` used `bufio.Scanner` which returns on EOF. After each EOF the function closed the file, slept 500ms, reopened, and called `f.Seek(0, io.SeekEnd)`. Any lines written between the scanner hitting EOF and the new seek were permanently silenced — they'd never enter the ring.

**Impact**: Under sustained attack with high log volume, the scanner could hit EOF, sleep 500ms, then seek past 2000+ lines that arrived during the sleep. The posture engine would see systematically fewer hostile records and might stay in `StateHealthy` during an active attack.

**Fix applied** (`cmd/dis/main.go`): `tailPoll` keeps the file open, polls with 100ms sleep on EOF, and only reopens when inode comparison detects rotation. Lines are never skipped.

### FM-2: 30-Second Window Reset Race at High Traffic

**What breaks**: `Posture.recompute()` atomically swaps `totalN` and `hostileN` to zero. A traffic burst that straddles a 30-second window boundary can be split across two reset cycles — appearing as 25% hostile in each window when the combined burst was 50%+. The posture engine stays in `StateElevated` instead of escalating to `StateAttack`.

**Mitigation for launch**: The 30s window is generous. A real attack sustained for even 60 seconds will eventually tip both windows. This is an acceptable trade-off pre-launch. Fix in second pass: use a sliding window rather than a hard-reset tumbling window.

### FM-3: `EDIS_DIS_COLLECTOR_URL` Constant Freezes at Plugin Boot

**What breaks**: `define('EDIS_DIS_COLLECTOR_URL', get_option(...))` runs when WordPress includes the plugin file — before `plugins_loaded`. In some WordPress configurations (notably with an object-cache drop-in or in WP-CLI context), `get_option()` at top-level include time returns the database default rather than the saved option. An operator who changes the collector URL in the admin panel will see the new value save correctly, but the constant is already defined with the old value for the current request and won't change until page reload.

**Immediate impact**: Minimal (the default `127.0.0.1:9099` is correct for co-located deployment). Risk increases if the site is ever moved to a multi-server setup where the collector runs on a different host.

**Fix**: Replace `define()` with a helper function that reads the option lazily:
```php
function edis_dis_collector_url(): string {
    return get_option('edis_dis_collector_url', 'http://127.0.0.1:9099');
}
```
And replace all `EDIS_DIS_COLLECTOR_URL` references with `edis_dis_collector_url()`. Low priority for single-server launch; required before multi-server.

---

## 4. First 24h Monitoring Checklist

### Pre-launch (before DNS cut)
- [ ] `curl localhost:9099/dis/health` returns `{"state":"healthy","ad_mode":"svg",...}`
- [ ] `curl localhost:9099/dis/posture` returns `hostile_ratio: 0` (no traffic yet)
- [ ] `systemctl status edis-dis` shows active (running)
- [ ] WordPress Admin → EDIS DIS panel shows live posture table (not the "collector not reachable" warning)

### At launch (first 30 minutes)
- [ ] Verify `hostile_ratio` is non-zero after real traffic arrives (proves log tailing is working)
- [ ] Verify `[edis_dis_ad]` shortcode renders `<div class="edis-ad edis-ad--svg">` in page source
- [ ] Watch for PHP `wp_remote_get` errors in WordPress error log (should be zero for localhost)
- [ ] Confirm no parse failures: tail collector stderr for `parseNginxCombined` skip lines

### After first 30 minutes
- [ ] Run `watch -n5 'curl -s localhost:9099/dis/posture'` — verify state stays healthy under normal traffic
- [ ] Test manual ForceState to `elevated`: confirm WordPress admin notice fires and ad slots switch to text
- [ ] Force state back to healthy: confirm ad slots return to svg
- [ ] Check collector RSS memory: should be < 50 MB (`/proc/$(pgrep edis-dis)/status | grep VmRSS`)

### First 24 hours
- [ ] After midnight: verify logrotate didn't kill the tailer (check `systemctl status edis-dis`, verify `/dis/health` still returns valid JSON)
- [ ] Check that hostile_ratio fluctuates naturally (crawlers, scrapers hit >0% but legitimate traffic keeps it below 20%)
- [ ] If ratio climbs above 20% during off-peak: investigate scanner activity, not a DIS bug
- [ ] Verify fail-open: `systemctl stop edis-dis`, confirm WordPress site continues serving all pages normally

---

## 5. The One Design Mistake to Fix Before Deploying

**The `tailFile` log-tailing race was the critical pre-launch defect.** This has been fixed in this commit.

The original code:
```go
// BEFORE (buggy): seeks to EOF on every reopen, loses lines in the gap
f.Seek(0, io.SeekEnd)
tailReader(f, ring, p)   // scanner exits at EOF
f.Close()
time.Sleep(500 * time.Millisecond)
// Any lines written in the 500ms window are skipped by the next Seek(EOF)
```

The fix keeps the file descriptor open and polls at the current offset, only reopening when inode comparison confirms log rotation. No lines are skipped. See `tailPoll()` and `fileRotated()` in `cmd/dis/main.go`.

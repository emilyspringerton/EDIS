package main

import (
	"testing"

	"github.com/emilyspringerton/edis/internal/dis"
)

func TestParseIPPrefix(t *testing.T) {
	cases := []struct {
		ip   string
		want uint32
	}{
		{"1.2.3.4", 1<<16 | 2<<8 | 3},
		{"192.168.10.255", 192<<16 | 168<<8 | 10},
		{"127.0.0.1", 127<<16 | 0<<8 | 0},
		{"::1", 0},       // IPv6 — not supported
		{"not-an-ip", 0}, // garbage
		{"", 0},
	}
	for _, tc := range cases {
		got := parseIPPrefix(tc.ip)
		if got != tc.want {
			t.Errorf("parseIPPrefix(%q) = %d, want %d", tc.ip, got, tc.want)
		}
	}
}

func TestIPTrackerDelta(t *testing.T) {
	tracker := newIPTracker()
	prefix := parseIPPrefix("1.2.3.4")
	const base = int64(1_000_000_000) // 1s in nanoseconds

	// First request: no prior entry → -1
	d := tracker.delta(prefix, base)
	if d != -1 {
		t.Fatalf("first request: want -1, got %d", d)
	}
	// Second request 10ms later: delta = 10
	d = tracker.delta(prefix, base+10_000_000)
	if d != 10 {
		t.Fatalf("10ms later: want 10, got %d", d)
	}
	// Third request 5ms later: delta = 5 → qualifies for +30
	d = tracker.delta(prefix, base+15_000_000)
	if d != 5 {
		t.Fatalf("5ms later: want 5, got %d", d)
	}
	// Different /24 prefix is independent: first request → -1
	other := parseIPPrefix("10.0.0.1")
	d = tracker.delta(other, base+20_000_000)
	if d != -1 {
		t.Fatalf("new prefix first request: want -1, got %d", d)
	}
}

func TestApplyDeltaScore(t *testing.T) {
	tracker := newIPTracker()
	const base = int64(1_000_000_000)

	makeRec := func(ip string, tsNs int64) dis.Record {
		rec, _ := parseNginxCombined(
			ip + ` - - [16/Jun/2026:13:26:30 +0000] "GET / HTTP/1.1" 200 0 "-" "curl/7.x"`,
		)
		rec.TsNs = tsNs // override parsed timestamp with controlled value
		return rec
	}

	// First request from 1.2.3.x → no delta bonus
	rec := makeRec("1.2.3.4", base)
	applyDeltaScore(&rec, tracker)
	baseScore := rec.Score // whatever scoreFromLogTail gave
	if rec.Score != baseScore {
		t.Errorf("first request should not change score")
	}

	// Second request 5ms later (< 20ms) → +30 burst bonus
	rec2 := makeRec("1.2.3.99", base+5_000_000)
	applyDeltaScore(&rec2, tracker)
	want := baseScore + 30
	if want > 100 {
		want = 100
	}
	if rec2.Score != uint8(want) {
		t.Errorf("burst request: score=%d want %d", rec2.Score, want)
	}

	// Third request 500ms later (≥ 20ms) → no burst bonus
	rec3 := makeRec("1.2.3.1", base+505_000_000)
	applyDeltaScore(&rec3, tracker)
	if rec3.Score != baseScore {
		t.Errorf("slow request: score=%d want %d (no burst bonus)", rec3.Score, baseScore)
	}
}

func TestDeltaScoreBurstRaisesHostileRatio(t *testing.T) {
	ring := &dis.Ring{}
	posture := dis.NewPosture()
	tracker := newIPTracker()

	const base = int64(1_000_000_000)
	// Synthesise 20 burst requests from 10.20.30.x, each 5ms apart.
	// Without delta scoring these are borderline (curl UA = 30); with delta
	// scoring each scores 60 (=hostile threshold), so hostile_ratio should rise.
	for i := 0; i < 20; i++ {
		line := `10.20.30.5 - - [16/Jun/2026:13:26:30 +0000] "GET /wp-login.php HTTP/1.1" 200 0 "-" "curl/7.x"`
		rec, ok := parseNginxCombined(line)
		if !ok {
			t.Fatalf("parse failed on iteration %d", i)
		}
		rec.TsNs = base + int64(i)*5_000_000 // 5ms apart
		applyDeltaScore(&rec, tracker)
		ring.Push(rec)
		posture.IngestRaw(rec)
	}

	ratio := posture.HostileRatio()
	if ratio <= 0 {
		t.Errorf("hostile_ratio=%.4f after burst; expected >0 (delta scoring not raising scores)", ratio)
	}
}

func TestParseNginxCombinedIPPrefix(t *testing.T) {
	line := `1.2.3.4 - - [16/Jun/2026:13:26:30 +0000] "GET / HTTP/1.1" 200 0 "-" "-"`
	rec, ok := parseNginxCombined(line)
	if !ok {
		t.Fatal("parse failed")
	}
	want := parseIPPrefix("1.2.3.4")
	if rec.IPPrefix != want {
		t.Errorf("IPPrefix=%d want %d", rec.IPPrefix, want)
	}
}

func TestParseNginxCombined(t *testing.T) {
	cases := []struct {
		name   string
		line   string
		ok     bool
		status uint16
		method uint8
		bytes  uint32
	}{
		{
			name:   "normal GET",
			line:   `127.0.0.1 - - [16/Jun/2026:13:26:30 +0000] "GET /wp-login.php HTTP/1.1" 200 1234 "-" "Mozilla/5.0"`,
			ok:     true,
			status: 200,
			method: 0,    // GET
			bytes:  1234,
		},
		{
			// Bad/malformed request: nginx logs "-" for $request.
			// Previously caused a field-index shift that dropped ALL 400 lines.
			name:   "bad request dash",
			line:   `127.0.0.1 - - [16/Jun/2026:13:26:30 +0000] "-" 400 0 "-" "-"`,
			ok:     true,
			status: 400,
			method: 5, // OTHER — "-" doesn't match any method
			bytes:  0,
		},
		{
			name:   "POST request with auth user",
			line:   `10.0.0.1 - frank [16/Jun/2026:13:26:30 +0000] "POST /wp-admin/admin-ajax.php HTTP/1.1" 200 42 "https://example.com/" "Mozilla/5.0 (Windows NT 10.0)"`,
			ok:     true,
			status: 200,
			method: 1, // POST
			bytes:  42,
		},
		{
			name:   "HEAD request",
			line:   `192.168.1.1 - - [16/Jun/2026:00:00:00 +0000] "HEAD / HTTP/1.0" 301 0 "-" "-"`,
			ok:     true,
			status: 301,
			method: 4, // HEAD
			bytes:  0,
		},
		{
			name:   "DELETE request",
			line:   `1.2.3.4 - - [16/Jun/2026:00:00:00 +0000] "DELETE /api/v1/resource HTTP/1.1" 204 0 "-" "curl/7.68.0"`,
			ok:     true,
			status: 204,
			method: 3, // DELETE
			bytes:  0,
		},
		{
			name: "too short — not a log line",
			line: `not a log line`,
			ok:   false,
		},
		{
			name: "empty line",
			line: ``,
			ok:   false,
		},
		{
			// 404 with referer containing spaces (UA field is the last one)
			name:   "404 with referer",
			line:   `5.6.7.8 - - [16/Jun/2026:13:26:30 +0000] "GET /missing-page HTTP/1.1" 404 512 "https://ref.example.com/" "python-requests/2.28.1"`,
			ok:     true,
			status: 404,
			method: 0, // GET
			bytes:  512,
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			rec, ok := parseNginxCombined(tc.line)
			if ok != tc.ok {
				t.Fatalf("ok=%v want %v", ok, tc.ok)
			}
			if !ok {
				return
			}
			if rec.Status != tc.status {
				t.Errorf("status=%d want %d", rec.Status, tc.status)
			}
			if rec.Method != tc.method {
				t.Errorf("method=%d want %d", rec.Method, tc.method)
			}
			if rec.RespBytes != tc.bytes {
				t.Errorf("bytes=%d want %d", rec.RespBytes, tc.bytes)
			}
		})
	}
}

func TestScoreFromLogTail(t *testing.T) {
	cases := []struct {
		name     string
		rest     string
		method   uint8
		minScore uint8
	}{
		{
			name:     "zgrab scanner",
			rest:     `"-" "zgrab/0.x"`,
			method:   0,
			minScore: 30,
		},
		{
			name:     "python-requests scanner",
			rest:     `"-" "python-requests/2.28.1"`,
			method:   0,
			minScore: 30,
		},
		{
			name:     "clean browser UA",
			rest:     `"-" "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"`,
			method:   0,
			minScore: 0,
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			score := scoreFromLogTail(tc.rest, tc.method)
			if score < tc.minScore {
				t.Errorf("score=%d want >=%d", score, tc.minScore)
			}
		})
	}
}

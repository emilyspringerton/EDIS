package main

import (
	"testing"
)

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

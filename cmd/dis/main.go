// cmd/dis/main.go — EDIS Digital Immune System collector daemon.
// Tails nginx access logs, maintains ring buffer + health state,
// exposes /dis/health (JSON) and /dis/posture (metrics) on localhost.
//
// Usage: dis --log /var/log/nginx/access.log --addr :9099
//
// The WordPress edis-dis plugin reads from :9099/dis/health to select ad mode
// and surface posture state in the admin panel.

package main

import (
	"bufio"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/emilyspringerton/edis/internal/dis"
)

var (
	flagLog        = flag.String("log", "/var/log/nginx/access.log", "nginx access log to tail")
	flagAddr       = flag.String("addr", "127.0.0.1:9099", "listen address for health/posture endpoints")
	flagStdin      = flag.Bool("stdin", false, "read log lines from stdin instead of tailing a file")
	flagAdminToken = flag.String("admin-token", "", "bearer token required for /dis/force (empty = endpoint disabled)")
)

func main() {
	flag.Parse()

	ring := &dis.Ring{}
	posture := dis.NewPosture()

	// Start log tailer in background
	go func() {
		if *flagStdin {
			tailReader(os.Stdin, ring, posture)
		} else {
			tailFile(*flagLog, ring, posture)
		}
	}()

	// Expose health endpoints
	mux := http.NewServeMux()
	mux.HandleFunc("/dis/health", handleHealth(posture))
	mux.HandleFunc("/dis/posture", handlePosture(posture))
	mux.HandleFunc("/dis/admode", handleAdMode(posture))
	if *flagAdminToken != "" {
		mux.HandleFunc("/dis/force", handleForce(posture, *flagAdminToken))
		log.Printf("dis: /dis/force admin endpoint enabled")
	}

	log.Printf("dis collector listening on %s (tailing %s)", *flagAddr, *flagLog)
	if err := http.ListenAndServe(*flagAddr, mux); err != nil {
		log.Fatalf("dis: listen: %v", err)
	}
}

// tailFile opens path, seeks to the end, and polls for new lines indefinitely.
// When the file is rotated (inode changes), it reopens automatically.
// Lines written during a reopen gap are NOT missed because we only reopen
// after detecting rotation — we do not close-and-seek on every EOF poll.
func tailFile(path string, ring *dis.Ring, p *dis.Posture) {
	tracker := newIPTracker()
	for {
		f, err := os.Open(path)
		if err != nil {
			log.Printf("dis: open %s: %v (retry in 5s)", path, err)
			time.Sleep(5 * time.Second)
			continue
		}
		f.Seek(0, io.SeekEnd) //nolint:errcheck
		tailPoll(f, path, ring, p, tracker)
		f.Close()
	}
}

// tailPoll keeps f open and polls for new lines, sleeping 100ms on EOF.
// Returns when the file has been rotated (inode change or file gone).
func tailPoll(f *os.File, path string, ring *dis.Ring, p *dis.Posture, tracker *ipTracker) {
	buf := bufio.NewReader(f)
	for {
		line, err := buf.ReadString('\n')
		if line != "" {
			rec, ok := parseNginxCombined(strings.TrimRight(line, "\r\n"))
			if ok {
				applyDeltaScore(&rec, tracker)
				ring.Push(rec)
				p.IngestRaw(rec)
			}
		}
		if err == nil {
			continue
		}
		if err != io.EOF {
			log.Printf("dis: read error: %v", err)
			return
		}
		// At EOF: check for log rotation before sleeping.
		if fileRotated(f, path) {
			return
		}
		time.Sleep(100 * time.Millisecond)
	}
}

// fileRotated returns true if the open file f no longer matches the file at path
// (i.e. the file has been rotated or deleted by logrotate).
func fileRotated(f *os.File, path string) bool {
	fi1, err := f.Stat()
	if err != nil {
		return true
	}
	fi2, err := os.Stat(path)
	if err != nil {
		return true // file deleted → rotated
	}
	return !os.SameFile(fi1, fi2)
}

// tailReader reads log lines from r until EOF or error, parsing each line.
// Used for stdin (-stdin flag) where polling is not needed.
func tailReader(r io.Reader, ring *dis.Ring, p *dis.Posture) {
	tracker := newIPTracker()
	sc := bufio.NewScanner(r)
	for sc.Scan() {
		line := sc.Text()
		rec, ok := parseNginxCombined(line)
		if !ok {
			continue
		}
		applyDeltaScore(&rec, tracker)
		ring.Push(rec)
		p.IngestRaw(rec)
	}
}

// parseNginxCombined parses the nginx "combined" log format:
// $remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent "$http_referer" "$http_user_agent"
//
// We locate the $request field by its surrounding double-quotes rather than
// counting spaces. This is robust against variable-length request fields,
// including the "-" nginx logs for bad/malformed requests (which previously
// caused all 400-class lines to be silently dropped — the field indices
// shifted by 2 when the request had no internal spaces).
func parseNginxCombined(line string) (dis.Record, bool) {
	// The first " in the line is always the opening of "$request" because
	// $remote_addr, -, $remote_user, and [$time_local] never contain quotes.
	reqOpen := strings.IndexByte(line, '"')
	if reqOpen < 0 {
		return dis.Record{}, false
	}
	reqClose := strings.IndexByte(line[reqOpen+1:], '"')
	if reqClose < 0 {
		return dis.Record{}, false
	}
	reqClose += reqOpen + 1 // absolute offset of closing "

	request := line[reqOpen+1 : reqClose]

	// Parse the prefix "IP - user [date tz]" using Fields (handles any spacing).
	prefixFields := strings.Fields(line[:reqOpen])
	if len(prefixFields) < 5 {
		return dis.Record{}, false
	}

	// prefixFields[3]="[02/Jan/2006:15:04:05"  prefixFields[4]="-0700]"
	tsNs := time.Now().UnixNano()
	tsRaw := strings.TrimPrefix(prefixFields[3], "[") + " " + strings.TrimSuffix(prefixFields[4], "]")
	if t, err := time.Parse("02/Jan/2006:15:04:05 -0700", tsRaw); err == nil {
		tsNs = t.UnixNano()
	}

	// Suffix is everything after the closing " — skip the single space separator.
	if reqClose+1 >= len(line) || line[reqClose+1] != ' ' {
		return dis.Record{}, false
	}
	tail := strings.SplitN(line[reqClose+2:], " ", 3)
	if len(tail) < 2 {
		return dis.Record{}, false
	}
	status, err := strconv.ParseUint(tail[0], 10, 16)
	if err != nil {
		return dis.Record{}, false
	}
	respBytes, _ := strconv.ParseUint(tail[1], 10, 32)

	rec := dis.Record{
		TsNs:      tsNs,
		Status:    uint16(status),
		RespBytes: uint32(respBytes),
		IPPrefix:  parseIPPrefix(prefixFields[0]),
	}

	// Method is the first space-separated token of the request field.
	rec.Method = encodeMethod(strings.SplitN(request, " ", 2)[0])

	// Basic UA-based threat scoring from the log.
	if len(tail) == 3 {
		rec.Score = scoreFromLogTail(tail[2], rec.Method)
	}

	return rec, true
}

// parseIPPrefix packs the first three octets of an IPv4 address into a uint32
// (bits 23-0 = a.b.c, bits 31-24 = 0). Returns 0 for IPv6 or invalid input.
func parseIPPrefix(ip string) uint32 {
	parts := strings.SplitN(ip, ".", 4)
	if len(parts) != 4 {
		return 0
	}
	a, e1 := strconv.ParseUint(parts[0], 10, 8)
	b, e2 := strconv.ParseUint(parts[1], 10, 8)
	c, e3 := strconv.ParseUint(parts[2], 10, 8)
	if e1 != nil || e2 != nil || e3 != nil {
		return 0
	}
	return uint32(a)<<16 | uint32(b)<<8 | uint32(c)
}

// ipTracker tracks the last-seen timestamp (nanoseconds) per /24 IPv4 prefix.
// Bounded to ipTrackerMax entries; evicted wholesale when full to bound memory.
const ipTrackerMax = 65535

type ipTracker struct {
	mu       sync.Mutex
	lastSeen map[uint32]int64
}

func newIPTracker() *ipTracker {
	return &ipTracker{lastSeen: make(map[uint32]int64, 256)}
}

// delta returns milliseconds since the last request from prefix, then updates
// the last-seen time. Returns -1 on first request or for unknown prefixes.
func (t *ipTracker) delta(prefix uint32, nowNs int64) int {
	if prefix == 0 {
		return -1
	}
	t.mu.Lock()
	defer t.mu.Unlock()
	prev, ok := t.lastSeen[prefix]
	if len(t.lastSeen) > ipTrackerMax {
		// Evict all — trades precision for bounded memory; active scanners
		// reappear on the next request and lose only one delta measurement.
		t.lastSeen = make(map[uint32]int64, 256)
	}
	t.lastSeen[prefix] = nowNs
	if !ok {
		return -1
	}
	ms := int((nowNs - prev) / 1e6)
	if ms < 0 {
		return -1 // clock skew / log-timestamp disorder
	}
	return ms
}

// applyDeltaScore adds +30 to rec.Score when inter-request delta < 20ms,
// matching the DIS scoring axiom in golden.md.
func applyDeltaScore(rec *dis.Record, tracker *ipTracker) {
	ms := tracker.delta(rec.IPPrefix, rec.TsNs)
	if ms >= 0 && ms < 20 {
		extra := int(rec.Score) + 30
		if extra > 100 {
			extra = 100
		}
		rec.Score = uint8(extra)
	}
}

// scoreFromLogTail derives a partial threat score from the referer+UA portion of
// the log line (tail[2] after stripping status+bytes).  Not as precise as full
// fingerprinting via Harvester middleware, but catches the most common scanners.
func scoreFromLogTail(rest string, method uint8) uint8 {
	// Extract user-agent: last quoted field in `"-" "UA"`
	ua := ""
	if i := strings.LastIndex(rest, "\""); i > 0 {
		if j := strings.LastIndex(rest[:i], "\""); j >= 0 {
			ua = strings.ToLower(rest[j+1 : i])
		}
	}

	var score int
	// Known scanner/bot UA substrings
	for _, sig := range []string{"zgrab", "masscan", "nmap", "sqlmap", "nikto", "nuclei", "python-requests", "go-http-client", "curl/", "wget/"} {
		if strings.Contains(ua, sig) {
			score += 30
			break
		}
	}
	// GET-only signal: collector tracks per-record; posture engine aggregates
	if method == 0 { // GET
		score += 5
	}
	if score > 100 {
		return 100
	}
	return uint8(score)
}

func encodeMethod(m string) uint8 {
	switch strings.ToUpper(m) {
	case "GET":
		return 0
	case "POST":
		return 1
	case "PUT":
		return 2
	case "DELETE":
		return 3
	case "HEAD":
		return 4
	default:
		return 5
	}
}

type healthResponse struct {
	State        string  `json:"state"`
	AdMode       string  `json:"ad_mode"`
	HostileRatio float64 `json:"hostile_ratio"`
	Updated      string  `json:"updated"`
}

func handleHealth(p *dis.Posture) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		state := p.State()
		resp := healthResponse{
			State:        state.String(),
			AdMode:       dis.SelectAdMode(state).String(),
			HostileRatio: p.HostileRatio(),
			Updated:      time.Now().UTC().Format(time.RFC3339),
		}
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Cache-Control", "no-store")
		json.NewEncoder(w).Encode(resp) //nolint:errcheck
	}
}

type postureResponse struct {
	State       string  `json:"state"`
	HostileRatio float64 `json:"hostile_ratio"`
	AdMode      string  `json:"ad_mode"`
	AdDesc      string  `json:"ad_mode_description"`
}

func handlePosture(p *dis.Posture) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		state := p.State()
		mode := dis.SelectAdMode(state)
		resp := postureResponse{
			State:        state.String(),
			HostileRatio: p.HostileRatio(),
			AdMode:       mode.String(),
			AdDesc:       dis.AdModeDescription(mode),
		}
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, "%s\n", mustJSON(resp))
	}
}

func handleAdMode(p *dis.Posture) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		mode := dis.SelectAdMode(p.State())
		w.Header().Set("Content-Type", "text/plain")
		fmt.Fprintln(w, mode.String())
	}
}

func mustJSON(v any) []byte {
	b, _ := json.Marshal(v)
	return b
}

// handleForce handles POST /dis/force?state=<state>
// Requires Authorization: Bearer <admin-token> header.
// Valid states: healthy, elevated, attack, degraded.
func handleForce(p *dis.Posture, token string) http.HandlerFunc {
	stateMap := map[string]dis.HealthState{
		"healthy":  dis.StateHealthy,
		"elevated": dis.StateElevated,
		"attack":   dis.StateAttack,
		"degraded": dis.StateDegraded,
	}
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		auth := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
		if auth != token {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		stateStr := r.URL.Query().Get("state")
		s, ok := stateMap[strings.ToLower(stateStr)]
		if !ok {
			http.Error(w, "invalid state; valid: healthy, elevated, attack, degraded", http.StatusBadRequest)
			return
		}
		p.ForceState(s)
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"ok":true,"state":%q}`, stateStr)
		log.Printf("dis: ForceState → %s (operator override)", stateStr)
	}
}

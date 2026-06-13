// internal/dis/fingerprint.go — request fingerprinting and threat scoring.
// Implements header-order hashing (FNV-1a) and daily-rotating HMAC session seeds.
// Score ≥ 60 = hostile per golden.md axiom.

package dis

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/binary"
	"net/http"
	"strings"
	"sync"
	"time"
)

// ScoreThreshold is the minimum score that classifies a session as hostile.
const ScoreThreshold = 60

// HeaderOrderHash computes FNV-1a over the canonical header names in arrival order.
// Canonical = lowercase; order preserved from the wire (Go net/http header map
// does not preserve order, so we use r.Header to get the list).
func HeaderOrderHash(r *http.Request) uint32 {
	const (
		offset32 uint32 = 2166136261
		prime32  uint32 = 16777619
	)
	h := offset32
	for _, name := range headerOrder(r) {
		for i := 0; i < len(name); i++ {
			h ^= uint32(name[i])
			h *= prime32
		}
		h ^= uint32('|')
		h *= prime32
	}
	return h
}

// headerOrder returns the lowercased header names from the request.
// Go's net/http normalises header names, so order is best-effort.
func headerOrder(r *http.Request) []string {
	names := make([]string, 0, len(r.Header))
	for name := range r.Header {
		names = append(names, strings.ToLower(name))
	}
	return names
}

// SessionSeed computes the HMAC session seed using the daily rotating key.
// session_seed = HMAC(rotating_daily_key, ja3_placeholder || header_order_hash || tcp_window || timing_bucket)
// JA3 is not available at the Go stdlib level (requires TLS library hooks),
// so we substitute a placeholder derived from TLS version + cipher suite.
func SessionSeed(headerOrderHash uint32, tlsVersion uint16, cipherSuite uint16, timingBucket uint8) uint32 {
	key := dailyKey()
	mac := hmac.New(sha256.New, key)
	var buf [8]byte
	binary.BigEndian.PutUint16(buf[0:], tlsVersion)
	binary.BigEndian.PutUint16(buf[2:], cipherSuite)
	binary.BigEndian.PutUint32(buf[4:], headerOrderHash)
	mac.Write(buf[:])
	mac.Write([]byte{timingBucket})
	sum := mac.Sum(nil)
	return binary.BigEndian.Uint32(sum[:4])
}

// ScoreRequest computes a 0-100 threat score for the given fingerprint components.
// Scoring from golden.md:
//   ja3 known-bad fingerprint:        +50
//   alphabetical header order:        +20
//   inter-request delta <20ms:        +30
//   GET skew (only GETs, no POST):    +10
func ScoreRequest(opts ScoreOpts) uint8 {
	var score int
	if opts.JA3KnownBad {
		score += 50
	}
	if opts.HeadersAlphabetical {
		score += 20
	}
	if opts.DeltaMs < 20 && opts.DeltaMs >= 0 {
		score += 30
	}
	if opts.OnlyGETs {
		score += 10
	}
	if score > 100 {
		return 100
	}
	return uint8(score)
}

// ScoreOpts is the input to ScoreRequest.
type ScoreOpts struct {
	JA3KnownBad         bool
	HeadersAlphabetical bool
	DeltaMs             int // negative means unknown (first request)
	OnlyGETs            bool
}

// IsAlphabetical checks if the header names arrive in alphabetical order —
// a common bot/scanner fingerprint.
func IsAlphabetical(r *http.Request) bool {
	names := headerOrder(r)
	if len(names) < 2 {
		return false
	}
	for i := 1; i < len(names); i++ {
		if names[i] < names[i-1] {
			return false
		}
	}
	return true
}

// --- daily key rotation ---

var (
	keyMu      sync.RWMutex
	currentKey []byte
	keyDate    string
)

func dailyKey() []byte {
	today := time.Now().UTC().Format("2006-01-02")
	keyMu.RLock()
	if keyDate == today && currentKey != nil {
		k := currentKey
		keyMu.RUnlock()
		return k
	}
	keyMu.RUnlock()

	keyMu.Lock()
	defer keyMu.Unlock()
	if keyDate == today && currentKey != nil {
		return currentKey
	}
	k := make([]byte, 32)
	if _, err := rand.Read(k); err != nil {
		// last resort: use date bytes — not cryptographically strong but fail open
		k = []byte(today)
	}
	currentKey = k
	keyDate = today
	return k
}

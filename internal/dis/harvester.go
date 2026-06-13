// internal/dis/harvester.go — Go HTTP middleware.
// Wraps any http.Handler to capture telemetry into a Ring without blocking.
// Axiom: telemetry failure MUST NOT affect availability (fail open always).

package dis

import (
	"hash/fnv"
	"net"
	"net/http"
	"strings"
	"time"
)

// Harvester wraps an http.Handler and records a Record per request.
type Harvester struct {
	next    http.Handler
	ring    *Ring
	posture *Posture
}

// NewHarvester creates a middleware that feeds r into posture.
func NewHarvester(next http.Handler, ring *Ring, p *Posture) *Harvester {
	return &Harvester{next: next, ring: ring, posture: p}
}

func (h *Harvester) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	start := time.Now()
	rw := &captureWriter{ResponseWriter: w, status: 200}

	h.next.ServeHTTP(rw, r)

	elapsed := time.Since(start)

	// Build record — defer any panic so telemetry never kills the request.
	defer func() { recover() }() //nolint:errcheck

	rec := Record{
		TsNs:      start.UnixNano(),
		Method:    encodeMethod(r.Method),
		PathHash:  pathHash(r.URL.Path),
		Status:    uint16(rw.status),
		ReqBytes:  uint32(r.ContentLength),
		RespBytes: uint32(rw.written),
		LatencyUs: uint32(elapsed.Microseconds()),
		IPPrefix:  clientIPPrefix(r),
		TLS:       r.TLS != nil,
		HTTP2:     r.ProtoMajor == 2,
	}

	// Fingerprint
	if r.TLS != nil {
		rec.SessionHash = SessionSeed(
			HeaderOrderHash(r),
			r.TLS.Version,
			r.TLS.CipherSuite,
			timingBucket(elapsed),
		)
	} else {
		rec.SessionHash = HeaderOrderHash(r)
	}

	rec.Score = h.posture.scoreRecord(r)
	h.ring.Push(rec)
	h.posture.ingest(rec)
}

// captureWriter wraps ResponseWriter to capture status code and bytes written.
type captureWriter struct {
	http.ResponseWriter
	status  int
	written int
}

func (cw *captureWriter) WriteHeader(code int) {
	cw.status = code
	cw.ResponseWriter.WriteHeader(code)
}

func (cw *captureWriter) Write(b []byte) (int, error) {
	n, err := cw.ResponseWriter.Write(b)
	cw.written += n
	return n, err
}

func encodeMethod(m string) uint8 {
	switch m {
	case http.MethodGet:
		return 0
	case http.MethodPost:
		return 1
	case http.MethodPut:
		return 2
	case http.MethodDelete:
		return 3
	case http.MethodHead:
		return 4
	default:
		return 5
	}
}

func pathHash(path string) uint32 {
	h := fnv.New32a()
	h.Write([]byte(path))
	return h.Sum32()
}

func clientIPPrefix(r *http.Request) uint32 {
	ip := realIP(r)
	p := net.ParseIP(ip)
	if p == nil {
		return 0
	}
	p4 := p.To4()
	if p4 == nil {
		return 0
	}
	return uint32(p4[0])<<16 | uint32(p4[1])<<8 | uint32(p4[2])
}

func realIP(r *http.Request) string {
	if xff := r.Header.Get("X-Forwarded-For"); xff != "" {
		parts := strings.SplitN(xff, ",", 2)
		return strings.TrimSpace(parts[0])
	}
	host, _, _ := net.SplitHostPort(r.RemoteAddr)
	return host
}

func timingBucket(d time.Duration) uint8 {
	ms := d.Milliseconds()
	switch {
	case ms < 20:
		return 0
	case ms < 100:
		return 1
	case ms < 500:
		return 2
	default:
		return 3
	}
}

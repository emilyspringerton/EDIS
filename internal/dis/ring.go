// internal/dis/ring.go — lock-free overwrite ring buffer for HTTP telemetry.
// Sized to ~1 second of sustained traffic at 10k RPS (16k slots).
// Push never blocks and never allocates after warmup.

package dis

import (
	"sync/atomic"
)

const RingSize = 1 << 14 // 16384 slots

// Record is the minimal "soul of request" — no body, no cookies, no user IDs.
type Record struct {
	TsNs        int64  // unix nanoseconds
	Method      uint8  // 0=GET 1=POST 2=PUT 3=DELETE 4=HEAD 5=OTHER
	PathHash    uint32 // FNV-1a of path (not stored)
	Status      uint16
	ReqBytes    uint32
	RespBytes   uint32
	LatencyUs   uint32
	IPPrefix    uint32 // first 3 octets of client IP packed into 24 bits
	TLS         bool
	HTTP2       bool
	Score       uint8  // threat score 0-100 from Fingerprint
	SessionHash uint32 // low 32 bits of HMAC session seed
}

// Ring is a fixed-size circular buffer. Head is the next write index.
// On overflow it wraps and overwrites oldest entries (no blocking).
type Ring struct {
	slots [RingSize]Record
	head  atomic.Uint64
}

// Push writes r into the ring, overwriting the oldest slot on full.
func (rb *Ring) Push(r Record) {
	idx := rb.head.Add(1) - 1
	rb.slots[idx&(RingSize-1)] = r
}

// Snapshot copies up to n recent records into dst (newest first).
// Returns the number of records copied.
func (rb *Ring) Snapshot(dst []Record) int {
	head := rb.head.Load()
	n := uint64(len(dst))
	if head < n {
		n = head
	}
	for i := uint64(0); i < n; i++ {
		dst[i] = rb.slots[(head-1-i)&(RingSize-1)]
	}
	return int(n)
}

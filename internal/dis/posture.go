// internal/dis/posture.go — ops posturing layer.
// Reads rolling metrics from the Ring, maintains a HealthState,
// and exposes Allow/Throttle/Challenge/Block decisions.
// Axioms: fail open always; local-first; no external calls.

package dis

import (
	"net/http"
	"sync"
	"sync/atomic"
	"time"
)

// HealthState is the four-level posture signal.
type HealthState uint8

const (
	StateHealthy  HealthState = iota // normal ops
	StateElevated                    // elevated load or mild threat signal
	StateAttack                      // active attack pattern detected
	StateDegraded                    // resource exhaustion; shed all non-essential load
)

func (s HealthState) String() string {
	switch s {
	case StateHealthy:
		return "healthy"
	case StateElevated:
		return "elevated"
	case StateAttack:
		return "attack"
	case StateDegraded:
		return "degraded"
	default:
		return "unknown"
	}
}

// Decision is what the posture layer recommends for a given request.
type Decision uint8

const (
	DecisionAllow     Decision = iota
	DecisionThrottle           // 429 with Retry-After
	DecisionChallenge          // 403 + CAPTCHA redirect
	DecisionBlock              // 403 hard drop
)

// Posture maintains rolling health state from ingested records.
type Posture struct {
	mu          sync.RWMutex
	state       atomic.Uint32 // HealthState stored as uint32 for atomic load
	hostileN    atomic.Int64  // count of hostile requests in rolling window
	totalN      atomic.Int64  // total requests in rolling window
	resetAtNs   atomic.Int64  // unix nanoseconds; replaces time.Time to avoid data race
	inRecompute atomic.Bool   // CAS guard: only one goroutine recomputes per window
}

var windowDuration = 30 * time.Second

// NewPosture returns a Posture ready to ingest records.
func NewPosture() *Posture {
	p := &Posture{}
	p.state.Store(uint32(StateHealthy))
	p.resetAtNs.Store(time.Now().Add(windowDuration).UnixNano())
	return p
}

// HostileRatio returns the fraction of hostile requests in the current window [0.0, 1.0].
func (p *Posture) HostileRatio() float64 {
	total := p.totalN.Load()
	if total == 0 {
		return 0
	}
	return float64(p.hostileN.Load()) / float64(total)
}

// State returns the current HealthState.
func (p *Posture) State() HealthState {
	return HealthState(p.state.Load())
}

// Decide returns the recommended action for the given request.
// Fail open: if anything panics, return Allow.
func (p *Posture) Decide(r *http.Request) (d Decision) {
	defer func() {
		if recover() != nil {
			d = DecisionAllow
		}
	}()

	switch p.State() {
	case StateHealthy:
		return DecisionAllow
	case StateElevated:
		// throttle only if the request looks like a scanner
		if IsAlphabetical(r) {
			return DecisionThrottle
		}
		return DecisionAllow
	case StateAttack:
		if IsAlphabetical(r) {
			return DecisionChallenge
		}
		return DecisionThrottle
	case StateDegraded:
		// block everything that isn't a GET
		if r.Method != http.MethodGet {
			return DecisionBlock
		}
		return DecisionThrottle
	default:
		return DecisionAllow
	}
}

// IngestRaw is the exported equivalent of ingest, for use by the collector daemon.
func (p *Posture) IngestRaw(rec Record) { p.ingest(rec) }

// ingest records a new telemetry Record and updates health state.
func (p *Posture) ingest(rec Record) {
	p.totalN.Add(1)
	if rec.Score >= ScoreThreshold {
		p.hostileN.Add(1)
	}
	// CAS guard: exactly one goroutine triggers recompute per window boundary.
	if time.Now().UnixNano() > p.resetAtNs.Load() && p.inRecompute.CompareAndSwap(false, true) {
		p.recompute()
		p.inRecompute.Store(false)
	}
}

// scoreRecord computes a threat score for an incoming request (pre-push).
func (p *Posture) scoreRecord(r *http.Request) uint8 {
	return ScoreRequest(ScoreOpts{
		HeadersAlphabetical: IsAlphabetical(r),
	})
}

// recompute recalculates health state from the rolling window.
func (p *Posture) recompute() {
	p.mu.Lock()
	defer p.mu.Unlock()

	total := p.totalN.Swap(0)
	hostile := p.hostileN.Swap(0)
	p.resetAtNs.Store(time.Now().Add(windowDuration).UnixNano())

	if total == 0 {
		p.state.Store(uint32(StateHealthy))
		return
	}

	ratio := float64(hostile) / float64(total)
	var next HealthState
	switch {
	case ratio > 0.5:
		next = StateAttack
	case ratio > 0.2:
		next = StateElevated
	case total > 5000: // high volume, not hostile — watch resources
		next = StateElevated
	default:
		next = StateHealthy
	}

	// Only degrade if attack sustained beyond one window — handled externally
	// by the collector process via ForceState.
	p.state.Store(uint32(next))
}

// ForceState overrides the health state (used by the collector for CPU/queue signals).
func (p *Posture) ForceState(s HealthState) {
	p.state.Store(uint32(s))
}

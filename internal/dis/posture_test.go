package dis

import (
	"testing"
)

func TestPostureIngest_HostileRatio(t *testing.T) {
	p := NewPosture()

	// Feed 10 records: 4 hostile (score=70), 6 clean (score=0)
	hostile := Record{Score: 70}
	clean := Record{Score: 0}

	for i := 0; i < 4; i++ {
		p.IngestRaw(hostile)
	}
	for i := 0; i < 6; i++ {
		p.IngestRaw(clean)
	}

	ratio := p.HostileRatio()
	if ratio < 0.35 || ratio > 0.45 {
		t.Errorf("HostileRatio()=%.4f, want ~0.40 (4/10)", ratio)
	}
}

func TestPostureRecompute_StateEscalation(t *testing.T) {
	p := NewPosture()

	// Inject 6 hostile out of 10 total → ratio 0.60 → StateAttack.
	// Call recompute() directly (same package) to trigger state update
	// without waiting for the 30s window to expire.
	hostile := Record{Score: 70}
	clean := Record{Score: 0}
	for i := 0; i < 6; i++ {
		p.IngestRaw(hostile)
	}
	for i := 0; i < 4; i++ {
		p.IngestRaw(clean)
	}
	p.recompute()

	if p.State() != StateAttack {
		t.Errorf("State()=%v want StateAttack (ratio=0.60)", p.State())
	}
}

func TestPostureRecompute_SwapOrder(t *testing.T) {
	// Verify that after recompute, the hostile ratio is never > 1.0.
	// If hostileN.Swap happened after totalN.Swap, a racing goroutine could
	// push hostile above total. With the corrected swap order (hostile first),
	// racing goroutines are counted in total but not in hostile → ratio ≤ 1.
	p := NewPosture()

	for i := 0; i < 100; i++ {
		p.IngestRaw(Record{Score: 100}) // all hostile
	}
	p.recompute()

	if p.HostileRatio() > 1.0 {
		t.Errorf("HostileRatio()=%.4f > 1.0 after recompute", p.HostileRatio())
	}
}

func TestPostureForceState(t *testing.T) {
	p := NewPosture()
	p.ForceState(StateDegraded)
	if p.State() != StateDegraded {
		t.Errorf("State()=%v want StateDegraded after ForceState", p.State())
	}
	p.ForceState(StateHealthy)
	if p.State() != StateHealthy {
		t.Errorf("State()=%v want StateHealthy after ForceState(Healthy)", p.State())
	}
}

func TestHealthStateString(t *testing.T) {
	cases := []struct {
		s    HealthState
		want string
	}{
		{StateHealthy, "healthy"},
		{StateElevated, "elevated"},
		{StateAttack, "attack"},
		{StateDegraded, "degraded"},
		{HealthState(99), "unknown"},
	}
	for _, tc := range cases {
		if got := tc.s.String(); got != tc.want {
			t.Errorf("HealthState(%d).String()=%q want %q", tc.s, got, tc.want)
		}
	}
}

// internal/dis/adengine.go — health-state-dependent ad mode selector.
// Maps HealthState to AdMode with <1ms latency, no external calls.
// Ad slot is a pressure valve: monetization = load shedding strategy.

package dis

// AdMode determines how ad slots are rendered under a given health state.
type AdMode uint8

const (
	AdModeSVG       AdMode = iota // healthy: full image/video ad (max revenue)
	AdModeText                    // elevated: text-only ad (less bandwidth)
	AdModePOWCAPTCHA              // attack: proof-of-work + CAPTCHA gate before ad
	AdModeNone                    // degraded: no ads (shed all external asset load)
)

func (m AdMode) String() string {
	switch m {
	case AdModeSVG:
		return "svg"
	case AdModeText:
		return "text"
	case AdModePOWCAPTCHA:
		return "pow_captcha"
	case AdModeNone:
		return "none"
	default:
		return "none"
	}
}

// SelectAdMode returns the AdMode appropriate for the current health state.
func SelectAdMode(s HealthState) AdMode {
	switch s {
	case StateHealthy:
		return AdModeSVG
	case StateElevated:
		return AdModeText
	case StateAttack:
		return AdModePOWCAPTCHA
	case StateDegraded:
		return AdModeNone
	default:
		return AdModeNone // fail safe: never show ads in unknown state
	}
}

// AdModeDescription returns a human-readable explanation for logging/admin UI.
func AdModeDescription(m AdMode) string {
	switch m {
	case AdModeSVG:
		return "Full SVG/image ads — normal operation"
	case AdModeText:
		return "Text-only ads — elevated load, reducing external asset calls"
	case AdModePOWCAPTCHA:
		return "PoW + CAPTCHA gate — active attack pattern detected"
	case AdModeNone:
		return "Ads suppressed — system degraded, shedding all non-essential load"
	default:
		return "Unknown ad mode"
	}
}

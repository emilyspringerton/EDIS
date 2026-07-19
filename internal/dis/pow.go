// internal/dis/pow.go — hashcash-style proof-of-work gate for AdModePOWCAPTCHA.
//
// golden.md Part 3 calls for a "PoW + CAPTCHA gate" in attack mode. A real
// third-party CAPTCHA (reCAPTCHA etc.) would violate the spec's own axioms —
// "no third-party beacons," "no SaaS umbilical cords" — so the gate is pure
// PoW: the browser must find a nonce whose hash (seed || nonce) has at least
// Difficulty leading zero bits before the real ad is served. Cheap for one
// legitimate visitor, expensive to repeat at bot scale.
//
// Stateless by design (no server-side challenge store, matching "no
// persistent identifiers" and the ring buffer's local-first footprint): the
// challenge token embeds its own expiry and an HMAC over (seed, expiry)
// under a secret generated once at collector startup. Verify just recomputes
// the HMAC and checks expiry + proof — no map, no cleanup goroutine, no
// growth under load.
package dis

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/base64"
	"encoding/binary"
	"errors"
	"strconv"
	"time"
)

// DefaultPOWDifficulty is the number of required leading zero bits.
// ~2^16 average hash attempts — a few hundred ms to a couple seconds via
// crypto.subtle in a browser, negligible for one human, real cost at the
// request volumes an attack posture implies.
const DefaultPOWDifficulty = 16

// POWChallengeTTL is how long an issued challenge remains valid.
const POWChallengeTTL = 60 * time.Second

var errBadToken = errors.New("dis: malformed pow token")

// POWIssuer issues and verifies stateless PoW challenges.
type POWIssuer struct {
	secret     [32]byte
	Difficulty uint8
}

// NewPOWIssuer generates a fresh random secret (instance-local; a collector
// restart invalidates outstanding challenges, which is fine — they're only
// ever valid for POWChallengeTTL anyway).
func NewPOWIssuer(difficulty uint8) (*POWIssuer, error) {
	iss := &POWIssuer{Difficulty: difficulty}
	if _, err := rand.Read(iss.secret[:]); err != nil {
		return nil, err
	}
	return iss, nil
}

// Challenge is what's handed to the client.
type Challenge struct {
	Seed       string `json:"seed"`       // hex-encoded random seed the client hashes against
	Token      string `json:"token"`      // opaque, verify passes this back unchanged
	Difficulty uint8  `json:"difficulty"` // required leading zero bits
	ExpiresAt  int64  `json:"expires_at"` // unix seconds
}

// Issue returns a new Challenge. No state is retained server-side.
func (p *POWIssuer) Issue(now time.Time) (Challenge, error) {
	var seed [16]byte
	if _, err := rand.Read(seed[:]); err != nil {
		return Challenge{}, err
	}
	expires := now.Add(POWChallengeTTL).Unix()
	token := p.sign(seed[:], expires)
	return Challenge{
		Seed:       encodeHex(seed[:]),
		Token:      token,
		Difficulty: p.Difficulty,
		ExpiresAt:  expires,
	}, nil
}

// sign builds the opaque token: base64( seed || expiry(8 bytes BE) || hmac ).
func (p *POWIssuer) sign(seed []byte, expires int64) string {
	var expBuf [8]byte
	binary.BigEndian.PutUint64(expBuf[:], uint64(expires))

	mac := hmac.New(sha256.New, p.secret[:])
	mac.Write(seed)
	mac.Write(expBuf[:])
	sum := mac.Sum(nil)

	buf := make([]byte, 0, len(seed)+len(expBuf)+len(sum))
	buf = append(buf, seed...)
	buf = append(buf, expBuf[:]...)
	buf = append(buf, sum...)
	return base64.RawURLEncoding.EncodeToString(buf)
}

// Verify checks the token's authenticity/expiry, then checks that nonce is a
// valid proof-of-work solution for the token's embedded seed.
func (p *POWIssuer) Verify(token, nonce string, now time.Time) (bool, error) {
	raw, err := base64.RawURLEncoding.DecodeString(token)
	if err != nil {
		return false, errBadToken
	}
	// seed(16) + expiry(8) + hmac-sha256(32)
	if len(raw) != 16+8+sha256.Size {
		return false, errBadToken
	}
	seed := raw[:16]
	expBuf := raw[16:24]
	gotMAC := raw[24:]

	expires := int64(binary.BigEndian.Uint64(expBuf))

	mac := hmac.New(sha256.New, p.secret[:])
	mac.Write(seed)
	mac.Write(expBuf)
	wantMAC := mac.Sum(nil)

	if subtle.ConstantTimeCompare(gotMAC, wantMAC) != 1 {
		return false, nil // tampered or issued by a different collector instance
	}
	if now.Unix() > expires {
		return false, nil // expired — client must request a fresh challenge
	}
	return checkProof(encodeHex(seed), nonce, p.Difficulty), nil
}

// checkProof reports whether sha256(seedHex || nonce) has at least
// difficulty leading zero bits.
func checkProof(seedHex, nonce string, difficulty uint8) bool {
	h := sha256.Sum256([]byte(seedHex + nonce))
	return leadingZeroBits(h[:]) >= difficulty
}

func leadingZeroBits(b []byte) uint8 {
	var n uint8
	for _, byte_ := range b {
		if byte_ == 0 {
			n += 8
			continue
		}
		for mask := byte(0x80); mask > 0; mask >>= 1 {
			if byte_&mask != 0 {
				return n
			}
			n++
		}
	}
	return n
}

func encodeHex(b []byte) string {
	const hexDigits = "0123456789abcdef"
	out := make([]byte, len(b)*2)
	for i, c := range b {
		out[i*2] = hexDigits[c>>4]
		out[i*2+1] = hexDigits[c&0x0f]
	}
	return string(out)
}

// findProof is a reference solver used only by tests — the real solving
// happens client-side in JS. Kept here so pow_test.go can generate a valid
// nonce without duplicating the hashing logic.
func findProof(seedHex string, difficulty uint8) string {
	for i := 0; ; i++ {
		nonce := strconv.Itoa(i)
		if checkProof(seedHex, nonce, difficulty) {
			return nonce
		}
	}
}

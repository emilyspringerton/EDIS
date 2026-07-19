package dis

import (
	"testing"
	"time"
)

func TestPOWIssueVerifyRoundTrip(t *testing.T) {
	iss, err := NewPOWIssuer(8) // low difficulty so the test solves fast
	if err != nil {
		t.Fatalf("NewPOWIssuer: %v", err)
	}
	now := time.Now()
	ch, err := iss.Issue(now)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}
	if ch.Difficulty != 8 {
		t.Fatalf("Difficulty = %d, want 8", ch.Difficulty)
	}

	nonce := findProof(ch.Seed, ch.Difficulty)
	ok, err := iss.Verify(ch.Token, nonce, now)
	if err != nil {
		t.Fatalf("Verify: %v", err)
	}
	if !ok {
		t.Fatal("Verify() = false for a genuine proof, want true")
	}
}

func TestPOWVerifyRejectsWrongNonce(t *testing.T) {
	iss, err := NewPOWIssuer(20) // high enough that "0" won't accidentally solve it
	if err != nil {
		t.Fatalf("NewPOWIssuer: %v", err)
	}
	now := time.Now()
	ch, err := iss.Issue(now)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}

	ok, err := iss.Verify(ch.Token, "0", now)
	if err != nil {
		t.Fatalf("Verify: %v", err)
	}
	if ok {
		t.Fatal("Verify() = true for an unsolved nonce, want false")
	}
}

func TestPOWVerifyRejectsExpiredToken(t *testing.T) {
	iss, err := NewPOWIssuer(8)
	if err != nil {
		t.Fatalf("NewPOWIssuer: %v", err)
	}
	issuedAt := time.Now()
	ch, err := iss.Issue(issuedAt)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}
	nonce := findProof(ch.Seed, ch.Difficulty)

	past := issuedAt.Add(POWChallengeTTL + time.Second)
	ok, err := iss.Verify(ch.Token, nonce, past)
	if err != nil {
		t.Fatalf("Verify: %v", err)
	}
	if ok {
		t.Fatal("Verify() = true for an expired token, want false")
	}
}

func TestPOWVerifyRejectsTamperedToken(t *testing.T) {
	iss, err := NewPOWIssuer(8)
	if err != nil {
		t.Fatalf("NewPOWIssuer: %v", err)
	}
	now := time.Now()
	ch, err := iss.Issue(now)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}
	nonce := findProof(ch.Seed, ch.Difficulty)

	tampered := ch.Token[:len(ch.Token)-1] + "x"
	ok, err := iss.Verify(tampered, nonce, now)
	if err == nil && ok {
		t.Fatal("Verify() accepted a tampered token")
	}
}

func TestPOWVerifyRejectsCrossInstanceToken(t *testing.T) {
	iss1, err := NewPOWIssuer(8)
	if err != nil {
		t.Fatalf("NewPOWIssuer: %v", err)
	}
	iss2, err := NewPOWIssuer(8)
	if err != nil {
		t.Fatalf("NewPOWIssuer: %v", err)
	}
	now := time.Now()
	ch, err := iss1.Issue(now)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}
	nonce := findProof(ch.Seed, ch.Difficulty)

	ok, _ := iss2.Verify(ch.Token, nonce, now)
	if ok {
		t.Fatal("Verify() accepted a token signed by a different secret")
	}
}

func TestLeadingZeroBits(t *testing.T) {
	cases := []struct {
		b    []byte
		want uint8
	}{
		{[]byte{0x00, 0x00}, 16},
		{[]byte{0xff}, 0},
		{[]byte{0x0f}, 4},
		{[]byte{0x00, 0x80}, 8},
		{[]byte{0x01}, 7},
	}
	for _, c := range cases {
		if got := leadingZeroBits(c.b); got != c.want {
			t.Errorf("leadingZeroBits(%x) = %d, want %d", c.b, got, c.want)
		}
	}
}

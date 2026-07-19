/**
 * EDIS DIS — client-side proof-of-work solver for the AdModePOWCAPTCHA gate.
 *
 * One legitimate visitor pays this cost once per challenge (a few hundred ms
 * to ~1-2s at the default difficulty). A bot re-running it at attack volume
 * pays it every single time — that asymmetry is the whole defensive value.
 * No third-party CAPTCHA service is involved (see golden.md's own axioms:
 * "no third-party beacons," "no SaaS umbilical cords") — this is pure
 * self-hosted hashcash, verified back against the DIS collector.
 */
(function () {
	'use strict';

	function toHex(buffer) {
		var bytes = new Uint8Array(buffer);
		var hex = '';
		for (var i = 0; i < bytes.length; i++) {
			hex += bytes[i].toString(16).padStart(2, '0');
		}
		return hex;
	}

	// Leading zero *bits* of a hex-encoded digest, matching the Go server's
	// bit-level check exactly (not just whole hex-nibble zeros).
	function leadingZeroBits(hex) {
		var bits = 0;
		for (var i = 0; i < hex.length; i++) {
			var nibble = parseInt(hex[i], 16);
			if (nibble === 0) {
				bits += 4;
				continue;
			}
			// Count leading zero bits within this nibble.
			if (nibble < 8) bits += 1;
			if (nibble < 4) bits += 1;
			if (nibble < 2) bits += 1;
			return bits;
		}
		return bits;
	}

	async function sha256Hex(input) {
		var data = new TextEncoder().encode(input);
		var digest = await crypto.subtle.digest('SHA-256', data);
		return toHex(digest);
	}

	async function solve(seed, difficulty, deadlineMs) {
		var start = performance.now();
		for (var i = 0; ; i++) {
			var nonce = String(i);
			var hex = await sha256Hex(seed + nonce);
			if (leadingZeroBits(hex) >= difficulty) {
				return nonce;
			}
			if (performance.now() - start > deadlineMs) {
				throw new Error('pow solve timeout');
			}
		}
	}

	async function runGate(el) {
		var restUrl = window.edisDisPow && window.edisDisPow.restUrl;
		var nonceHeader = window.edisDisPow && window.edisDisPow.nonce;
		if (!restUrl) return;

		var slot = el.getAttribute('data-slot') || 'default';
		var text = el.getAttribute('data-text') || '';
		var href = el.getAttribute('data-href') || '';

		try {
			var challengeResp = await fetch(restUrl + 'dis-pow-challenge', {
				headers: { 'X-WP-Nonce': nonceHeader },
			});
			if (!challengeResp.ok) throw new Error('challenge fetch failed');
			var challenge = await challengeResp.json();

			var nonce = await solve(challenge.seed, challenge.difficulty, 8000);

			var verifyResp = await fetch(restUrl + 'dis-pow-verify', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonceHeader,
				},
				body: JSON.stringify({
					token: challenge.token,
					nonce: nonce,
					slot: slot,
					text: text,
					href: href,
				}),
			});
			if (!verifyResp.ok) throw new Error('verify failed');
			var result = await verifyResp.json();
			if (result.ok && result.html) {
				el.outerHTML = result.html;
			} else {
				el.remove(); // fail closed for this slot only — never blocks the page
			}
		} catch (e) {
			// Fail closed on the ad slot, never on the page. Attack-mode traffic
			// that can't/won't run the PoW simply doesn't see an ad — correct
			// per golden.md 3.7 ("never block content").
			el.remove();
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var gates = document.querySelectorAll('.edis-ad--challenge');
		gates.forEach(runGate);
	});
})();

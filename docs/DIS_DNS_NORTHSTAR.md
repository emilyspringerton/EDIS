# DIS for DNS — Northstar

*Written: 2026-07-19*

---

## Founder framing, verbatim

"emily's internet native imune system" / "what would an internet native being do for an imune
system dns wise" / "also write a DIS northstar for DNS ops."

---

## 1. Why DNS is a different layer than the rest of DIS

Every other piece of DIS so far — the harvester, the fingerprint engine, the ad-mode selector —
operates *after* a request has already reached this box. DNS is the layer before that: it decides
whether a request reaches us at all, and whether it reaches the *real* us. A DNS-level compromise
(cache poisoning, a hijacked subdomain, a lookalike domain serving a fake login page) doesn't
trip any of the existing DIS posture logic, because none of that traffic ever touches our nginx
access log. DIS's own axioms — "local-first," "no third-party beacons" — don't translate cleanly
here either: DNS is federated by design, we don't run our own root, and we've delegated
authoritative serving to Cloudflare. The honest DIS-native question at this layer isn't "how do
we do this ourselves instead of trusting a third party" — it's "what do we verify, what do we
monitor, and what do we correctly leave to the provider we've already chosen to trust."

## 2. Current state (checked, not assumed)

Live `dig` queries against `okemily.com` and `farthq.com`, 2026-07-19:

| Control | Status |
|---|---|
| Nameservers | Cloudflare (`jocelyn.ns.cloudflare.com` / `nicolas.ns.cloudflare.com`) on both domains |
| DNSSEC | **Not enabled** — empty `DNSKEY` response on both domains |
| CAA records | **None set** — any CA could issue a certificate for either domain without our domain's explicit permission |
| SPF | Present on `okemily.com`: `v=spf1 include:spf.efwd.registrar-servers.com ~all` — softfail (`~all`), not hardfail (`-all`) |
| DMARC | **Not set** — no `TXT` record at `_dmarc.okemily.com` |

Two things worth naming plainly: being on Cloudflare already buys real, free protection at this
layer (their edge absorbs the DNS-level DDoS/anomaly patterns a self-hosted resolver would need
custom tooling to catch — not worth rebuilding). But three cheap, zero-cost, zero-new-infra
controls are sitting unused: DNSSEC, CAA, and DMARC. None of them require new code — they're
Cloudflare dashboard changes, the DNS equivalent of the sudo-queue scripts elsewhere in this
company's ops.

## 3. What an internet-native immune system actually watches for, DNS-wise

Four real threat classes, each with a concrete, buildable response:

**3.1 Cache poisoning / response spoofing** — an attacker tricks a resolver into caching a fake
answer for our domain, redirecting real visitors to attacker infrastructure.
*Defense:* DNSSEC. Cryptographically signs zone records so resolvers can verify authenticity.
Cloudflare supports one-click DNSSEC; this is pure configuration, no code.

**3.2 Rogue certificate issuance** — any CA anywhere issues a valid TLS cert for `okemily.com`
without our knowledge (a misconfigured or compromised CA, not a hack of our own systems), then
uses it to run a convincing man-in-the-middle or phishing site.
*Defense:* CAA records restricting issuance to the CAs we actually use (Let's Encrypt, confirmed
in use across this company's certbot-managed sites). Also pure configuration.

**3.3 Subdomain takeover** — a subdomain's `CNAME` still points at a cloud resource
(a deprovisioned app, an old load balancer, a deleted S3-style bucket) that no longer exists, so
an attacker who claims that same resource on the provider's side inherits the subdomain — no
DNS access required on their end, just noticing the dangling pointer. This company runs a real
number of subdomains across products (`news.okemily.com`, `iduna.farthq.com`, and growing) —
exactly the shape of estate where this risk accumulates quietly as products get deprecated or
migrated and their DNS records outlive them.
*Defense:* this is genuinely DIS-shaped work, not just a dashboard toggle — a periodic watcher
(same idiom as every other `cmd/*-watcher` in this codebase) that enumerates known subdomains via
the Cloudflare API, resolves each one, and flags any `CNAME` target that no longer answers or
belongs to a decommissioned service. Not built yet; scoped as Phase 2 below.

**3.4 Typosquatting / lookalike-domain phishing infrastructure** — someone registers
`okemi1y.com` or `einhorn-industrial.net` and stands up a fake login/signup page ahead of an
actual phishing push. The earliest reliable public signal for this is Certificate Transparency
logs (every publicly-trusted cert issuance is logged there, permanently, by design — free to
query, no API key, the same infrastructure that makes CT itself a defensive tool, not just a
compliance one).
*Defense:* a periodic check against `crt.sh` (or an equivalent CT log search) for certs issued to
domains edit-distance-1 or homoglyph-similar to our real domains. Also genuinely DIS-shaped;
scoped as Phase 2.

## 4. Sequencing

**Phase 0 — zero-cost configuration (do first, no code, no watcher needed):**
1. Enable DNSSEC on `okemily.com` and `farthq.com` via Cloudflare's dashboard.
2. Add CAA records restricting issuance to Let's Encrypt (`letsencrypt.org`) on both domains.
3. Add a DMARC record starting at `p=none` (monitoring only — collects reports without rejecting
   mail, the correct first step before ever moving to `p=quarantine`/`p=reject`) at
   `_dmarc.okemily.com`.
4. Tighten SPF from `~all` to `-all` once DMARC monitoring confirms no legitimate mail source is
   missing from the `include:` list.

All four are dashboard/DNS-record changes — desktop-queue shaped, not engineering work. Should be
queued there once this doc is registered, not built as code.

**Phase 1 — subdomain inventory.** Before a takeover watcher can flag anything, there needs to be
a real, current list of what subdomains exist and what they're supposed to point at. Start by
pulling the actual zone from Cloudflare's API (`GET /zones/:id/dns_records`) rather than
reconstructing it from memory — this company's subdomain count has grown organically across many
sessions and repos, and guessing at the list would defeat the point.

**Phase 2 — the two real watchers**, once Phase 1's inventory exists:
- Subdomain-takeover watcher: periodic resolve-and-check against the Phase 1 inventory.
- Typosquat watcher: periodic CT-log query for lookalike-domain cert issuance.
Both are candidates for the exact `cmd/*-watcher` + systemd-timer pattern already established
across this codebase (`bond-watcher`, `earnings-calendar`, etc.) — same shape, new domain.

## 5. What this explicitly does not do

Does not stand up a self-hosted authoritative DNS resolver, does not attempt to replicate
Cloudflare's own edge-level DDoS protection, does not add a new third-party monitoring SaaS
(would violate DIS's own "no SaaS umbilical cords" axiom). The scope here is specifically the
gap between "what Cloudflare already protects because we're on their nameservers" and "what only
we know to watch for, because it's about our own domains and our own deprecated infrastructure."

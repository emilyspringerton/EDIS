# FatBaby Ad Monetization — Policy + Architecture (2026-09-07)

## Where this comes from

Founder real-time, across several messages this session:

> "lets think about how that data can be used for revenue (TINA articles -> internal ads for
> games or emily+ or emily for business etc) and certain vetted advertisers (we wont share data
> with them just allow them to put an ad with a link) mostly ads to internal ecosystem products
> as they get built out but we arent totally against partnerships (we would take a redbull
> sponsorship TBH but we still wouldnt share data with them we aknowledge if the ad gets clicked
> data will be collectable to a certain extent but thats the internet my friends)"
>
> "im saying if there is an external advertiser and someone clicks on a link there is data about
> where they came from right?" -> confirmed, then: "yea write it all up"
>
> "the ad can be to the ads like would you like to advertise?" -> "mixed with other stuff as we
> have stuff to advertise"

## The real policy

1. **No user data is ever shared with an advertiser.** Not analytics, not identifiers, not
   behavioral data -- nothing. What we serve is a static creative (text or SVG) plus an outbound
   link. That's the entire integration surface.
2. **We acknowledge, plainly, that a click carries some inherent information anyway** -- a plain
   HTML link sends a `Referer` header by default (the advertiser's own server sees "this click
   came from fatbaby"), and their server naturally sees the visitor's IP/User-Agent like any
   other request. That's not us sharing anything; it's how HTTP navigation works for any site on
   the internet. We don't add tracking pixels, scripts, or tags to make it worse.
3. **We go one step further for external advertisers specifically**: every sponsor link
   (`kind: "sponsor"` in the ad pool) gets `rel="nofollow noreferrer noopener"` — `noreferrer`
   suppresses even that default `Referer` header, so an external advertiser doesn't get "came
   from fatbaby" for free. House ads (our own products) and the "advertise here" mailto stay
   `nofollow`-only; there's no privacy boundary being crossed pointing at our own ecosystem or
   our own inbox.
4. **Internal-ecosystem-first, but not exclusive.** "Mostly ads to internal ecosystem products as
   they get built out but we aren't totally against partnerships" -- a real, vetted external
   sponsor (the founder's own example: "we would take a Redbull sponsorship") is a real,
   supported ad kind, not a hypothetical. Nothing here is built to keep external sponsors out;
   it's built to make sure vetting and the no-data-sharing line are real, not aspirational.
5. **A house ad soliciting more advertisers is real inventory, mixed in the same rotation as
   everything else** -- not a fallback shown only when nothing else exists. "Would you like to
   advertise here?" is one more real entry in the pool alongside GFD, not a last resort.

## Architecture — real, shipped this pass

Built on top of `edis-dis` (EDIS Digital Immune System), the plugin that already existed for a
different reason (health-adaptive ad-mode switching under load/attack) but happened to own the
one real ad-rendering shortcode in this repo, `[edis_dis_ad]`. This pass adds a real **ad pool**
on top of it rather than building a second, separate ad system:

- `edis_dis_default_ad_pool()` — the real, current inventory: a `house` entry for GFD (real,
  live, shipped -- `dis-gfd-subscription` is this exact repo's own precedent for a real
  cross-product integration), and a `meta` entry, the "advertise here" CTA, pointed at a real,
  admin-configurable mailto (`edis_dis_advertise_contact`, new settings field on the existing DIS
  admin page -- no contact-form backend exists for FatBaby yet, and a dead link would be worse
  than an honest mailto).
- Filterable via `apply_filters('edis_dis_ad_pool', $pool, $slot)` — adding a real vetted sponsor,
  or slot-specific inventory, is a filter callback, not a rewrite of this file. No fabricated
  sponsor entry ships here; the pool ships with exactly the two real, current, honest items above.
- `[edis_dis_ad slot="..."]` picks one entry from the pool at random per render (`edis_dis_pick_ad`)
  and renders it through the exact same health-adaptive pipeline that already existed (SVG when
  healthy, text-only when elevated, PoW-gated when under attack, nothing at all when degraded) --
  the ad system doesn't get a separate code path from the load-shedding one, it inherits it.
  Explicit `src`/`text`/`href` attributes still work exactly as before, for a genuine one-off
  placement that should bypass the pool.
- `edis_dis_ad_rel($kind)` — the real `rel` policy from point 3 above, applied uniformly wherever
  an ad link is rendered (SVG, text, and the PoW-verified-then-served text ad).
- Wired live into the theme footer (`themes/edis/footer.php`, `slot="footer"`) — this pass's one
  real placement; more slots (sidebar, in-article) are a template change away, not an
  architecture change.

## Where TINA fits

TINA articles (see `PRRJECT_FATBABY/docs/northstar/tina-engine.md`, and this session's own
`internal/gauntlet` compliance pass) are named by the founder as the real, natural home for this
inventory -- structured, disclosure-forward, high-intent-reader content is exactly where a house
ad for GFD or a vetted sponsor slot earns attention without compromising TINA's own "not advice"
framing. No TINA-specific ad placement is wired yet; the pool above works anywhere
`[edis_dis_ad]` is placed, TINA's own template included, once one exists.

## Explicitly not done — real, honest gaps

- **No analytics exist on the FatBaby site at all** (checked directly: no GA/Plausible/Matomo/
  gtag anywhere in either theme). "We acknowledge clicks are collectible" is currently a
  statement about how the internet works, not something we ourselves measure yet -- there's
  nothing on our side counting ad impressions or click-throughs. A future, honest addition would
  be first-party-only click counting (no third-party pixel, no per-visitor identity), named here
  as a real, separate, not-yet-scoped decision.
- **No real vetted external sponsor exists yet.** The `sponsor` kind and its `rel` policy are
  real and ready; no fabricated placeholder sponsor ships in the default pool.
- **No admin UI for managing the ad pool itself** (only the advertise-contact address is
  settings-page-editable) -- adding/removing entries is a code change (a filter callback) today,
  a real, deliberate v0 scope decision, not an oversight.
- **Only one placement (footer) is wired into the live theme.** Sidebar/in-article placements
  are real, straightforward follow-ups, not built this pass.

## Related

- `plugins/edis-dis/edis-dis.php` — the actual implementation.
- `themes/edis/footer.php` — the one real, live placement this pass.
- `PRRJECT_FATBABY/docs/northstar/tina-engine.md` — TINA's own design, the natural home for
  future ad placements.
- `PRRJECT_FATBABY/internal/gauntlet` — the sibling compliance pass (disclaimers, ticker linking,
  SKULDMARK) shipped the same session, on the content side of the same "get FatBaby ops solid"
  push.
- `EMILY/BACKLOG.md` — the section logging this pass's real, completed work.

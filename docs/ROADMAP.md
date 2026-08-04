# Product roadmap

What we are building, in what order, and why. Decisions here are settled
unless revisited explicitly — the point of writing them down is to stop
re-litigating them.

## Positioning

**Self-serve software for tabletop creators running a Kickstarter.** Not a
pledge manager (BackerKit and Gamefound own that, and it is post-campaign).
Not an agency. The wedge is pre-launch decision-making and ad execution,
which the agencies charge thousands for and the platforms do badly.

Tabletop specifically, not "crowdfunding generally", because our opinions
are only true for one shape of project: physical product, £20–80 pledges,
a concentrated and reachable community, pledge values that support paid
acquisition.

## Settled decisions

| Decision | Choice | Consequence |
| --- | --- | --- |
| Niche | Tabletop / board games first | Benchmarks, ad targeting, copy and comparables all assume it |
| Pricing | Everything free for now; premium tier later | Optimise for traction and data, not revenue. Nothing gets gated yet |
| Ad builder scope | Campaign **setup**, not creative generation | We answer Meta's 40 configuration questions; Meta's Advantage+ handles creative enhancement |
| Forecast input | Growth rate, not spend | Ads are one channel. Spend is one way to estimate the rate, not the only one |

## Phase 1 — Stop being an ads-only product

The forecast currently cannot produce a number without planned ad spend, and
roughly 60% of the app assumes Meta. A creator building an audience through
communities or press gets nothing.

- Onboarding profile: campaign experience, stage, **acquisition channels**,
  Meta Ads familiarity, existing audience. Every answer must change what the
  software does or it does not get asked.
- Channel-aware funnel and forecast. Where there is no ad spend, measure the
  weekly growth rate directly and extrapolate to the launch date. Same
  engine, same "what has to happen" panel, different way of estimating the
  input.
- UTM tagging and source attribution on signups, so growth can be split by
  channel rather than assumed to be paid.

## Phase 2 — Value before they spend anything

Day one is currently eight zeros and figures labelled "typical". A
first-timer has no reason to return tomorrow.

- **Comparable campaigns.** Tabletop projects with a similar goal, their
  outcomes, and what their audience looked like at launch. Replaces our
  hardcoded benchmarks with real ones and gives a first session substance.
  Sourced from public scrape datasets — Kickstarter retired its public API.
- **Goal viability check.** Manufacturing, shipping and fees against the
  funding goal: "£25,000 funds 340 units and leaves you £2,000 short."
  Requires no integrations, and it is the question first-timers get wrong.

## Phase 3 — The ad builder

Assets and a budget in; correctly configured campaigns out. See the
[ad builder design](#ad-builder-design) below.

## Phase 4 — Launch week and the live campaign

We cover roughly eight weeks of a twelve-month journey, and the thirty days
that decide the outcome are invisible.

- The 48-hour launch plan: who is emailed, in what order, at what time —
  followers first, VIPs before the general list.
- Live funding velocity against comparables, mid-campaign slump prediction,
  update cadence prompts.

## Phase 5 — Campaign over campaign

The retention answer and the moat: nobody else holds your last campaign's
data. "At 40 days out you had 610 followers; you have 418 now." First-timers
become repeat users because leaving means abandoning their history.

## Later

- **Product types beyond tabletop.** Let creators pick their category and
  adapt backer rates, average pledge, ad interest targeting, comparable
  filtering and copy accordingly. To keep this cheap, tabletop assumptions
  should live behind a single profile object from Phase 1 onward rather than
  being scattered as constants — see `BackerRates`, the default average
  pledge, and the ad interest set.
- Proper Meta OAuth plus App Review, replacing "create your own Meta app",
  which is the worst moment in the product.
- Lookalike audiences once a list clears 100 subscribers.
- Cross-promotion between creators on the platform — a real network effect,
  and standard practice in tabletop already.
- Premium tier. Candidates: campaign-over-campaign history, ad builder
  beyond the first campaign, comparables depth. Nothing gated before we
  have traction.

## Ad builder design

**Problem.** Ads Manager asks a first-time creator around forty questions
they cannot answer. Five of them decide whether a signup costs £0.50 or
£5.00.

**Not the problem.** Creative quality. Meta's Advantage+ already enhances
images and generates text variants, and for a physical product the creator's
own photography is the asset. We do not generate imagery.

### Flow

0. **Preflight.** Token carries `ads_management`; Facebook Page connected;
   pixel exists; ad account has a payment method. Each failure gets a
   plain-English fix and a deep link. Worth shipping alone — this is where
   people give up today.
1. **Assets.** 3–5 images and/or video, validated for resolution and aspect.
   Flag a missing 9:16 asset: Reels and Stories are the cheap inventory.
2. **About the game.** Name, one-liner, players, play time, theme,
   shippable countries.
3. **Which ads.** Defaults to all three, each justified with the creator's
   own numbers from the forecast.
4. **Budget.** Pre-filled from the launch plan's daily spend, final-week
   spike included.
5. **Preview.** Everything that will be created, targeting written in
   English rather than API fields. Copy editable.
6. **Create paused.** Going live is a separate, explicit confirmation that
   states the daily spend.

### Structure created

One campaign, three ad sets — one per destination — and one ad per creative
asset inside each.

- Campaign: `OUTCOME_LEADS`, `special_ad_categories: []`, `PAUSED`
- Instant form ad set: `LEAD_GENERATION`, `destination_type: ON_AD`
- Landing page ad set: `OFFSITE_CONVERSIONS`, promoted object pixel + `LEAD`
- Kickstarter ad set: traffic, upgraded to conversions once the pixel proves
  it fires
- Shared targeting: 25–55, shippable countries, curated tabletop interests,
  Advantage+ audience, placements and creative enhancements all on

**One ad per creative, not dynamic creative.** Dynamic creative delivers
slightly better but hides which asset won. Our analyser works at ad level,
so one ad per asset lets us say "the box shot is at £0.60, the lifestyle
photo at £2.10 — kill the lifestyle photo". No other tool surfaces that.

**We create the Instant Form** on the Page: email only, no extra questions,
because every added field costs signups.

**Text is prefilled, never auto-published.** Meta requires primary text and
a headline; a blank box is worse than a good default. Always editable.

### Safety

We are spending someone else's money.

- Everything created paused, always
- Hard daily budget cap
- Full preview before any write
- Idempotency keys, so a retry cannot double-create
- We never modify objects we did not create
- Every API write logged
- One-click "pause everything"

### The loop this unlocks

We create the ads, we already grade them per ad with verdicts, so the Ads
page can act: "this ad costs £4.20 a signup, above the £0.90 one is worth —
pause it." Measurement to action on one screen, only possible because we
created the objects.

### Explicitly out of scope

Creative generation, bid micro-management, an A/B testing framework, and
lookalike audiences until a list clears 100 records.

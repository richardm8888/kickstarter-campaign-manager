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
- **Budget triage**, asked immediately after the project details and before
  anything else. See [budget triage](#budget-triage) below. "I don't know
  yet" is a first-class answer that routes into the recommendation rather
  than blocking.
- **Reward tiers replace the average pledge field.** Asking for one number
  makes the creator do our job. They enter tier names and prices; we apply
  the tabletop distribution — core tier takes 55–70% of backers, average
  pledge lands 15–35% above the core price once add-ons and upgrades are
  counted — show the working, and let them override. Comparables improve
  the estimate once Phase 2 lands.
- **Meta setup preflight.** With OAuth deferred, "paste a token you
  generated yourself" stays the front door, so make it survivable: check
  the token carries `ads_management`, a Page is connected, a pixel exists
  and the ad account has a payment method, each failure with a plain
  fix and a deep link.
- **Instrument the setup funnel, step by step.** During validation,
  "gave up in the Meta developer console" and "did not want the product"
  look identical in aggregate and mean opposite things. Without
  attributable drop-off we will read a setup problem as a demand problem
  and draw the wrong conclusion about the whole product.
- **Concierge onboarding**, paid, capped at roughly the first 50
  customers. It is the only bridge across the token wall while OAuth is
  deferred, and fifty setup calls are the spec for what to automate
  later. Put the cap in writing — if it is still running at customer 200,
  the product never got easier and the calls became the business.

## The daily list

Built. `/projects/{id}/today`, plus the top item on the dashboard.

The premise: a creator running this alongside a job does not need a
dashboard, they need to know what to do in the next thirty minutes. So the
list answers one question — given everything that has happened, what are
the most valuable things to do today — and every design decision below
protects that.

**Three, hard.** Ranking is the product. A list of eleven gets skimmed,
then ignored, then the tool stops being opened, so everything below third
place is thrown away rather than shown smaller. A quiet day returns
nothing at all: inventing work to fill the list is the fastest way to make
the list worthless.

**Scored, not sorted.** Every detector must state impact, confidence,
effort and urgency, so a ten-minute job with good evidence outranks a
two-hour one with a hunch behind it. Half an hour is the reference job;
longer tasks are not excluded, they have to be worth more.

**Trends, never days.** The shortest window is three days against the
fortnight behind it, and a comparison without enough data reports that
rather than a number. Almost every bad marketing decision starts with
reacting to one bad Tuesday.

**Bottleneck first.** Traffic to signups to followers to backers. The
instinctive response to disappointing results is to buy more of the thing
in front of them, which is exactly wrong when the stage behind is the
limit: rising traffic with flat signups is a page problem, and more budget
is the one action guaranteed not to help.

**Tasks persist.** An untouched urgent task is still there tomorrow as the
same task, not raised again as new thinking. A finished one is suppressed
for a week — doing the work rarely moves the numbers the same day, so a
detector firing again immediately is describing the old evidence. A
dismissal is a judgement that it does not matter, respected for three
weeks. A problem that resolves itself stops being listed without anyone
ticking anything.

**Deterministic.** Every task traces to numbers that can be shown. That is
what makes it safe to act on one without going to check first, which is
the entire point. The AI layer stays where it already is — phrasing in
insights, judgement in the page audit — and is deliberately not in the
path that decides what work exists.

Detectors live in `app/Daily/Detectors`. Adding one means implementing
`Detector` and declaring what a finding is worth; the brief handles
ranking, persistence and carry-forward. Each also reports what it checked
and found healthy, which is what fills "nothing to worry about" — knowing
what to ignore is half of why a three-item list is believable.

Not built, in rough order of value: SEO signals (needs Search Console,
which nothing here reads yet), website behaviour beyond ad-reported page
views, and learning from outcomes — the history is recorded, but nothing
yet reads "recommended X, then followers jumped" back into scoring.

## Phase 2 — Value before they spend anything

Day one is currently eight zeros and figures labelled "typical". A
first-timer has no reason to return tomorrow.

- **Comparable campaigns.** Tabletop projects with a similar goal, their
  outcomes, and what their audience looked like at launch. Replaces our
  hardcoded benchmarks with real ones and gives a first session substance.
  Sourced from public scrape datasets — Kickstarter retired its public API.
  Live and ended campaign pages expose reward tiers, per-tier backer counts
  and total raised, so real average pledges are derivable: "twelve similar
  games priced their core tier at £39 and averaged £52 a backer." A
  pre-launch page exposes none of that, which is why our own project's
  tiers have to be entered by hand.
- **Goal viability check.** Manufacturing, shipping and fees against the
  funding goal: "£25,000 funds 340 units and leaves you £2,000 short."
  Requires no integrations, and it is the question first-timers get wrong.

## Phase 3 — The ad builder

Assets and a budget in; correctly configured campaigns out. See the
[ad builder design](#ad-builder-design) below.

Not blocked by the deferred OAuth work: a creator's own app token can carry
`ads_management` for their own ad account without our app being reviewed.

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

- **Import the Kickstarter backer report.** A CSV upload, because there is
  no API — the same route BackerKit and Gamefound use. It is the only way
  to ever learn a creator's *actual* list-to-backer conversion, actual
  average pledge and actual channel performance, which is what turns our
  published rates into their measured ones.

## Gated on validation

Deliberately deferred. These cost real money or real calendar time before
there is evidence anyone wants the product, so they wait for evidence.

### Meta OAuth and App Review

"Continue with Facebook" instead of "build your own app at
developers.facebook.com and paste the token". It needs Business
Verification and App Review for `ads_management`, `ads_read`,
`leads_retrieval`, `pages_show_list` and `business_management` — a
registered business, a privacy policy, and a screencast per permission
that a reviewer actually tests.

It would take first-run setup from roughly 45 minutes of developer-console
navigation to about 15 minutes of Page and payment method. That is the
single biggest UX win available in the product, and it is still the right
call to wait: the cost lands before the evidence does.

**Revisit when** manual setup is the bottleneck rather than demand — as a
starting number, once ~20 creators have connected Meta and run ads. Write
the trigger down and check it, or "validate first" quietly becomes
"never".

**Does not block the ad builder.** A creator's own app token can carry
`ads_management` for their own ad account without our app being reviewed.

**What no amount of integration work can automate**, so it stays manual
whatever happens here:

| Automatable | Manual, always |
| --- | --- |
| Create the pixel | Have a Facebook Page |
| Create the Instant Form | Ad account with a **payment method** — Meta will never let us add a card |
| Create campaigns, ad sets, ads | Paste the pixel ID into Kickstarter's project settings |
| Upload creative, read insights and leads | Everything on Kickstarter itself |

## Later

- **Product types beyond tabletop.** Let creators pick their category and
  adapt backer rates, average pledge, ad interest targeting, comparable
  filtering and copy accordingly. To keep this cheap, tabletop assumptions
  should live behind a single profile object from Phase 1 onward rather than
  being scattered as constants — see `BackerRates`, the default average
  pledge, and the ad interest set.
- Lookalike audiences once a list clears 100 subscribers.
- Cross-promotion between creators on the platform — a real network effect,
  and standard practice in tabletop already.
- Premium tier. Candidates: campaign-over-campaign history, ad builder
  beyond the first campaign, comparables depth. Nothing gated before we
  have traction.

## Kickstarter has no usable API

Worth stating plainly, because it constrains everything. The old public API
was never officially supported and its endpoints are closed. There is no
partner programme and no write API: we can never create a project, set
rewards, or launch on a creator's behalf. Even BackerKit and Gamefound take
backer data as a CSV the creator downloads.

Consequences:

- Work *on* Kickstarter is always the creator's. We guide, we do not do.
- Follower counts come from Kickstarter's **GraphQL endpoint**, not the
  HTML. The page is a shell; React fetches the count after load, so no
  amount of pattern work reaches it. `POST /graph` with the page's own
  `PrelaunchPage` operation returns `project(slug:) { watchesCount }` —
  Kickstarter's word for a follower is a "watch". An anonymous visit
  supplies the session cookies and the `csrf-token` meta tag the call
  needs; no login is involved. HTML scraping stays as a fallback.
  This is undocumented and unversioned, so treat a shape change as
  expected: `KickstarterFollowers` records nothing rather than guessing,
  and manual entry covers the gap.
- Because the markup can change, a creator can also record the count by
  hand (`POST /projects/{id}/kickstarter-followers`). Readings from both
  paths are append-only and build one growth curve.
- Fetching a Kickstarter page at all needs the full browser header set in
  `BrowserHeaders` — Cloudflare answers `403 cf-mitigated: challenge`
  without client hints. `php artisan page:diagnose <url>` re-measures this
  from the host being refused if it ever stops working, and
  `php artisan kickstarter:inspect <url>` shows what a page exposes when a
  pattern needs rewriting.
- Post-campaign truth arrives as a CSV upload (Phase 5).

### The last step of the funnel cannot be measured

Email → click → **follow** stops being visible at the follow.

Kickstarter's project settings take a Google Analytics ID, and the tag
fires on the project page, reporting into the same property with
`hostName` containing `kickstarter.com`. So arrivals *are* measurable, and
`ks_page_sessions_by_source` records them by referrer — which answers the
question that actually changes the morning's work: whether people from the
email reach the page at all.

What Kickstarter never fires is an event when somebody follows. No pixel,
no callback, no API. So a follow can only ever be inferred from the total
count moving, and never attributed to a source.

Consequences, and the reason the wording matters:

- **Followers ÷ subscribers is a ratio, not a conversion rate.** Nothing
  links the two populations. It was labelled "email to follower converting
  at 29%", which claimed a measurement nobody has and could exceed 100%.
- **Ad-bought follows are subtracted** before that ratio is taken
  (`AudienceSize::organicFollowers`), because otherwise Kickstarter-page
  ads make an idle email list look like it is converting brilliantly.
- Where arrivals are visible, the daily list says which half is broken —
  nobody arriving means the emails are not asking, plenty arriving and few
  following means the page is not persuading — and says nothing at all
  when the tag is absent.

**Meta's pixel totals were the other way in, and they are closed.** A
follow that came from an email fires the pixel on the Kickstarter page but
was never an ad click, so Meta attributes it to nothing; the total minus
the attributed ones would have been the follows nobody paid for. Asked
against the live API with the creator's own token, all three ways of
reading those totals answered `(#100) Permission Denied`:

    /stats?aggregation=event    (#100) Permission Denied
    /stats?aggregation=host     (#100) Permission Denied
    /stats?aggregation=url      (#100) Permission Denied

Whether that is a missing scope or a withdrawn edge is not settled — the
error is the same either way, and `/debug_token` would distinguish them if
it ever seems worth chasing. The probe that asked has been removed rather
than left on a page as a button nobody should press again.

**So the measurement is follower lift around each send** (`FollowerLift`).
Followers gained in the three days from a send, minus ad-bought follows in
that window, minus what a quiet day brings for this project — the median of
recent non-send days, because one burst would drag a mean far enough to
make every later send look like a failure.

It is an inference and the UI calls it one: *"associated with"*, never *"a
conversion rate"*. It refuses to produce a number rather than guess when
the window has not finished, when there are fewer than five quiet days to
form a baseline, or when two sends overlap — and overlap is judged on the
windows, not the send dates, because the second of a close pair sits in the
first one's wake and is contaminated just as much.

Two cautions learnt the hard way here, both worth keeping:

**A truncated search is not evidence of absence.** `kickstarter:inspect`
originally capped its output in document order, and Kickstarter's
feature-flag blob at the top of every page (`backer_report_update_2024` and
forty others) exhausted the cap before the scan reached the body. It hid the
answer and produced a confident wrong conclusion. The command now ranks
matches before truncating.

**Establish server-rendered vs client-rendered before writing a single
pattern.** Rounds of better regexes were spent against a document that never
contained the number. `kickstarter:inspect` now reports this first, and
`--find` chases a value seen in the browser back through the fetched HTML.
When a value is on screen but not in the response, the answer is always the
request the page makes — find it in DevTools → Network rather than guessing
at markup.

This is not only a limitation. It settles where we sit: everything around
Kickstarter, nothing inside it.

## Budget triage

Asked once, right after the project details, because it is the single most
determinative input and the answer reshapes the product.

The arithmetic behind the advice, at our benchmark rates: £0.85 a click and
22% converting puts an email signup at about **£3.86**, against the £0.90 a
standard subscriber is worth (2% × £45). Buying email signups is roughly
four times underwater. A Kickstarter follower is worth £9, so ads that buy
*follows* clear comfortably. **Paid ads should mostly buy Kickstarter
followers; a landing-page email funnel usually cannot pay for itself.**

Meta also needs roughly 50 conversions per ad set per week to leave the
learning phase, which at £4–8 a follow is £200–400 a week just to make one
ad set function. Hence the floors:

| Budget | Advice |
| --- | --- |
| £0 | Organic playbook. A legitimate path, not a consolation prize. |
| Under ~£300 | Not enough to learn — £100 buys ~118 clicks, a weekend of noise. Concentrate it into a two-week burst before launch, or spend it on better product photography. |
| £300–1,500 | One ad set, one destination: Kickstarter follows. Do not split three ways. |
| £1,500–5,000 | The full three-ad structure with room to test. |
| £5,000+ | Add lookalikes and retargeting. |

Because we already measure organic growth rate, the trade-off becomes a
calculation rather than a lecture: *"you are adding 40 a week; you need 880
more; that is 22 weeks organically, £2,400 of ads over the 8 weeks you have,
or a mix."* Three routes to the same target, and it works on day one with
nothing connected.

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

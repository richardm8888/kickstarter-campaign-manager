# Kickstarter Launch OS

AI-powered pre-launch platform for Kickstarter creators. It guides you from
*"I have an idea"* to *"my Kickstarter funded"* — an opinionated launch
advisor, not another analytics dashboard.

## What's inside

| Area | What it does |
|---|---|
| **Dashboard** | Visitors, subscribers, VIP upgrades, conversion, cost per subscriber, revenue, projected backers, funding forecast — with the full launch funnel (Ads → Landing page → Email → VIP → Notify → Backer) |
| **Landing page builder** | Opinionated template with hero, video, features, testimonials, FAQ, email capture, £1 VIP upgrade, CTA, footer. Configuration over freedom; public capture API included |
| **Integrations** | Meta Ads, Google Analytics 4, MailerLite, Stripe behind one `Integration` contract (`connect / disconnect / sync / status`), synced hourly into an append-only metrics store |
| **AI insights** | Rule-based signal detection (CPC spikes, conversion drops, list stalls, subscribers-needed) turned into plain-English recommendations; optional Anthropic-powered copy polish |
| **Campaign health** | Weighted 0–100 readiness score across eight factors, each with a concrete next action |
| **Funding forecast** | Deterministic engine: ad spend → visitors → subscribers → VIPs → backers → funding, with what-if preview and confidence rating |

## Stack

- **Backend** — Laravel 12, Sanctum, PostgreSQL, queued jobs, scheduler
- **Frontend** — React 19, Vite, TypeScript (strict), TailwindCSS 4, TanStack Router + Query, Recharts
- **Deploy** — Docker Compose (Fly.io / Railway / Hetzner / DigitalOcean friendly)

## Local development

Backend (uses SQLite out of the box):

```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve          # http://localhost:8000
```

Frontend (proxies `/api` to the backend):

```bash
cd frontend
npm install
npm run dev                # http://localhost:5173
```

Optional: set `ANTHROPIC_API_KEY` in `backend/.env` to have insight copy
rewritten by Claude. Everything works without it — insight detection is
deterministic and rule-based.

## Docker

```bash
APP_KEY=$(cd backend && php artisan key:generate --show)
APP_KEY=$APP_KEY docker compose up --build   # app on http://localhost:8080
```

Compose runs Postgres, the API, a queue worker, the scheduler (hourly
integration syncs → fresh insights) and the built frontend behind nginx.

## Tests

```bash
cd backend && php artisan test    # feature + critical forecasting unit tests
cd frontend && npx tsc -b         # strict type-check
```

## Architecture notes

- `backend/app/Integrations` — one class per provider implementing the
  `Integration` contract; API calls never leak elsewhere.
- `backend/app/Forecasting` — pure, deterministic forecast engine (DTO in,
  DTO out) so the numbers are exactly testable.
- `backend/app/AI` — generators depend on an `AiProvider` contract;
  Anthropic driver + null driver, no vendor calls in controllers.
- `backend/app/Recommendations` — campaign health scoring that always maps
  a score to an action.
- `metric_snapshots` is **append-only** time series: syncs insert new
  observations, history is never rewritten.
- `frontend/src/features/*` — feature folders (auth, dashboard, analytics,
  integrations, landing-page, ai, forecasting) over a small shadcn-style
  UI kit; dark mode, mobile-first, skeleton/empty/error states throughout.

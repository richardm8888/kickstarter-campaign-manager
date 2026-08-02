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

## Deploying to a droplet / VM (recommended)

`docker-compose.yml` runs the platform — API, a real queue worker, the
scheduler (hourly syncs → fresh insights) and the built frontend behind
nginx — on any small VM (1 GB RAM is enough to start). It serves on port
**8080** by default so it can share a droplet with an app already using
port 80.

**Database**: use a managed Postgres if you have one (recommended — DO
managed databases handle backups for you):

1. In DigitalOcean, open your database cluster → *Settings → Trusted
   sources* → add the droplet, so it can connect.
2. Copy the *Connection string* from *Connection details* (it looks like
   `postgresql://doadmin:...@host:25060/defaultdb?sslmode=require`) and set
   it as `DB_URL` in `.env`. Tables are created in `defaultdb` on first
   boot; create a dedicated database in the cluster first if you'd rather
   keep them separate.

No managed database? Skip `DB_URL` and add the bundled Postgres overlay to
every compose command:
`docker compose -f docker-compose.yml -f docker-compose.local-db.yml ...`

On the droplet:

```bash
# 1. Docker (skip if already installed)
curl -fsSL https://get.docker.com | sh

# 2. Get the code
git clone https://github.com/richardm8888/kickstarter-campaign-manager.git
cd kickstarter-campaign-manager

# 3. Configure
cp .env.example .env
nano .env        # set APP_KEY (command in the file), DB_URL, APP_URL

# 4. Launch — app on http://<droplet-ip>:8080
docker compose up -d --build

# 5. Firewall (if using ufw)
ufw allow 8080/tcp
```

Updating later: `git pull && docker compose up -d --build`.
Logs: `docker compose logs -f backend`.

**Adding a domain later** (droplet already routes another domain through a
host proxy): keep `HTTP_PORT=8080` and add a virtual host that proxies to
it. nginx:

```nginx
server {
    listen 80;
    server_name launch.yourdomain.com;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

…then `certbot --nginx -d launch.yourdomain.com` for HTTPS. (Caddy
equivalent: `launch.yourdomain.com { reverse_proxy localhost:8080 }` —
certificates are automatic.) Finally set
`APP_URL=https://launch.yourdomain.com` in `.env` and
`docker compose up -d` again.

## Free hosting (Render + Neon + GitHub Actions)

The repo ships a single-service free-tier deployment: the root `Dockerfile`
builds the React app and serves it from Laravel, so everything fits one free
web service.

1. **Database** — create a free project at [neon.tech](https://neon.tech) and
   copy its Postgres connection string.
2. **App** — at [render.com](https://render.com) choose *New → Blueprint*,
   point it at this repo (it picks up `render.yaml`), and fill in:
   - `APP_KEY`: output of `openssl rand -base64 32 | sed 's/^/base64:/'`
   - `DB_URL`: the Neon connection string (keep `?sslmode=require`)
   - `ANTHROPIC_API_KEY`: optional
3. **Hourly syncs** — the free tier has no resident scheduler, so a GitHub
   Actions cron (`.github/workflows/scheduled-sync.yml`) pings the app
   instead. Add two repository secrets in GitHub (*Settings → Secrets and
   variables → Actions*): `APP_URL` (your Render URL) and `CRON_SECRET`
   (copy the generated value from the Render environment tab).

Free-tier trade-offs: the service sleeps after ~15 minutes idle (first visit
takes ~30–60 s to wake — relevant for public landing pages), and queued jobs
run inline (`QUEUE_CONNECTION=sync`). When you outgrow it, `docker-compose.yml`
runs the same code with a real queue worker and scheduler on any VM.

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

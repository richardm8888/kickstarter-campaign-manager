# Kickstarter Launch OS

AI-powered pre-launch platform for Kickstarter creators. It guides you from
*"I have an idea"* to *"my Kickstarter funded"* — an opinionated launch
advisor, not another analytics dashboard.

## What's inside

| Area | What it does |
|---|---|
| **Dashboard** | Visitors, subscribers, VIP upgrades, conversion, cost per subscriber, Kickstarter followers, projected backers, funding forecast — with the full launch funnel (impressions → clicks → landing page views \| form views → signups → VIPs → backers) |
| **Landing page builder** | Opinionated template with hero, video, features, testimonials, FAQ, email capture, £1 VIP upgrade, CTA, footer. Configuration over freedom; public capture API included |
| **Page audits** | Two analysers — your own landing page and your Kickstarter page — each scoring deterministic checks and then reading the page as a visitor would (see below) |
| **Integrations** | Meta Ads, Google Analytics 4, MailerLite, Stripe behind one `Integration` contract (`connect / disconnect / sync / status`), synced into an append-only metrics store — metrics hourly, new contacts every five minutes |
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

**Or stop updating it by hand.** Once three secrets are set, merging to
`main` builds the images, migrates and swaps the containers on its own,
rolling back if the new build does not come up healthy — see
[docs/DEPLOY.md](docs/DEPLOY.md).

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

## Small droplets and memory

Building the images is far more memory-hungry than running them: `npm ci`
plus the Vite build can spike past 1 GB, while the running stack (PHP
server, queue, scheduler, nginx) sits in the low hundreds of MB — less
still when using a managed database, since no Postgres runs locally.

If a build is killed on a 1 GB droplet, add swap before resizing:

```bash
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

Confirm an out-of-memory kill with `dmesg | grep -i "killed process"`.

Tune the runtime footprint with `PHP_WORKERS` in `.env` (each worker is
roughly 40–80 MB): use `2` on a 1 GB droplet shared with other apps, `4–8`
where there's headroom.

Note that `backend`, `queue` and `scheduler` share one image, so a full
build is two images (API + frontend), not four. Building them one at a
time keeps peak memory lower than the default parallel build:

```bash
docker compose build backend
docker compose build frontend
docker compose up -d
```

### Deploying without building (recommended for 1 GB droplets)

`.github/workflows/publish-images.yml` builds both images on GitHub's
runners and pushes them to GHCR. The droplet then only downloads them:

1. Let the workflow run (it triggers on push, or run it manually from the
   repo's *Actions* tab).
2. Make the two packages public under the repo's *Packages* settings — or,
   to keep them private, log the droplet in first:
   `echo <token> | docker login ghcr.io -u <username> --password-stdin`
   using a token with `read:packages`.
3. On the droplet, set the prefix in `.env`:

   ```bash
   IMAGE_PREFIX=ghcr.io/richardm8888/kickstarter-campaign-manager
   TAG=latest
   ```

4. Deploy — and for every update afterwards:

   ```bash
   docker compose pull
   docker compose up -d --no-build
   ```

Each release is also tagged with its commit SHA, so `TAG=<sha>` pins or
rolls back to any previous build.

## Troubleshooting deployment

**`permission denied for schema public` on first migration.** PostgreSQL 15+
only lets the owner create objects in the `public` schema, so a
non-owner application user cannot create the `migrations` table. Connect as
the admin user (`doadmin` on DigitalOcean) to the application's database —
not `defaultdb` — and hand the schema over:

```sql
GRANT ALL PRIVILEGES ON DATABASE <database> TO "<app_user>";
GRANT USAGE, CREATE ON SCHEMA public TO "<app_user>";
ALTER SCHEMA public OWNER TO "<app_user>";
```

**`dependency failed to start`** on queue or scheduler means the API
container exited or never became healthy — the real error is in
`docker compose logs backend`. A connection timeout usually means the host
isn't in the database's trusted sources.

**`DB_URL` truncated.** In a `.env` file `#` starts a comment, so a password
containing one is silently cut short. Wrap the value in single quotes and
verify with `docker compose run --rm backend printenv DB_URL`.

## Keeping it running

Every service sets `restart: unless-stopped`, so containers restart on
crash and come back after a droplet reboot. Confirm Docker itself starts
at boot (default for apt installs):

```bash
systemctl is-enabled docker      # expect: enabled
```

**Integration syncs.** Every sync logs its outcome to the container logs —
provider, project, duration, metrics recorded, or the error:

```bash
docker compose logs -f scheduler          # sync triggers
docker compose logs -f queue              # the syncs themselves
docker compose logs queue | grep 'Integration sync'
```

Run one on demand and see the result immediately — the fastest way to
diagnose a provider returning nothing:

```bash
docker compose exec backend php artisan integrations:sync
docker compose exec backend php artisan integrations:sync --provider=ga4
```

It prints a row per integration with the metrics recorded or the error,
and exits non-zero if any failed. Jobs that fail every attempt land in the
failed-jobs table:

```bash
docker compose exec backend php artisan queue:failed
docker compose exec backend php artisan queue:retry all
```

**Meta Instant Form leads.** Leads submitted through a Meta form stay
inside Facebook, where you cannot email them. The scheduler imports them
**every five minutes** and forwards them to MailerLite, so a welcome email
follows a signup while the person still remembers signing up. A wider
hourly sweep re-checks the past month and re-forwards anyone MailerLite
never accepted, so a lead that arrived during an expired token is not lost.
Run either on demand with:

```bash
docker compose exec backend php artisan meta:import-leads
```

The Meta token needs the `leads_retrieval` permission for this.

**Stripe VIP purchases.** Select which Stripe products count as a VIP
upgrade on the Integrations screen. On the same five-minute cycle, buyers
of those products become VIP subscribers and are added to your VIP email
group; every other purchase is counted as revenue only. On demand:

```bash
docker compose exec backend php artisan stripe:import-vips
```

**Page audits.** Both analysers live on the Landing page screen and run two
passes over whatever URL you give them.

The **checks** are deterministic and rule-based, so the same page always
scores the same and progress between runs is real. They go past "is there a
form in the markup" to the things that actually cost signups: how many
calls to action compete for one decision, how many fields the form asks
for, how much copy a visitor wades through before meeting a next step,
whether the headline says anything. A check has three outcomes, not two —
where a signal cannot be read (a JavaScript-injected form, a Kickstarter
markup change) it records that we could not tell and is excluded from the
score entirely, rather than punishing the creator for our blind spot.

The **UX walk** is the AI reading the page as a first-time visitor and
saying what is wrong, quoting the words it is criticising and naming the
change to make. It never touches the score: a language model's opinion
should not move a number tracked week to week. Without `ANTHROPIC_API_KEY`
the checks still run and the walk is simply absent.

The Kickstarter analyser is a different audit, not the same one pointed
elsewhere — video weight, title length, reward tier count, risks, shipping
— and it detects whether the page is pre-launch or live, so a pre-launch
page is never marked down for lacking reward tiers it cannot have yet.

**Kickstarter followers.** Paste your pre-launch page URL into Settings and
the scheduler reads its follower count every hour — Kickstarter publishes no
API for it, so the public page is the only source. On demand:

```bash
docker compose exec backend php artisan kickstarter:followers
```

Followers matter out of proportion to their number. The forecast treats the
three audiences separately, because they do not behave alike:

| Audience | Backs at | Why |
| --- | --- | --- |
| Email subscribers | 1–3% | gave an address for a lead magnet, may not remember |
| Kickstarter followers | 10–30% | notified by Kickstarter the moment you open, already have a payment method there |
| Paid VIP reservers | 15–60% | have already paid, which filters for intent better than anything else |

One follower is therefore worth about ten email subscribers, which is why
the ad report judges each ad against what its *destination* produces rather
than on cost per click alone.

Choose which MailerLite group imported contacts join on the Integrations
screen — automations and segments are usually built around groups, so this
is what makes the imported list actionable.

Every answer the form collected travels with the contact, plus `lead_id`
(Meta's own id) and `lead_source` (the form's answer if it asks, otherwise
the campaign name). Question names become snake_case keys — "Lead Source"
→ `lead_source` — so create matching custom fields in MailerLite
(*Subscribers → Fields*) or it will ignore them.

**Kickstarter follows vs email signups.** If your ads send people to a
Kickstarter page, the pixel's `Lead` event means a *follow*, not a contact
you can email. Map it so the two are never conflated:

```bash
docker compose exec backend php artisan meta:actions   # see what Meta reports
docker compose exec backend php artisan meta:map \
  --follow="offsite_conversion.fb_pixel_lead" \
  --lead="leadgen_grouped"
```

Follows then feed the Kickstarter step of the funnel, while signups stay
the measure of list growth.

**Health checks.** The API is probed via Laravel's `/up` endpoint and the
frontend via its nginx root, so status is visible at a glance:

```bash
docker compose ps                # STATUS column shows (healthy)
docker compose logs -f backend   # follow API logs
docker compose restart backend   # restart one service
```

Note that Docker restarts containers that *exit*, not ones that are merely
unhealthy. That covers crashes and reboots. If you also want automatic
recovery from a hung-but-alive process, add a watchdog:

```yaml
# optional, appended to docker-compose.yml
  autoheal:
    image: willfarrell/autoheal
    restart: unless-stopped
    environment:
      AUTOHEAL_CONTAINER_LABEL: all
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
```

**Logs** are capped at 10 MB × 3 files per service, so they can't fill the
disk. Reclaim space from old images after updates with
`docker system prune -af` (safe — it leaves volumes alone).

**Outside monitoring.** `/up` is a public health endpoint, ideal for a free
uptime monitor (UptimeRobot, Better Stack) pointed at
`https://your-domain/up` to alert you if the droplet or app goes down. Pair
it with DigitalOcean's own droplet alerts for CPU and disk.

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
3. **Scheduled syncs** — the free tier has no resident scheduler, so a
   GitHub Actions cron (`.github/workflows/scheduled-sync.yml`) pings the
   app instead. Add two repository secrets in GitHub (*Settings → Secrets
   and variables → Actions*): `APP_URL` (your Render URL) and `CRON_SECRET`
   (copy the generated value from the Render environment tab). Note that
   this runs hourly at best — GitHub throttles scheduled workflows heavily,
   so new contacts wait up to an hour for their welcome email. The
   five-minute cycle needs the resident scheduler in `docker-compose.yml`.

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

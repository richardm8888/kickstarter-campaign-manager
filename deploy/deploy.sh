#!/usr/bin/env bash
#
# Deploys a commit to this host.
#
# Runs on the droplet, piped in over SSH by .github/workflows/deploy.yml so
# it never depends on what the droplet already has checked out. Can also be
# run by hand:
#
#   ./deploy/deploy.sh <sha>
#
# The commit is the unit of deployment, not `latest`. A tag that moves is
# fine when a human is watching and wrong when nothing is: pinning means
# the running code can be named, and rolling back is just deploying the
# previous name again.

set -euo pipefail

SHA="${1:?usage: deploy.sh <commit-sha>}"
DEPLOY_PATH="${DEPLOY_PATH:-/opt/kickstarter-launch-os}"
HEALTH_RETRIES="${HEALTH_RETRIES:-30}"
HEALTH_INTERVAL="${HEALTH_INTERVAL:-5}"

cd "$DEPLOY_PATH"

# The compose file, the deploy script and the migrations all live in the
# repository, so the checkout has to move before anything else does.
git fetch --quiet origin main
git checkout --quiet --detach "$SHA"

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

# Whatever is running now, so a failed deploy has somewhere to go back to.
PREVIOUS="$(grep -E '^TAG=' .env 2>/dev/null | cut -d= -f2- || true)"
PREVIOUS="${PREVIOUS:-latest}"

set_tag() {
    # Written into .env rather than exported, so a later `docker compose up`
    # typed by hand on this box starts the same code that is running now.
    if grep -qE '^TAG=' .env; then
        sed -i "s|^TAG=.*|TAG=$1|" .env
    else
        printf 'TAG=%s\n' "$1" >> .env
    fi
}

health_ok() {
    # Three independent signals, because each alone lies.
    #
    # The backend image already defines a healthcheck, so ask Docker
    # rather than inventing a second definition of healthy. But that is
    # measured from inside the backend container, so it says nothing
    # about whether anything can reach it. Fetching the page a visitor
    # would proves the frontend serves files — and files come off
    # nginx's own disk, so that says nothing about the API either.
    #
    # Both of those passed while every API call was failing, which is
    # how a completely unusable site was deployed and reported healthy.
    # The third signal is the one that would have caught it.
    local id port status
    id="$(docker compose ps -q backend 2>/dev/null || true)"

    [ -n "$id" ] || return 1
    [ "$(docker inspect -f '{{.State.Health.Status}}' "$id" 2>/dev/null)" = 'healthy' ] || return 1

    port="$(grep -E '^HTTP_PORT=' .env 2>/dev/null | cut -d= -f2- || true)"
    port="${port:-8080}"

    curl -fsS --max-time 5 -o /dev/null "http://127.0.0.1:${port}/" || return 1

    # The API, through the frontend, by the route a browser uses.
    #
    # 401 is the expected answer and a perfectly good one: it can only
    # come from Laravel, so it proves the whole path resolves. What is
    # being ruled out is 502 and 504 — nginx holding an address nothing
    # answers on — and 000, meaning no reply at all.
    status="$(curl -s --max-time 10 -o /dev/null -w '%{http_code}' \
        -H 'Accept: application/json' "http://127.0.0.1:${port}/api/projects" || echo 000)"

    case "$status" in
        000|5*) return 1 ;;
        *) return 0 ;;
    esac
}

wait_for_health() {
    for _ in $(seq 1 "$HEALTH_RETRIES"); do
        if health_ok; then
            return 0
        fi
        sleep "$HEALTH_INTERVAL"
    done

    return 1
}

roll_back() {
    say "Deploy failed — rolling back to $PREVIOUS"
    set_tag "$PREVIOUS"

    # The previous images are still on disk, so this does not need the
    # registry and works even if that is why the deploy failed.
    docker compose up -d --no-build --remove-orphans

    if wait_for_health; then
        echo "Rolled back and healthy. The site is up on the previous build."
    else
        echo "ROLLBACK FAILED — the site is down and needs a human." >&2
    fi

    exit 1
}

say "Deploying $SHA"
set_tag "$SHA"

# The workflow passes a token scoped to this repository and valid for the
# length of the job, so nothing long-lived is stored on the droplet. When
# the script is run by hand there is no token, and the login already on
# the box is used instead.
if [ -n "${REGISTRY_TOKEN:-}" ]; then
    printf '%s' "$REGISTRY_TOKEN" \
        | docker login ghcr.io -u "${REGISTRY_USER:-x}" --password-stdin > /dev/null
fi

# Pull before touching anything running: a registry problem should fail
# while the old containers are still serving.
say "Pulling images"
docker compose pull --quiet

# Migrations run in a throwaway container on the new image, before the new
# app starts. The other way round leaves a window where new code queries
# columns that do not exist yet, which is a 500 to whoever is browsing.
say "Running migrations"
# -T and </dev/null: `docker compose run` attaches stdin by default. When
# this script arrives on stdin, that means it reads the script itself —
# everything below this line disappears and bash exits 0 having deployed
# nothing. The workflow no longer feeds the script in on stdin, and this
# is the second lock on the same door.
if ! docker compose run --rm --no-deps -T backend php artisan migrate --force < /dev/null; then
    say "Migration failed — nothing has been swapped, the old build is still serving"
    set_tag "$PREVIOUS"
    exit 1
fi

say "Starting containers"
docker compose up -d --no-build --remove-orphans

say "Waiting for health"
wait_for_health || roll_back

# Images accumulate quickly on a small droplet, and a full disk fails the
# next deploy in a way that looks like something else entirely.
say "Cleaning up old images"
docker image prune -f --filter "until=168h" > /dev/null || true

say "Deployed $SHA"

# Proof that the script ran to the end. A deploy that stops early — for
# any reason, not just the stdin bug — has to fail loudly rather than
# report success on the strength of an exit code from wherever it
# stopped. The workflow greps for this exact line.
echo "DEPLOY-COMPLETE $SHA"

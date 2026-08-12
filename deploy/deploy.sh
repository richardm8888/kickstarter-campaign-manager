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
    # Two independent signals, because either alone lies. The backend
    # image already defines a healthcheck, so ask Docker rather than
    # inventing a second definition of healthy — but a healthy backend
    # behind a broken frontend is still a dead site, so also fetch the
    # page a visitor would.
    local id port
    id="$(docker compose ps -q backend 2>/dev/null || true)"

    [ -n "$id" ] || return 1
    [ "$(docker inspect -f '{{.State.Health.Status}}' "$id" 2>/dev/null)" = 'healthy' ] || return 1

    port="$(grep -E '^HTTP_PORT=' .env 2>/dev/null | cut -d= -f2- || true)"

    curl -fsS --max-time 5 -o /dev/null "http://127.0.0.1:${port:-8080}/"
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
if ! docker compose run --rm --no-deps backend php artisan migrate --force; then
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

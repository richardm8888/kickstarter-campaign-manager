# Deploying

Merging to `main` deploys to the droplet. Nothing else needs doing.

```
merge to main
  → Publish images   builds backend + frontend, tags them with the commit
  → Deploy           SSHes in, migrates, swaps containers, checks health
```

The commit is the unit of deployment. Every image is tagged with its SHA,
the droplet's `.env` pins `TAG` to that SHA, and rolling back is deploying
an earlier one. `latest` still exists and now only ever moves on `main`.

## One-time setup

### 0. What `<user>` and `<droplet-ip>` are

Both are what you already use to get into the droplet. Nothing new to
create.

- **`<droplet-ip>`** — DigitalOcean → Droplets, the IP is beside the
  droplet's name. From on the droplet itself: `curl -4 ifconfig.me`.
- **`<user>`** — whoever you are when you SSH in. On a stock DigitalOcean
  droplet that is `root`. From on the droplet: `whoami`.

If `ssh <user>@<droplet-ip>` works from your machine, those are the two
values. Deploying as `root` is fine — it is one box running one app, and a
separate deploy user buys little against the chance of locking yourself
out of your own droplet mid-setup.

### 1. A key for GitHub to use

Your own SSH key would work, but give GitHub its own so it can be revoked
without touching your access. On your machine:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/launch-os-deploy -C 'github-deploy' -N ''
ssh-copy-id -i ~/.ssh/launch-os-deploy.pub <user>@<droplet-ip>
```

`ssh-copy-id` will ask for the droplet password, or use your existing key
if you have one. Check it worked before going further — a key that was
never installed fails later as an unhelpful "Permission denied" inside a
GitHub Action:

```bash
ssh -i ~/.ssh/launch-os-deploy <user>@<droplet-ip> 'whoami && docker ps'
```

That should print the username and a container list. If `docker ps` says
permission denied, see step 3.

### 2. Repository secrets

**Settings → Secrets and variables → Actions → Secrets:**

| Secret | Value |
| --- | --- |
| `SSH_HOST` | droplet IP or hostname |
| `SSH_USER` | the user you copied the key to |
| `SSH_PRIVATE_KEY` | contents of `~/.ssh/launch-os-deploy` — the whole file, `BEGIN`/`END` lines included |

**→ Variables** (optional):

| Variable | Default | Value |
| --- | --- | --- |
| `DEPLOY_PATH` | `/opt/kickstarter-launch-os` | where the repo is checked out on the droplet |
| `APP_URL` | — | shown as a link on the deployment in GitHub |

No registry credentials are stored on the droplet. The workflow passes a
token that is scoped to this repository and expires with the job.

### 3. The droplet

Skip this if `docker ps` already worked in step 1 and the repo is already
checked out — it probably is, since that is how the app got there.

Docker has to run without `sudo`. As `root` it already does; any other
user needs adding to the group:

```bash
sudo usermod -aG docker <user>   # log out and back in for it to take
```

The deploy also needs to know where the repository lives. **If it is
already checked out somewhere else** — likely, if you set the droplet up
by hand — do not move it. Set the `DEPLOY_PATH` variable in GitHub to
wherever it is (`pwd` in that directory tells you), and skip the clone.

Only if there is no checkout yet:

```bash
sudo git clone https://github.com/richardm8888/kickstarter-campaign-manager \
  /opt/kickstarter-launch-os
sudo chown -R <user>:<user> /opt/kickstarter-launch-os
cd /opt/kickstarter-launch-os
cp .env.example .env
```

Either way, `.env` needs `IMAGE_PREFIX` so images are pulled rather than
built on the droplet:

```
IMAGE_PREFIX=ghcr.io/richardm8888/kickstarter-campaign-manager
```

Leave `TAG` alone — deploys overwrite it.

### 4. Check it before trusting it

Actions → Deploy → Run workflow, leaving the commit box empty. It deploys
the tip of `main`, which is what is already running, so a mistake in the
setup shows up while nothing is changing.

Building on the droplet still works and needs none of the above; it is
just slow and memory-hungry on a small box, which is why the images are
built on GitHub's runners instead.

## What a deploy does

1. **Moves the checkout** to the commit, so the compose file and the
   migrations match the images.
2. **Logs in and pulls.** Before anything running is touched — a registry
   problem should fail while the old containers are still serving.
3. **Migrates**, in a throwaway container on the *new* image, *before* the
   new app starts. The other order leaves a window where new code queries
   columns that do not exist yet, which is a 500 to whoever is browsing. A
   failed migration stops the deploy with the old build still up.
4. **Swaps the containers.**
5. **Waits for health** — up to about two and a half minutes, which covers
   the 90-second `start_period` on the backend's healthcheck. Two signals
   have to agree: Docker reports the backend healthy, and the site itself
   answers on `HTTP_PORT`. A healthy backend behind a broken frontend is
   still a dead site.
6. **Rolls back on its own** if that fails: puts `TAG` back, restarts the
   previous images (still on disk, so this works even when the registry is
   the problem) and waits for health again. If the rollback is also
   unhealthy it says so loudly, because at that point it needs a person.
7. **Prunes images** older than a week. A full disk fails the next deploy
   in a way that looks like something else entirely.

## Deploying something else

**Roll back, or ship a specific commit:** Actions → Deploy → Run workflow →
paste the commit. Manual runs skip the "was the build green" check, which
is deliberate: that check is what would stop you shipping a known-good
commit during an incident.

**By hand on the droplet**, if GitHub is unavailable:

```bash
cd /opt/kickstarter-launch-os
./deploy/deploy.sh <sha>
```

Same script, same steps, same rollback. Without a token it uses whatever
`docker login` the box already has.

## Things worth knowing

**Deploys queue rather than cancel.** Two merges in quick succession deploy
in order. Cancelling would risk interrupting a migration, and would let a
merge cancel the deploy of the merge before it and leave that commit
unshipped.

**The database is not in `docker-compose.yml`.** It is expected at `DB_URL`
or `DB_HOST` — managed Postgres, or one running outside compose. Deploys do
not touch it beyond running migrations, but note that a rollback rolls back
*code only*. A migration that drops a column is not undone by deploying the
previous image, so anything destructive wants a backup taken first.

**There is one droplet and no staging.** CI runs the tests on every push, so
a red build never becomes a deploy, but the first place any merge runs for
real is production.

# Working on this repository

## Branching

**One branch per change, and never reuse a branch whose PR has merged.**

A merged pull request is closed for good — it cannot pick up new commits.
Pushing more work to the branch behind it produces commits that are on
GitHub, pass CI, and are tracked by nothing. This has already happened
twice here, both times noticed only because someone asked where a change
had got to.

So after a merge, start again from `main`:

```bash
git fetch origin main
git checkout -b claude/<short-slug> origin/main
```

Name the branch after the change, not the session. Open the PR while the
work is still small enough to describe in a sentence.

## Deploying

Merging to `main` deploys. There is no staging, and the droplet is
production — see [docs/DEPLOY.md](docs/DEPLOY.md) for what a deploy does
and how to roll one back.

## Units

Two conventions live side by side, and mixing them has caused real bugs:

- **Money the app owns** — pledges, funding goals, budgets — is in **minor
  units** (pence). Written as `45_00`.
- **Money from Meta** — everything on an ad row: `spend`, `cpc`, `cpl` — is
  in **currency units**, because that is how the API reports it and nothing
  converts it. Written as `20.0`.

A threshold written `20_00` against ad spend is a £2,000 floor, not £20.
The failure is silent: the check simply never fires, which looks exactly
like nothing being wrong.

## Diagnostics and warnings

Anything that tells a creator something is broken has to be right, because
a false alarm costs them a morning and teaches them to ignore the next one.
Three rules earned the hard way:

- **Compare like with like.** An instant form ad never loads a page; a
  Kickstarter ad loads one we cannot tag. Measuring either against a pixel
  event invents a problem out of arithmetic.
- **Only warn about things that can still be acted on.** Ads that are off,
  campaigns that ended: their numbers are history, not decisions.
- **Narrow by what something cannot do, not by what it is.** Excluding
  unclassified data turns a field we failed to read into a diagnostic that
  silently stopped running.

## Tests

`php artisan test` in `backend`, `npx tsc -b && npm run build` in
`frontend`. When fixing a false positive, test both directions — that the
false case is quiet *and* that the real case still fires. Narrowing a check
without the second half just converts a false alarm into a blind spot.

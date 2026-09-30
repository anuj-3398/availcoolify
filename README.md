<div align="center">

<img src="./public/coolify-logo.svg" alt="AvailCoolify logo" width="120" />

# AvailCoolify

**Avail's self-hosted deploy platform: a customised fork of [Coolify](https://github.com/coollabsio/coolify).**

[Dashboard](https://coolify.avail.tools) · [Upstream Coolify](https://github.com/coollabsio/coolify) · [Coolify docs](https://coolify.io/docs)

</div>

## What it is

Developers push to GitHub and AvailCoolify builds and runs the app at `https://<app>.apps.avail.tools`. Every pull request gets a private preview behind the Clerk login. The in-app **Developer Guide** (account menu → Developer Guide) explains day-to-day use with screenshots.

## What's different from Coolify

| Area | AvailCoolify |
| --- | --- |
| Sign-in | Clerk only. Password login, Coolify 2FA and the other OAuth providers are removed from the UI. |
| Teams | One **Avail Team**, no personal teams. Clerk users with an `@availproject.org` email (`AVAIL_AUTO_JOIN_DOMAINS`) join it as members automatically; everyone else needs an invitation and sees "Waiting for an invitation" until they accept one. Removing someone sticks: they don't rejoin on their next sign-in until an admin lets them back in. Only the Avail Team owner can create teams or upgrade the platform. |
| Roles | **Guest:** read-only, and only the projects shared with them. **Member:** can create apps (public repo, their own GitHub repos, Dockerfile, image), deploy, restart, stop and roll back them, and delete apps they created. **Admin:** settings, env vars, terminal, databases, services, projects and members. **Owner:** everything, plus teams and upgrades. |
| Guests | Invitations default to Guest, with access for 30, 60 or 90 days, until a date, or without expiry, counted from acceptance. Admins tick which projects each guest sees in **Settings → Guest access** or a project's settings. Guests can't use the API, and their previews open only for their projects. Expired guests see "Your guest access has ended". Guests get an email 7 days before and when their access ends, once transactional email is configured. |
| GitHub | Vercel-style **Continue with GitHub**: one platform GitHub App; each user connects their account and only sees repositories they can push to. Uninstalled or suspended installations stop deploys. |
| Previews | PR previews always sit behind a Clerk login (Traefik ForwardAuth), and each PR gets an "AvailCoolify preview" status check. |
| Access protection | One switch per environment (Settings → Access protection) puts every app in it behind the Clerk login, e.g. staging on, production off. |
| New resources | Apps deploy and databases start right after creation. |
| UI | AvailCoolify branding, trimmed resource picker and account menu, dashboard with projects first and the latest deployment per app, in-app Developer Guide. |

## Branches

| Branch | Purpose |
| --- | --- |
| `main` | Exact mirror of `coollabsio/coolify` `main`, fast-forward only. |
| `custom` | All Avail changes on top of `main`. This is what gets deployed. |

Syncing with upstream:

```bash
git checkout main && git fetch upstream && git merge --ff-only upstream/main && git push origin main
git checkout custom && git rebase main        # resolve conflicts, run the tests
git push --force-with-lease origin custom
```

## Deploying

The server runs a local image built from `custom`, not the official Coolify image, and Coolify's auto-update is off. Don't use the dashboard's Upgrade button; upgrade by syncing upstream and redeploying.

```bash
./scripts/deploy-custom.sh                              # build HEAD of custom and deploy it
./scripts/deploy-custom.sh availcoolify:custom-<sha>    # roll back to an earlier build
```

The script builds `docker/production/Dockerfile`, pins the image in `docker-compose.custom.yml` next to Coolify's compose files, sets the reported version to `<base>-avail.<sha>` and restarts the stack. Vendor patches in `patches/` are applied during the build, which fails if one no longer applies.

## Tests

Our tests are the `tests/Feature/Avail*.php` files plus the updated upstream tests for the areas we changed. Run them with Pest, as upstream does:

```bash
vendor/bin/pest tests/Feature/Avail*.php
```

A few upstream tests fail by design, because the feature they cover is removed here (for example password login and the forgot-password link).

## Upstream

Everything else is Coolify: see the [upstream README](https://github.com/coollabsio/coolify#readme) and [documentation](https://coolify.io/docs). Coolify is licensed under Apache-2.0, and so is this fork.

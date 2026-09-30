AvailCoolify is our self-hosted deploy platform, a customised Coolify. Push to GitHub and it builds and runs your app over HTTPS; open a pull request and it spins up a private preview.

- **Dashboard:** [coolify.avail.tools](https://coolify.avail.tools)
- **Sign-in:** Clerk only (the same Clerk account as HQ). There is no password login.
- **Owner:** the owner of Avail Team (see **Team → Members**) runs the platform. Ask the Owner for new projects, role changes or anything broken.

## Getting access

Sign in with Clerk with your **@availproject.org** email and you're in: your account is created on first login and joins **Avail Team** as a **Member** automatically. Nobody gets a personal team.

Anyone else (partners, contractors, auditors) needs an invitation from an admin. Until they accept one, signing in shows a "Waiting for an invitation" page.

![Sign-in page with the Login with Clerk button](/images/developer-guide/login.jpg)

1. Open [coolify.avail.tools](https://coolify.avail.tools) and click **Login with Clerk**. You always get a fresh sign-in screen, so pick the right account.
2. You land on the dashboard of **Avail Team**: projects first, then recent deployments (the latest one per app). Guests see only the projects shared with them.
3. Need more than a member can do? Ask the Owner or an admin to change your role under **Team → Members**.

![Dashboard with projects and recent deployments](/images/developer-guide/dashboard.jpg)

| Role | Can do |
| --- | --- |
| Guest | View only the projects an admin shared with them: their apps, deployments and build logs. Read-only, no API access, and usually time-limited (30 days by default) |
| Member | View apps, deployments and build logs; create apps (public repo, your own GitHub repos, Dockerfile, image); deploy, redeploy, restart, stop and roll back apps; runtime logs, environment variables and delete for apps you created |
| Admin | Everything a member can, plus app settings, env vars, web terminal, databases, services, projects and environments, and managing team members |
| Owner | Everything an admin can, plus creating teams and upgrading the platform |

Projects and environments are created by admins. Two-factor is handled by Clerk, not in the profile.

## Deploying an app

Every app lives in a project and environment, e.g. **Avail Project → staging**. Push to the app's branch and it redeploys automatically.

![From push to live app, and pull request previews](/images/developer-guide/deploy-flow.svg)

1. Open the environment → **+ New resource** → **Git Repository (with GitHub App)**.
2. Click **Continue with GitHub** (once). If the AvailCoolify GitHub app isn't installed on your account yet, GitHub asks you to install it; choose the repositories it may see.
3. Pick an account or organisation. You only see repositories you can push to.
4. Pick the repo and branch. The app is created and its first deployment starts straight away.

![Import from GitHub: connected account and repository access](/images/developer-guide/github-import.jpg)

- **Missing account or org:** use **+ Install on a GitHub account or organisation** (org installs need an org owner on GitHub).
- **Missing repo:** use **Adjust repository access** and add it to the installation.
- **Build:** apps build with Railpack, which detects the language from the repo. A Dockerfile or Docker Compose file can be used instead (app → Configuration → Build Pack).
- **Manual deploy:** open the app → **Deploy** (or **Redeploy**). Use **Restart** when only the container needs restarting, not a rebuild.

![Application page with its URL and the Deploy button](/images/developer-guide/app-general.jpg)

**App URLs** are `https://<name>.apps.avail.tools` with a real certificate, e.g. `https://nexus-fast-bridge.apps.avail.tools`. New apps start with a random name; an admin can change it under **Domains**, followed by a redeploy.

**Deployment log:** app → **Deployment Logs** lists every run; open one to see each build and start step.

![Deployment history of an application](/images/developer-guide/deployments.jpg)

## Preview deployments

Every pull request gets its own private preview URL, behind a Clerk login that only lets members of the team in (and guests who have that project).

1. **Open a PR** against the app's repo. AvailCoolify builds the PR branch, comments on the PR with the preview link, and adds an **AvailCoolify preview** check that turns green ("Preview ready") when it's up.
2. **Preview URL:** `https://<PR number>.<app>.apps.avail.tools`, e.g. PR 7 → `7.nexus-fast-bridge.apps.avail.tools`.
3. **Push more commits** to the PR: the preview rebuilds.
4. **Close or merge the PR:** the preview is removed.

After one sign-in, a preview stays unlocked in that browser for 12 hours. Only PRs from repo owners, org members and collaborators build; PRs from forks never do. Put `[skip ci]` or `[skip cd]` in the PR title or commit message to skip a build.

## Access protection per environment

Whether an app's own URL needs a Clerk login is decided by its environment, not per app. An admin sets it in **Settings → Access protection**, one switch per environment.

![Access protection: one switch per environment](/images/developer-guide/access-protection.jpg)

| Environment | Switch | Result |
| --- | --- | --- |
| `staging`, `preview-test` | On | Every app in it needs a Clerk login from a team member, or a guest who has that project |
| `production` | Off | Apps are public |

PR previews need a Clerk login in every environment. A switch change reaches an app on its next deploy.

## Promoting from staging to production

Test in staging first, then copy the app to production.

![Promoting an app from staging to production](/images/developer-guide/promote-flow.svg)

1. App → **Resource operations** → **Clone to another environment** → pick the project and **production**.
2. Open the new app in production and change its **domain** (the clone keeps staging's URL) and any **environment variables** that differ (database URLs, API keys).
3. **Deploy** it and check the production URL.
4. Once production works, delete the staging copy (app → **Danger zone → Delete**).

![Resource operations: clone to another environment, or move](/images/developer-guide/resource-operations.jpg)

**Move resource** on the same page moves the app itself instead of copying it. The app's Clerk protection only updates after its labels are reset (General → Container labels → reset) and it's redeployed. Clone, move and delete need the Admin role, except that members may delete apps they created.

## Inviting people

Everyone who signs in with Clerk with an @availproject.org email joins Avail Team automatically. Admins invite everyone else by email address:

![Invite a member: email address, role (Guest by default), access duration, projects and Generate Link](/images/developer-guide/invite.jpg)

1. **Team → Members → Invite a member**: enter the email address and pick a role. The role starts as **Guest**.
2. For a guest, pick how long access lasts (30, 60 or 90 days, a custom end date, or no expiry) and tick the projects they may see. The time starts when they accept.
3. Click **Generate Link** and send the link yourself (Slack, email). Email delivery from AvailCoolify isn't set up yet.
4. The person opens the link, signs in with Clerk as that email address and accepts.

**Managing guests:** **Settings → Guest access** shows every guest with a tick box per project and the date their access ends (amber in the last 7 days, red once ended). **Manage** extends access, sets a new end date, removes the expiry or removes the guest. Each project's settings page also lists its guests. When access ends, the guest sees "Your guest access has ended" until an admin extends it; their project ticks are kept. Once email is configured, guests get an email 7 days before and when their access ends.

**Removing people:** removing someone under **Team → Members** sticks. An @availproject.org user you remove doesn't rejoin on their next sign-in; they're listed under **Removed**, and **Re-invite** lets them back in.

## Environment variables and secrets

Set variables per app under **app → Environment Variables**; they apply on the next deploy, not to the running container. You can add, change and delete the variables of apps you created (for example an API key your app needs), and see their values; other apps' variables need the Admin role.

| Option | What it does |
| --- | --- |
| Runtime | Available to the running app (on by default) |
| Build time | Available while building, e.g. for frontend API URLs baked into the bundle (on by default) |
| Build secrets | Passed to the build as secrets, not left in the image |
| Preview deployments | Separate values used only by PR previews, e.g. a staging API key |

- **Shared variables:** an admin defines a value once under **Shared Variables** at team, project or environment level and references it in an app as `{{team.KEY}}`, `{{project.KEY}}` or `{{environment.KEY}}` instead of pasting the secret into every app. Only admins can add such references; ask one if your app needs a shared value.
- **Previews:** give previews their own values (test databases, sandbox keys) so a PR never writes to production data.
- **Never commit secrets** to the repo; keep them here.

## Logs, terminal and troubleshooting

Start with the deployment log for build problems and **Runtime Logs** for problems while the app runs.

- **Deployment log:** app → **Deployment Logs** → pick the run.
- **Runtime logs:** app → **Runtime Logs**. Live stdout/stderr of the running app. They can contain secrets, so members only see them for apps they created; admins see all. Guests see deployment logs only.
- **Web terminal:** app → **Terminal** opens a shell inside the container (Admin role).

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| Build fails | Missing dependency or build variable | Read the deployment log; add the variable with Build time on |
| Deploy succeeds, URL shows 404 or 502 | App listens on a different port | Set **Ports Exposes** to the port the app listens on, then redeploy |
| App keeps restarting, then stops | App crashes on start | Check Runtime Logs; the restart limit stops it after repeated crashes |
| Repo missing in the GitHub picker | App not installed on that account, or no push access | **Adjust repository access**, or ask the repo owner for write access |
| Deploy refused: "GitHub source disconnected" | The GitHub app was uninstalled or suspended on that account | Reinstall it on GitHub, then deploy again |
| Preview shows 403 | You aren't in Avail Team, or you're a guest without that project | Sign in with the right Clerk account, or ask an admin |
| "Waiting for an invitation" after sign-in | Your email isn't @availproject.org and you haven't accepted an invitation | Ask an admin for an invitation link |
| "Your guest access has ended" | Your guest access date passed | Ask an admin to extend it |
| Preview never appears | PR from a fork or a non-collaborator, or `[skip ci]` in the title | Push the branch to the main repo; remove the skip tag |
| Variable change not visible | Variables apply on deploy | Redeploy the app |

## Rules and gotchas

The platform itself is managed by the Owner; developers manage their own apps.

- **Security and domain changes need a redeploy** to reach the running app (access protection, domains, ports, labels).
- **Everything runs on one server** (8 GB RAM) shared by builds and all apps. Avoid parallel heavy builds, and ask before adding memory-hungry services.
- **Backups aren't set up yet.** Don't keep data you can't lose in app databases on this platform for now.
- **Docs:** the upstream [Coolify docs](https://coolify.io/docs) (account menu → Documentation) cover the general features; this guide covers what's specific to us.

AvailCoolify is our self-hosted deploy platform, a customised Coolify. Push to GitHub and it builds and runs your app; open a pull request and it spins up a private preview.

- **Sign-in:** Clerk only (the same Clerk account as HQ). There is no password login.
- **Platform owner:** Anuj Agnihotri (anuj@availproject.org). Ask for access, new projects or anything broken.

## Getting access

Sign in once with Clerk, then ask Anuj to add you to the team. Until then you land in an empty personal team and can't see any real projects.

1. Open the dashboard and click **Login with Clerk**. You always get a fresh sign-in screen, so pick the right account.
2. Your account is created on first login, in its own empty team.
3. Ask Anuj to invite you to **Root Team** (Team → Members → Invite). Accept the invite while signed in.
4. Switch to Root Team with the team switcher at the top of the sidebar.

| Role | Can do |
| --- | --- |
| Member | View apps, deployments and logs (read-only) |
| Admin | Deploy, restart, edit apps and env vars, web terminal, manage team members |
| Owner | Everything an admin can, plus team-level ownership |

Anyone who deploys needs **Admin**; give **Member** to people who only need to look. Two-factor is handled by Clerk, not in the profile.

## Deploying an app

Push to the app's branch and it redeploys automatically. Everything lives under **Root Team → Pilot → production** today.

- **Source:** New resource → **Git Repository (with GitHub App)** → **Continue with GitHub** (once), then pick an account or organisation. You see every repo there you can push to. If an account or org is missing, an admin uses **+ Add GitHub account or organisation**; if a repo is missing, use **Adjust repository access**.
- **Auto-deploy:** a push to the app's branch (e.g. `main`) starts a build within seconds.
- **Manual deploy:** open the app → **Deploy** (or **Redeploy**). Use **Restart** when only the container needs restarting, not a rebuild.
- **Build:** apps build with Railpack, which detects the language from the repo. A Dockerfile or Docker Compose file can be used instead (app → Configuration → Build Pack).
- **New app:** Project → **+ New** → pick the repo, branch and build pack, then set the domain. Deploying needs the Admin role.

App URLs currently look like `http://<id>.167.235.69.240.sslip.io` (plain HTTP). Proper domains with HTTPS are a planned improvement.

## Preview deployments

Every pull request gets its own private preview URL. PR previews always sit behind a Clerk login that only lets members of the app's team in.

1. **Open a PR** against the app's repo. AvailCoolify builds the PR branch and comments on the PR with the preview link.
2. **Preview URL:** `http://<PR number>.<app id>.167.235.69.240.sslip.io`, e.g. PR 7 → `7.onx5amwtnwf1hwwqzfmelnb7.167.235.69.240.sslip.io`.
3. **Push more commits** to the PR: the preview rebuilds.
4. **Close or merge the PR:** the preview is removed.

Opening a preview URL: if you aren't signed in, you're sent to the Clerk sign-in first. Members of the app's team then see the preview; anyone else gets a 403 page ("You don't have enough privileges to access this page").

After one sign-in, a preview stays unlocked in that browser for 12 hours. Only PRs from repo owners, org members and collaborators build; PRs from forks never do. Put `[skip ci]` or `[skip cd]` in the PR title or commit message to skip a build.

## Access protection per environment

Whether an app's own URLs need a Clerk login is decided by its environment, not per app. An admin sets it in **Settings → Access protection**, one switch per environment.

| Environment type | Switch | Result |
| --- | --- | --- |
| Testing, e.g. `staging`, `preview-test` | On | Every app in it needs a Clerk login from a member of its team |
| `production` | Off | Apps are public |

Deploy to the testing environment first, check it there, then promote to production. PR previews need a Clerk login in every environment. A switch change reaches an app on its next deploy.

## Environment variables and secrets

Set variables per app under **app → Environment Variables**; they apply on the next deploy, not to the running container.

| Option | What it does |
| --- | --- |
| Runtime | Available to the running app (on by default) |
| Build time | Available while building, e.g. for frontend API URLs baked into the bundle (on by default) |
| Build secrets | Passed to the build as secrets, not left in the image |
| Preview deployments | Separate values used only by PR previews, e.g. a staging API key |

- **Shared variables:** define a value once under **Shared Variables** at team, project or environment level. Reference it in an app as `{{team.KEY}}`, `{{project.KEY}}` or `{{environment.KEY}}` instead of pasting the secret into every app.
- **Previews:** give previews their own values (test databases, sandbox keys) so a PR never writes to production data.
- **Never commit secrets** to the repo; keep them here. Changing a variable needs the Admin role.

## Logs, terminal and troubleshooting

Start with the deployment log for build problems and the Logs tab for runtime problems.

- **Deployment log:** app → **Deployments** → pick the run. Shows every build and start step.
- **Container logs:** app → **Logs**. Live stdout/stderr of the running app.
- **Web terminal:** app → **Terminal** opens a shell inside the container (Admin role).

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| Build fails | Missing dependency or build variable | Read the deployment log; add the variable with Build time on |
| Deploy succeeds, URL shows 404 or 502 | App listens on a different port | Set **Ports Exposes** to the port the app listens on, then redeploy |
| App keeps restarting, then stops | App crashes on start | Check Logs; the restart limit stops it after repeated crashes |
| Preview shows 403 | You aren't on the app's team | Ask Anuj to add you to Root Team |
| Preview never appears | PR from a fork or a non-collaborator, or `[skip ci]` in the title | Push the branch to the main repo; remove the skip tag |
| Variable change not visible | Variables apply on deploy | Redeploy the app |

## Rules and gotchas

The platform itself is managed by Anuj; developers manage their own apps.

- **Never click Upgrade** if an upgrade banner appears. AvailCoolify runs a custom build; the official upgrade would overwrite it. Upgrades are done by Anuj.
- **Security and domain changes need a redeploy** to reach the running app (Authentication, domains, ports, labels).
- **Everything runs on one server** (8 GB RAM) shared by builds and all apps. Avoid parallel heavy builds, and ask before adding memory-hungry services.
- **Backups aren't set up yet.** Don't keep data you can't lose in app databases on this platform for now.
- **Docs:** the upstream [Coolify docs](https://coolify.io/docs) (account menu → Documentation) cover the general features; this guide covers what's specific to us.

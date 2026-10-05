# Production pipeline

`.github/workflows/avail-production.yml` automates the machine part of the production go-live
checklist. It runs on the fork (`anuj-3398/availcoolify`) from the `avail` branch.

| Trigger | What runs |
| --- | --- |
| Pull request to `avail` | Tests (Avail suite, shellcheck, `php -l`) |
| Push to `avail` | Tests, then build and push `ghcr.io/<owner>/availcoolify:custom-<sha9>` (label `coolify.managed=true`) |
| Manual: **build** | Same as a push |
| Manual: **deploy** | Build HEAD, deploy it to the control plane, configure the instance, smoke checks |
| Manual: **provision** | Everything from bare VMs: harden hosts, Docker, `/data/coolify`, `.env` for the managed Postgres, Cloudflare, deploy, configure, smoke checks |
| Manual: **rollback** | Redeploy an earlier image (`image_tag`, e.g. `custom-06e5e21b9`); compose files come from that commit |

`deploy`, `provision` and `rollback` use the GitHub environment **production**. Add required reviewers
there so nothing reaches production without an approval.

## Before the first run (what the pipeline cannot do)

1. Create the VMs (control plane 2 vCPU/4 GB; app server(s) 4–8 vCPU/16 GB), Ubuntu 24.04, with your
   public key in `/root/.ssh/authorized_keys`. The provider firewall must allow SSH on **both** 22 and the
   new port (`PROD_SSH_PORT`) during provisioning, and 80/443 from the internet. Keep 8000, 6001, 6002,
   5432 and 6379 closed. Docker-published ports bypass `ufw`, so this has to be the provider firewall.
2. Create the managed Postgres 15+ (same region, database `coolify`, allowlist the control plane only,
   PITR 7+ days). Have its CA certificate ready.
3. Cloudflare zone for the domain; buy **Advanced Certificate Manager** (Total TLS fails without it).
4. Register the OAuth app in Avail's **production** Clerk instance (redirect
   `https://<dashboard>/auth/clerk/callback`) and keep client id, secret and the instance base URL.
5. Create the GitHub App under the `availproject` org, install it on the repos, then after the first
   deploy add it under Sources and run `php artisan avail:github-platform <id>` in the container.
   Its webhook must leave **Workflow job** off.

## Secrets (environment `production`)

| Secret | Used for |
| --- | --- |
| `PROD_SSH_PRIVATE_KEY` | SSH as root to all servers |
| `PROD_SSH_KNOWN_HOSTS` | optional but recommended: pinned host keys (`ssh-keyscan -p 22 <ip>`). Without it the first connection is trusted |
| `PROD_DB_HOST`, `PROD_DB_PASSWORD` | managed Postgres |
| `PROD_DB_CA_CERT` | the provider's CA, PEM text; written to `/data/coolify/ssh/db-ca.pem` |
| `PROD_APP_KEY` | optional: reuse an existing `APP_KEY` (restore drill). Empty = generated on first provision |
| `PROD_CLERK_CLIENT_ID`, `PROD_CLERK_CLIENT_SECRET` | Clerk OAuth app |
| `PROD_CLOUDFLARE_API_TOKEN` | Zone:Read, DNS:Edit, Zone Settings:Edit, Cache Rules:Edit, Config Rules:Edit, Zone WAF:Edit, SSL and Certificates:Edit |

## Variables (environment `production`)

| Variable | Example | Notes |
| --- | --- | --- |
| `PROD_CONTROL_PLANE_HOST` | `203.0.113.10` | IP (also used for the Cloudflare record) |
| `PROD_APP_SERVER_HOSTS` | `203.0.113.20 203.0.113.21` | space-separated IPs; the first gets the `*.apps` record |
| `PROD_DASHBOARD_URL` | `https://deploy.example.com` | |
| `PROD_APPS_WILDCARD` | `https://apps.example.com` | default wildcard for new apps |
| `PROD_CF_ZONE` | `example.com` | Cloudflare zone name |
| `PROD_ROOT_USER_EMAIL` | `owner@example.com` | seeded as the first admin; their first Clerk login becomes the owner of Avail Team |
| `PROD_CLERK_BASE_URL` | `https://clerk.example.com` | required since 4.4.0, otherwise login is refused |
| `PROD_SSH_PORT` | `58122` | default 58122 |
| `PROD_INITIAL_SSH_PORT` | `22` | port a fresh VM answers on |
| `PROD_DB_PORT`, `PROD_DB_NAME`, `PROD_DB_USER`, `PROD_DB_SSLMODE` | `25060`, `coolify`, `coolify`, `verify-full` | defaults 5432, coolify, coolify, verify-full |
| `PROD_AUTO_JOIN_DOMAINS` | `availproject.org` | default `availproject.org` |

## Scripts

| Script | Runs where | Does |
| --- | --- | --- |
| `bootstrap-host.sh` | on each VM | key-only SSH on the new port (two phases so you can't lock yourself out), `fail2ban` on that port, `MaxStartups 30:60:200`, unattended upgrades, swap, Docker with 10m×3 log rotation; control plane also `/data/coolify` layout, the `coolify` network, Coolify's localhost key and `.env` (existing `.env` and `APP_KEY` are never overwritten) |
| `deploy-release.sh` | control plane | checks the managed DB is reachable, records the previous image, pulls from GHCR, runs `scripts/deploy-custom.sh <image>` |
| `configure-instance.php` | inside the `coolify` container | auto-update off, sponsorship popup off, registration on, force HTTPS, instance URL, daily Docker cleanup, wildcard domain, SSH port of the local server, Clerk settings, Cloudflare ranges as Traefik trusted IPs. `DRY_RUN=1` shows changes without saving |
| `cloudflare-setup.sh` | the runner | proxied DNS, Full (strict) rule for the two hostnames, Total TLS, cache bypass, rate limit |
| `smoke-check.sh` | the runner | health, login page, HTTP→HTTPS, websocket 101, no password form |
| `ci-lib.sh` | the runner | SSH helpers; secrets travel as a 0600 file that is deleted after use, never on a command line |

`scripts/deploy-custom.sh` now also writes a compose overlay that disables the bundled `postgres`
service whenever `DB_HOST` in `.env` is not `postgres`. The testing VM has no `DB_HOST`, so it is unchanged.

## Not automated

- Buying ACM, WAF managed rulesets, restricting 80/443 to Cloudflare's ranges (do it in the provider firewall).
- Adding app servers in Coolify (Servers → Add needs a private key created in the UI), their proxy trusted IPs
  (re-run **deploy** after adding one) and their wildcard domain if it differs.
- Custom app domains on the company zone (grey cloud A records), SigNoz and its collectors, email, notification
  channels, S3 backup storage, the restore drill, MFA in Clerk and the break-glass test.

## Rollback

Run the workflow with **rollback** and the previous tag (the summary of every deploy prints it). Migrations are
not undone; use the provider's point-in-time restore if a migration has to be reverted.

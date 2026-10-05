#!/usr/bin/env bash
# Run on the control plane by the CI "deploy" job. Pulls a prebuilt image and starts it through
# scripts/deploy-custom.sh (same compose layering and rollback story as the testing VM).
#
#   deploy-release.sh <image-ref>        e.g. ghcr.io/anuj-3398/availcoolify:custom-06e5e21b9
#
# The CI job rsyncs the release files (compose files, config/constants.php, scripts/) into
# RELEASE_DIR first; GHCR_USER / GHCR_TOKEN let this host pull a private image.
set -euo pipefail

IMAGE="${1:?usage: deploy-release.sh <image-ref>}"
RELEASE_DIR="${RELEASE_DIR:-/root/availcoolify-release}"
SOURCE=/data/coolify/source
ENV_FILE="$SOURCE/.env"
STAMP="$(date +%Y%m%d-%H%M%S)"

log() { printf '\n==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[ -f "$ENV_FILE" ] || die "$ENV_FILE missing; run the provision job first"
[ -d "$RELEASE_DIR" ] || die "$RELEASE_DIR missing"

get_env() { sed -n "s/^$1=//p" "$ENV_FILE" | head -n1; }

# The database is managed and outside this host: make sure it is reachable before touching anything.
DB_HOST="$(get_env DB_HOST)"
if [ -n "$DB_HOST" ] && [ "$DB_HOST" != "postgres" ]; then
    log "Checking managed Postgres at $DB_HOST"
    docker run --rm postgres:15-alpine pg_isready -h "$DB_HOST" -p "$(get_env DB_PORT)" -t 10 \
        || die "managed Postgres not reachable from this host (allowlist / private network?)"
fi

log "Saving the current state for rollback"
BACKUP="/root/pre-deploy-$STAMP"
mkdir -p "$BACKUP"
cp "$ENV_FILE" "$BACKUP/.env"
cp "$SOURCE"/docker-compose*.yml "$BACKUP/" 2>/dev/null || true
PREVIOUS="$(docker inspect coolify --format '{{.Config.Image}}' 2>/dev/null || true)"
echo "${PREVIOUS:-none}" >"$BACKUP/previous-image.txt"
echo "${PREVIOUS:-none}" >"$SOURCE/.avail-previous-image"
echo "Previous image: ${PREVIOUS:-none} (saved in $BACKUP)"

if [ -n "${GHCR_TOKEN:-}" ]; then
    log "Logging in to ghcr.io"
    printf '%s' "$GHCR_TOKEN" | docker login ghcr.io -u "${GHCR_USER:?GHCR_USER required with GHCR_TOKEN}" --password-stdin
fi

log "Pulling $IMAGE"
docker pull "$IMAGE"

log "Deploying"
cd "$RELEASE_DIR"
./scripts/deploy-custom.sh "$IMAGE"

# The token was only needed for the pull; do not leave it on the host.
docker logout ghcr.io >/dev/null 2>&1 || true

log "Deployed $IMAGE (rollback: re-run with ${PREVIOUS:-the previous tag})"

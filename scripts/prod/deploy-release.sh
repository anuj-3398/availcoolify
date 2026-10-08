#!/usr/bin/env bash
# Run on the control plane by the release workflow. Pulls a prebuilt image from the registry and starts
# it through scripts/deploy-custom.sh (same compose layering and rollback story as the testing VM).
#
#   deploy-release.sh <image-ref>
#       e.g. registry.digitalocean.com/availj/availcoolify:custom-06e5e21b9
#
# The workflow copies the release files (compose files, config/constants.php, scripts/) into RELEASE_DIR
# first. DOCR_TOKEN is the registry's read-only token; DigitalOcean takes an API token as both user name
# and password. It goes into a throw-away Docker config for the pull and is deleted straight after.
#
# Test seams: COOLIFY_SOURCE (default /data/coolify/source) and BACKUP_ROOT (default /root).
set -euo pipefail

IMAGE="${1:?usage: deploy-release.sh <image-ref>}"
RELEASE_DIR="${RELEASE_DIR:-/root/availcoolify-release}"
SOURCE="${COOLIFY_SOURCE:-/data/coolify/source}"
BACKUP_ROOT="${BACKUP_ROOT:-/root}"
ENV_FILE="$SOURCE/.env"
STAMP="$(date +%Y%m%d-%H%M%S)"
export COOLIFY_SOURCE="$SOURCE"

log() { printf '\n==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[ -f "$ENV_FILE" ] || die "$ENV_FILE missing; run the provision action first"
[ -d "$RELEASE_DIR" ] || die "$RELEASE_DIR missing"

get_env() { sed -n "s/^$1=//p" "$ENV_FILE" | head -n1; }

# "host port" of the managed database, from DATABASE_URL (postgres://user:pass@host:port/db?...) or, on
# older installs, DB_HOST / DB_PORT. Prints nothing when the database is the bundled one.
db_endpoint() {
    local url rest hostport host port
    url="$(get_env DATABASE_URL)"
    if [ -n "$url" ]; then
        rest="${url#*://}"      # user:pass@host:port/db?...
        rest="${rest#*@}"       # host:port/db?...
        hostport="${rest%%[/?]*}"
        host="${hostport%:*}"
        port="${hostport##*:}"
        [ "$host" != "$hostport" ] || port=5432
        echo "$host ${port:-5432}"
        return
    fi
    host="$(get_env DB_HOST)"
    if [ -n "$host" ] && [ "$host" != postgres ]; then
        echo "$host $(get_env DB_PORT)"
    fi
}

# The database is managed and outside this host: make sure it is reachable before touching anything.
endpoint="$(db_endpoint)"
if [ -n "$endpoint" ]; then
    read -r db_host db_port <<<"$endpoint"
    log "Checking managed Postgres at $db_host:${db_port:-5432}"
    docker run --rm postgres:15-alpine pg_isready -h "$db_host" -p "${db_port:-5432}" -t 10 \
        || die "managed Postgres not reachable from this host (allowlist / private network?)"
fi

log "Saving the current state for rollback"
BACKUP="$BACKUP_ROOT/pre-deploy-$STAMP"
mkdir -p "$BACKUP"
cp "$ENV_FILE" "$BACKUP/.env"
chmod 600 "$BACKUP/.env"
cp "$SOURCE"/docker-compose*.yml "$BACKUP/" 2>/dev/null || true
PREVIOUS="$(docker inspect coolify --format '{{.Config.Image}}' 2>/dev/null || true)"
echo "${PREVIOUS:-none}" >"$BACKUP/previous-image.txt"
echo "${PREVIOUS:-none}" >"$SOURCE/.avail-previous-image"
echo "Previous image: ${PREVIOUS:-none} (saved in $BACKUP)"

log "Pulling $IMAGE"
REGISTRY_HOST="${IMAGE%%/*}"
if [ -n "${DOCR_TOKEN:-}" ] && [[ "$REGISTRY_HOST" == *.* ]]; then
    DOCKER_CONFIG_DIR="$(mktemp -d)"
    trap 'rm -rf "$DOCKER_CONFIG_DIR"' EXIT
    auth="$(printf '%s:%s' "$DOCR_TOKEN" "$DOCR_TOKEN" | base64 -w0)"
    printf '{"auths":{"%s":{"auth":"%s"}}}' "$REGISTRY_HOST" "$auth" >"$DOCKER_CONFIG_DIR/config.json"
    DOCKER_CONFIG="$DOCKER_CONFIG_DIR" docker pull "$IMAGE"
    rm -rf "$DOCKER_CONFIG_DIR"
else
    docker pull "$IMAGE"
fi

log "Deploying"
cd "$RELEASE_DIR"
./scripts/deploy-custom.sh "$IMAGE"

log "Deployed $IMAGE (rollback: re-run with ${PREVIOUS:-the previous tag})"

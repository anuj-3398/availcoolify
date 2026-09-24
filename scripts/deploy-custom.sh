#!/usr/bin/env bash
# Build the checked-out branch (normally `custom`) into a local Coolify image and run it
# in place of the official one. Survives restarts, container recreation and reboots
# because Coolify's compose setup always includes docker-compose.custom.yml.
#
# Usage:  scripts/deploy-custom.sh            build HEAD and deploy it
#         scripts/deploy-custom.sh <tag>      redeploy an already-built image (rollback)
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
SOURCE=/data/coolify/source
STAMP="$(date +%Y%m%d-%H%M%S)"

cd "$REPO"

if [ $# -ge 1 ]; then
    TAG="$1"
    docker image inspect "$TAG" >/dev/null
else
    if [ -n "$(git status --porcelain)" ]; then
        echo "Working tree is dirty; commit or stash first." >&2
        exit 1
    fi
    TAG="availcoolify:custom-$(git rev-parse --short HEAD)"
    echo "Building $TAG from $(git rev-parse --abbrev-ref HEAD)"
    docker build -f docker/production/Dockerfile -t "$TAG" .
fi

# The compose files ship with the source tree and change between versions
# (e.g. the realtime server moved into the coolify container), so keep them in sync.
mkdir -p "$SOURCE/backups-compose/$STAMP"
cp "$SOURCE"/docker-compose*.yml "$SOURCE/backups-compose/$STAMP/"
cp docker-compose.yml docker-compose.prod.yml "$SOURCE/"

cat >"$SOURCE/docker-compose.custom.yml" <<EOF
# Managed by availcoolify scripts/deploy-custom.sh — runs the Avail custom build.
services:
  coolify:
    image: "$TAG"
EOF

docker compose --env-file "$SOURCE/.env" \
    -f "$SOURCE/docker-compose.yml" \
    -f "$SOURCE/docker-compose.prod.yml" \
    -f "$SOURCE/docker-compose.custom.yml" \
    up -d --remove-orphans --wait --wait-timeout 300

echo "Coolify is running $TAG"
echo "Previous compose files: $SOURCE/backups-compose/$STAMP"

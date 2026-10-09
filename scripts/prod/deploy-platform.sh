#!/usr/bin/env bash
# Run on every VM by the release workflow: start the log and metrics shipper (Grafana Alloy -> SigNoz) from
# the copy of docker/avail-otel that the workflow put in RELEASE_DIR. Idempotent: re-running updates it in place.
#
# Environment (the workflow sends it as a 0600 env file):
#   OTEL_EXPORTER_OTLP_ENDPOINT   SigNoz OTLP/HTTP endpoint (it takes no key). Without it nothing is started.
#   HOST_NAME                     host_name attribute on every log line and metric (default: this host's name)
#   DEPLOYMENT_ENVIRONMENT        deployment_environment attribute (default: production)
#   PLATFORM_DIR                  where the compose project lives (default /data/coolify/platform/otel)
set -euo pipefail

RELEASE_DIR="${RELEASE_DIR:-/root/availcoolify-release}"
SRC="$RELEASE_DIR/docker/avail-otel"
DEST="${PLATFORM_DIR:-/data/coolify/platform/otel}"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

if [ -z "${OTEL_EXPORTER_OTLP_ENDPOINT:-}" ]; then
    echo "OTEL_EXPORTER_OTLP_ENDPOINT is not set: log shipper not started"
    exit 0
fi
[ -f "$SRC/docker-compose.yml" ] && [ -f "$SRC/config.alloy" ] || die "$SRC is missing (the workflow copies it from the repo)"

install -d -m 700 "$DEST"
cp "$SRC/docker-compose.yml" "$SRC/config.alloy" "$DEST/"
(
    umask 077
    {
        printf 'OTEL_EXPORTER_OTLP_ENDPOINT=%s\n' "$OTEL_EXPORTER_OTLP_ENDPOINT"
        printf 'HOST_NAME=%s\n' "${HOST_NAME:-$(hostname)}"
        printf 'DEPLOYMENT_ENVIRONMENT=%s\n' "${DEPLOYMENT_ENVIRONMENT:-production}"
    } >"$DEST/.env"
)

cd "$DEST"
docker compose -p avail-platform up -d --remove-orphans

sleep 3
[ "$(docker inspect -f '{{.State.Running}}' avail-log-shipper 2>/dev/null || true)" = true ] || die "avail-log-shipper is not running"
echo "Log shipper running on $(hostname) (host_name=${HOST_NAME:-$(hostname)})"

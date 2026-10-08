#!/usr/bin/env bash
# Read every parameter under an AWS SSM path into a file a shell can source.
#
#   ssm-load.sh <path-prefix> <outfile>          e.g. ssm-load.sh /avail/coolify/prod/ "$RUNNER_TEMP/ssm.env"
#
# The last segment of each parameter name becomes the variable name (DATABASE_URL, CLERK_CLIENT_ID, ...).
# Values are shell-quoted, so multi-line ones such as a CA certificate survive, and on GitHub Actions every
# value is registered as a mask so it never shows up in a log. The file is created with mode 0600.
# Needs the AWS CLI with credentials (the workflow assumes a role first) and jq.
set -euo pipefail

PREFIX="${1:?usage: ssm-load.sh <path-prefix> <outfile>}"
OUT="${2:?usage: ssm-load.sh <path-prefix> <outfile>}"
case "$PREFIX" in /*) ;; *) echo "ssm-load: the path prefix must start with /" >&2; exit 2 ;; esac
PREFIX="${PREFIX%/}/"

json="$(aws ssm get-parameters-by-path --path "$PREFIX" --recursive --with-decryption \
    --query 'Parameters[].[Name,Value]' --output json)"

count="$(jq 'length' <<<"$json")"
if [ "$count" -eq 0 ]; then
    echo "ssm-load: no parameters under $PREFIX (wrong prefix, region or role?)" >&2
    exit 1
fi

if [ "${GITHUB_ACTIONS:-}" = true ]; then
    # Masks apply per line. '%' must be escaped in workflow commands. Very short lines would mangle the log.
    jq -r '.[][1] | split("\n")[] | gsub("\r"; "") | select(length >= 4)' <<<"$json" | while IFS= read -r line; do
        echo "::add-mask::${line//%/%25}"
    done
fi

valid='^[A-Za-z_][A-Za-z0-9_]*$'
skipped="$(jq -r --arg re "$valid" '.[] | (.[0] | split("/") | last) | select(test($re) | not)' <<<"$json")"
[ -z "$skipped" ] || echo "::warning::ssm-load: ignoring parameters whose last path segment is not a valid variable name: $(echo "$skipped" | paste -sd' ' -)" >&2

umask 077
jq -r --arg re "$valid" '.[] | (.[0] | split("/") | last) as $name | select($name | test($re)) | "\($name)=\(.[1] | @sh)"' <<<"$json" >"$OUT"

echo "ssm-load: $count parameters from $PREFIX -> $OUT"
jq -r '.[][0] | split("/") | last' <<<"$json" | sort | sed 's/^/  /'

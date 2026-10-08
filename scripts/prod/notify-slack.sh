#!/usr/bin/env bash
# Post the result of a release run to Slack. Never fails the job.
#
#   ACTION=deploy JOB_STATUS=success IMAGE_REF=... scripts/prod/notify-slack.sh
#
# The incoming webhook is the SSM parameter SLACK_WEBHOOK_URL (read from the file ssm-load.sh wrote).
# Without it, or without that file, this does nothing.
set -uo pipefail

SSM_ENV_FILE="${SSM_ENV_FILE:-${RUNNER_TEMP:-/tmp}/ssm.env}"
[ -f "$SSM_ENV_FILE" ] || { echo "slack: no secrets file, nothing to post"; exit 0; }

# A subshell, so only this one value is picked out of the file.
# shellcheck disable=SC1090
SLACK_WEBHOOK_URL="$( . "$SSM_ENV_FILE" >/dev/null 2>&1; printf '%s' "${SLACK_WEBHOOK_URL:-}" )"
[ -n "$SLACK_WEBHOOK_URL" ] || { echo "slack: SLACK_WEBHOOK_URL is not in SSM, nothing to post"; exit 0; }

case "${JOB_STATUS:-}" in
    success) icon=":white_check_mark:" ;;
    cancelled) icon=":no_entry_sign:" ;;
    *) icon=":x:" ;;
esac
run_url="${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY:-}/actions/runs/${GITHUB_RUN_ID:-}"
text="$icon AvailCoolify production *${ACTION:-deploy}*: ${JOB_STATUS:-unknown}"$'\n'"Image: \`${IMAGE_REF:-n/a}\` by ${GITHUB_ACTOR:-unknown} - <${run_url}|run>"
payload="$(jq -nc --arg text "$text" '{text: $text}')"

# The webhook URL goes in on stdin, not on the command line.
printf 'url = "%s"\n' "$SLACK_WEBHOOK_URL" |
    curl -sS --max-time 15 -K - -H 'Content-Type: application/json' --data "$payload" -o /dev/null -w 'slack: HTTP %{http_code}\n' ||
    echo "slack: request failed"
exit 0

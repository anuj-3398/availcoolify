#!/usr/bin/env bash
# Post-deploy checks against the public dashboard URL (go-live check, item 1).
#   smoke-check.sh https://deploy.example.com [ip]
# With an IP, the dashboard's host name is pinned to it (curl --resolve) instead of looked up in DNS: for
# throwaway VMs before their DNS record exists. TLS is not verified then, because without DNS Traefik has
# no Let's Encrypt certificate yet.
set -uo pipefail

DASH="${1:?usage: smoke-check.sh <dashboard url> [ip]}"
DASH="${DASH%/}"
PIN_IP="${2:-}"
fail=0

CURL_OPTS=()
if [ -n "$PIN_IP" ]; then
    host="${DASH#*://}"
    host="${host%%/*}"
    CURL_OPTS=(--resolve "$host:443:$PIN_IP" --resolve "$host:80:$PIN_IP" --insecure)
    echo "Checking $DASH on $PIN_IP (DNS bypassed, TLS not verified)"
fi

check() { # check <name> <expected-regex> <actual>
    if [[ "$3" =~ $2 ]]; then printf 'ok    %-34s %s\n' "$1" "$3"; else printf 'FAIL  %-34s %s (want %s)\n' "$1" "$3" "$2"; fail=1; fi
}

retry() { # retry <attempts> <cmd...>: wait for the freshly started container behind the proxy
    local n="$1"; shift
    local out
    for _ in $(seq "$n"); do out="$("$@")"; [ -n "$out" ] && [ "$out" != "000" ] && { echo "$out"; return; }; sleep 5; done
    echo "${out:-000}"
}

code() { curl -s "${CURL_OPTS[@]}" -o /dev/null -w '%{http_code}' --max-time 15 "$@"; }

check "health"            '^200$' "$(retry 24 code "$DASH/api/health")"
check "login page"        '^200$' "$(code "$DASH/login")"
check "http -> https"     '^30[1278]$' "$(code "http://${DASH#https://}/login")"
# HTTP/1.1 only: curl over HTTP/2 through Cloudflare reports 500 for the Upgrade request, which is normal.
check "websocket upgrade" '^101$' "$(curl -s "${CURL_OPTS[@]}" --http1.1 -o /dev/null -w '%{http_code}' --max-time 5 \
    -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' \
    -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' "$DASH/app/smoke" || true)"
check "static asset"       '^200$' "$(code "$DASH/images/developer-guide/dashboard.jpg")"
# Without a session the dashboard must send people to Clerk, never show a password form.
check "no password form"  '^0$' "$(curl -s "${CURL_OPTS[@]}" --max-time 15 "$DASH/login" | grep -c 'name="password"')"

exit "$fail"

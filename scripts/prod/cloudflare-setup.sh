#!/usr/bin/env bash
# Cloudflare side of the go-live checklist ("Domains, TLS and CDN"), via the API. Idempotent.
#
#   CF_API_TOKEN=... CF_ZONE=example.com DASHBOARD_HOST=deploy.example.com \
#   APPS_HOST=apps.example.com CONTROL_PLANE_IP=1.2.3.4 APP_SERVER_IP=5.6.7.8 \
#   scripts/prod/cloudflare-setup.sh            (DRY_RUN=1 prints the requests without sending writes)
#
# What it does:
#   - DNS (proxied): DASHBOARD_HOST -> control plane, *.APPS_HOST -> app server
#   - Full (strict) TLS for those two hostnames only, as a Configuration Rule (zone setting untouched)
#   - Total TLS (needs Advanced Certificate Manager; a warning, not a failure, when it isn't bought)
#   - Cache bypass for /livewire/*, /app, /auth/*, /webhooks/*, /preview-guard/* on the dashboard
#   - One rate-limit rule for /auth/* and /webhooks/*
# Not done here: buying ACM, WAF managed rulesets (plan dependent), DNS-only records for custom
# app domains such as fastbridge.<company domain> (they need access to that zone).
#
# Token permissions: Zone:Read, DNS:Edit, Zone Settings:Edit, Cache Rules:Edit, Config Rules:Edit,
# Zone WAF:Edit (rate limiting), SSL and Certificates:Edit (Total TLS).
#
# The rule phases are replaced as a whole by the API, so this script only touches rules whose ref
# starts with "avail-" and keeps every other rule in the same phase.
set -euo pipefail

: "${CF_API_TOKEN:?}" "${CF_ZONE:?}" "${DASHBOARD_HOST:?}" "${APPS_HOST:?}" "${CONTROL_PLANE_IP:?}" "${APP_SERVER_IP:?}"
DRY_RUN="${DRY_RUN:-0}"
API=https://api.cloudflare.com/client/v4
WARN=0

log() { printf '\n==> %s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; WARN=1; }

cf() { # cf <method> <path> [json-body] -> prints the response, returns non-zero on API failure
    local method="$1" path="$2" body="${3:-}" out
    if [ "$DRY_RUN" = 1 ] && [ "$method" != GET ]; then
        echo "DRY-RUN $method $path ${body:0:300}" >&2
        echo '{"success":true,"result":{}}'
        return 0
    fi
    if [ -n "$body" ]; then
        out="$(curl -sS -X "$method" "$API$path" -H "Authorization: Bearer $CF_API_TOKEN" -H 'Content-Type: application/json' --data "$body")"
    else
        out="$(curl -sS -X "$method" "$API$path" -H "Authorization: Bearer $CF_API_TOKEN")"
    fi
    echo "$out"
    [ "$(echo "$out" | jq -r '.success')" = true ]
}

errors() { jq -r '[.errors[]?.message] | join("; ")'; }

log "Zone $CF_ZONE"
ZONE_ID="$(cf GET "/zones?name=$CF_ZONE" | jq -r '.result[0].id // empty' || true)"
[ -n "$ZONE_ID" ] || { echo "zone $CF_ZONE not found or token cannot read it" >&2; exit 1; }

upsert_dns() { # upsert_dns <name> <ip>
    local name="$1" ip="$2" id body
    body="$(jq -n --arg n "$name" --arg ip "$ip" '{type:"A",name:$n,content:$ip,ttl:1,proxied:true}')"
    id="$(cf GET "/zones/$ZONE_ID/dns_records?type=A&name=$name" | jq -r '.result[0].id // empty' || true)"
    if [ -n "$id" ]; then
        cf PUT "/zones/$ZONE_ID/dns_records/$id" "$body" >/dev/null && echo "updated  A $name -> $ip (proxied)"
    else
        cf POST "/zones/$ZONE_ID/dns_records" "$body" >/dev/null && echo "created  A $name -> $ip (proxied)"
    fi
}

log "DNS"
upsert_dns "$DASHBOARD_HOST" "$CONTROL_PLANE_IP"
upsert_dns "*.$APPS_HOST" "$APP_SERVER_IP"

# upsert_rules <phase> <rules-json-array>: keep foreign rules, replace ours (ref "avail-*").
upsert_rules() {
    local phase="$1" ours="$2" current keep out
    current="$(cf GET "/zones/$ZONE_ID/rulesets/phases/$phase/entrypoint" 2>/dev/null || true)"
    keep="$(echo "$current" | jq -c '[.result.rules[]? | select((.ref // "") | startswith("avail-") | not)]' 2>/dev/null || echo '[]')"
    [ -n "$keep" ] || keep='[]'
    if out="$(cf PUT "/zones/$ZONE_ID/rulesets/phases/$phase/entrypoint" \
        "$(jq -n --argjson keep "$keep" --argjson ours "$ours" '{rules: ($keep + $ours)}')")"; then
        echo "phase $phase: $(echo "$ours" | jq length) Avail rule(s), $(echo "$keep" | jq length) other rule(s) kept"
    else
        warn "phase $phase not updated: $(echo "$out" | errors)"
    fi
}

log "TLS: Full (strict) for the dashboard and app hostnames"
upsert_rules http_config_settings "$(jq -n --arg d "$DASHBOARD_HOST" --arg a "$APPS_HOST" '[{
    ref: "avail-ssl-strict", description: "AvailCoolify: Full (strict)", action: "set_config",
    expression: "(http.host eq \"\($d)\") or (ends_with(http.host, \".\($a)\"))",
    action_parameters: {ssl: "strict"}, enabled: true }]')"

log "TLS: Total TLS (covers x.apps.<domain> and PR previews like 7.x.apps.<domain>)"
if out="$(cf POST "/zones/$ZONE_ID/acm/total_tls" '{"enabled":true,"certificate_authority":"lets_encrypt"}')"; then
    echo "Total TLS enabled"
else
    warn "Total TLS not enabled: $(echo "$out" | errors) (buy Advanced Certificate Manager first)"
fi

log "Cache: bypass dynamic dashboard paths"
upsert_rules http_request_cache_settings "$(jq -n --arg d "$DASHBOARD_HOST" '[{
    ref: "avail-cache-bypass", description: "AvailCoolify: never cache dynamic paths", action: "set_cache_settings",
    expression: "(http.host eq \"\($d)\") and (starts_with(http.request.uri.path, \"/livewire/\") or http.request.uri.path eq \"/app\" or starts_with(http.request.uri.path, \"/app/\") or starts_with(http.request.uri.path, \"/auth/\") or starts_with(http.request.uri.path, \"/webhooks/\") or starts_with(http.request.uri.path, \"/preview-guard/\"))",
    action_parameters: {cache: false}, enabled: true }]')"

log "Rate limit: /auth/* and /webhooks/*"
upsert_rules http_ratelimit "$(jq -n --arg d "$DASHBOARD_HOST" '[{
    ref: "avail-ratelimit-auth", description: "AvailCoolify: rate limit auth and webhooks", action: "block",
    expression: "(http.host eq \"\($d)\") and (starts_with(http.request.uri.path, \"/auth/\") or starts_with(http.request.uri.path, \"/webhooks/\"))",
    ratelimit: {characteristics: ["cf.colo.id", "ip.src"], period: 10, requests_per_period: 40, mitigation_timeout: 10},
    enabled: true }]')"

[ "$WARN" -eq 0 ] && log "Cloudflare configured" || log "Cloudflare configured with warnings (see above)"
exit "$WARN"

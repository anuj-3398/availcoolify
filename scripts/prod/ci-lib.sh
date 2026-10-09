#!/usr/bin/env bash
# Helpers for the release workflow (source this file; it defines functions only).
#
#   load_ssm                      export the SSM parameters that scripts/prod/ssm-load.sh wrote
#   remote <node> <command...>    run a command as root on a VM through Teleport
#   remote_env <node> <command...>
#                                 like remote, but first ships the variables named in $REMOTE_ENV to the
#                                 VM as a 0600 file that the command sources and then deletes, so secrets
#                                 never appear in a process list or the workflow log
#   push_release <node>           copy the files a deploy needs into /root/availcoolify-release
#
# VMs are reached through Teleport only (no SSH keys). <node> is the node name in Teleport. The workflow's
# teleport-actions/auth step leaves TELEPORT_IDENTITY_FILE in the environment; TELEPORT_PROXY is the proxy
# address (host:port).

RELEASE_DIR="${RELEASE_DIR:-/root/availcoolify-release}"
SSM_ENV_FILE="${SSM_ENV_FILE:-${RUNNER_TEMP:-/tmp}/ssm.env}"

load_ssm() {
    if [ ! -f "$SSM_ENV_FILE" ]; then
        echo "::error::$SSM_ENV_FILE is missing; the 'Load secrets from SSM' step did not run" >&2
        return 1
    fi
    set -a
    # shellcheck disable=SC1090
    . "$SSM_ENV_FILE"
    set +a
}

remote() {
    local node="$1"
    shift
    : "${TELEPORT_IDENTITY_FILE:?TELEPORT_IDENTITY_FILE is not set (did teleport-actions/auth run?)}"
    : "${TELEPORT_PROXY:?TELEPORT_PROXY is not set}"
    tsh --proxy="$TELEPORT_PROXY" -i "$TELEPORT_IDENTITY_FILE" ssh "root@$node" "$@"
}

remote_env() {
    local node="$1" f name
    shift
    f="/root/.avail-run-$$-$RANDOM.env"
    {
        for name in $REMOTE_ENV; do
            printf '%s=%q\n' "$name" "${!name-}"
        done
    } | remote "$node" "umask 077; cat > '$f'"
    remote "$node" "set -a; . '$f'; set +a; rm -f '$f'; $*"
}

push_release() {
    local node="$1"
    tar czf - \
        --exclude='docker/avail-otel/.env' \
        docker-compose.yml docker-compose.prod.yml .env.production \
        config/constants.php \
        scripts/deploy-custom.sh scripts/prod \
        docker/avail-otel |
        remote "$node" "rm -rf '$RELEASE_DIR' && mkdir -p '$RELEASE_DIR' && tar xzf - -C '$RELEASE_DIR' && chmod +x '$RELEASE_DIR'/scripts/deploy-custom.sh '$RELEASE_DIR'/scripts/prod/*.sh"
}

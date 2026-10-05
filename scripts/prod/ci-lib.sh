#!/usr/bin/env bash
# Helpers for the production workflow (source this file; it defines functions only).
#
#   ssh_setup               needs PROD_SSH_PRIVATE_KEY; optional PROD_SSH_KNOWN_HOSTS
#   choose_port <host>      prints the first SSH port that works: FINAL_SSH_PORT, else INITIAL_SSH_PORT
#   remote <host> <port> <command...>
#   remote_env <host> <port> <command...>
#                           like remote, but first ships the variables named in $REMOTE_ENV to the
#                           host as a 0600 file that the command sources and then deletes, so secrets
#                           never appear in a process list or the workflow log
#   push_release <host> <port>   rsync the files a deploy needs into /root/availcoolify-release

SSH_DIR="${HOME}/.ssh"
FINAL_SSH_PORT="${FINAL_SSH_PORT:-58122}"
INITIAL_SSH_PORT="${INITIAL_SSH_PORT:-22}"
RELEASE_DIR=/root/availcoolify-release

ssh_setup() {
    : "${PROD_SSH_PRIVATE_KEY:?PROD_SSH_PRIVATE_KEY is not set}"
    install -d -m 700 "$SSH_DIR"
    printf '%s\n' "$PROD_SSH_PRIVATE_KEY" >"$SSH_DIR/avail_deploy"
    chmod 600 "$SSH_DIR/avail_deploy"
    if [ -n "${PROD_SSH_KNOWN_HOSTS:-}" ]; then
        printf '%s\n' "$PROD_SSH_KNOWN_HOSTS" >"$SSH_DIR/known_hosts"
        STRICT=yes
    else
        echo "::warning::PROD_SSH_KNOWN_HOSTS is not set; trusting host keys on first use"
        STRICT=accept-new
    fi
    SSH_BASE=(-i "$SSH_DIR/avail_deploy" -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15
        -o ServerAliveInterval=15 -o StrictHostKeyChecking="$STRICT" -o UserKnownHostsFile="$SSH_DIR/known_hosts")
}

choose_port() {
    local host="$1" port
    for port in "$FINAL_SSH_PORT" "$INITIAL_SSH_PORT"; do
        if ssh "${SSH_BASE[@]}" -p "$port" "root@$host" true 2>/dev/null; then
            echo "$port"
            return 0
        fi
    done
    echo "cannot reach $host over SSH on $FINAL_SSH_PORT or $INITIAL_SSH_PORT (provider firewall? key?)" >&2
    return 1
}

remote() {
    local host="$1" port="$2"
    shift 2
    ssh "${SSH_BASE[@]}" -p "$port" "root@$host" "$@"
}

remote_env() {
    local host="$1" port="$2" f name
    shift 2
    f="/root/.avail-run-$$-$RANDOM.env"
    {
        for name in $REMOTE_ENV; do
            printf '%s=%q\n' "$name" "${!name-}"
        done
    } | ssh "${SSH_BASE[@]}" -p "$port" "root@$host" "umask 077; cat > '$f'"
    ssh "${SSH_BASE[@]}" -p "$port" "root@$host" "set -a; . '$f'; set +a; rm -f '$f'; $*"
}

push_release() {
    local host="$1" port="$2"
    remote "$host" "$port" "mkdir -p $RELEASE_DIR"
    rsync -az --delete -e "ssh ${SSH_BASE[*]} -p $port" \
        --include='/docker-compose.yml' --include='/docker-compose.prod.yml' --include='/.env.production' \
        --include='/config/' --include='/config/constants.php' \
        --include='/scripts/' --include='/scripts/deploy-custom.sh' --include='/scripts/prod/' --include='/scripts/prod/***' \
        --exclude='*' \
        ./ "root@$host:$RELEASE_DIR/"
    remote "$host" "$port" "chmod +x $RELEASE_DIR/scripts/deploy-custom.sh $RELEASE_DIR/scripts/prod/*.sh"
}

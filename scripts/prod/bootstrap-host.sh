#!/usr/bin/env bash
# Prepare a fresh Ubuntu 24.04 VM for AvailCoolify. Idempotent: safe to re-run.
#
#   bootstrap-host.sh --role control-plane|app-server
#
# People and CI reach the VM through Teleport, so the Teleport agent must already be running here: the
# script refuses to continue without it. sshd stays only for Coolify, which manages its servers over SSH:
# key-only, on SSH_PORT only (port 22 is closed), and nobody but Coolify's key may log in (see
# ssh-authorized-keys.sh). The provider firewall keeps SSH off the internet: closed on the control plane
# (Coolify reaches its own host over the Docker bridge), open to the control plane's IP on app servers.
#
# Configuration comes from the environment (the CI job sends it as a 0600 env file):
#   SSH_PORT               SSH port for Coolify (default 58122)
#   SWAP_GB                swapfile size when the host has no swap (default 4)
#   -- control plane only --
#   DATABASE_URL           postgres://user:pass@host:port/coolify (required); special characters in the
#                          password must be percent-encoded
#   DB_SSLMODE             added as ?sslmode= when the URL has none (default require)
#   DB_CA_CERT             PEM text of the provider's CA, for sslmode=verify-full / verify-ca
#   COOLIFY_APP_KEY        reuse an existing APP_KEY (restore drill); generated when empty
#   ROOT_USER_EMAIL        first instance admin; their first Clerk login becomes the owner
#   AUTO_JOIN_DOMAINS      default availproject.org
#
#   PRUNE_AUTHORIZED_KEYS  1 (default) removes every SSH key but Coolify's; 0 only lists them
#   ALLOW_SSH_WITHOUT_TELEPORT=1   escape hatch for a VM that is not in Teleport: keeps SSH on its old
#                          ports and keeps existing keys. Never set by the workflow.
#
# An existing .env is never overwritten, so the APP_KEY survives re-runs.
# Test seams: ENV_FILE, ENV_TEMPLATE and DATA_DIR; the script can be sourced without running.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SSH_PORT="${SSH_PORT:-58122}"
SWAP_GB="${SWAP_GB:-4}"
DATA_DIR="${DATA_DIR:-/data/coolify}"
ENV_FILE="${ENV_FILE:-$DATA_DIR/source/.env}"
ENV_TEMPLATE="${ENV_TEMPLATE:-/root/availcoolify-release/.env.production}"
export DEBIAN_FRONTEND=noninteractive

log() { printf '\n==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# ----------------------------------------------------------------------------- SSH
# Ports sshd really listens on (with ssh.socket this differs from sshd_config's Port).
sshd_ports() { ss -H -ltnp 2>/dev/null | grep '"sshd"' | awk '{print $4}' | sed 's/.*://' | sort -un; }

teleport_active() { systemctl is-active --quiet teleport 2>/dev/null; }

# Access is through Teleport only. Without the agent there would be no way in once sshd is restricted to
# Coolify, so refuse before changing anything.
require_teleport() {
    teleport_active && return 0
    if [ "${ALLOW_SSH_WITHOUT_TELEPORT:-0}" = 1 ]; then
        echo "WARNING: the Teleport agent is not running; SSH stays on its old ports and existing keys stay valid" >&2
        return 0
    fi
    die "the Teleport agent (service 'teleport') is not running on this host. Enrol the VM in Teleport first: CI and people reach it only that way."
}

configure_ssh() {
    local ports
    if teleport_active; then
        # sshd only has to serve Coolify, on the one port.
        ports="$SSH_PORT"
    else
        # Only reachable with ALLOW_SSH_WITHOUT_TELEPORT=1 (see require_teleport): do not cut off whatever access exists.
        [ -s /root/.ssh/authorized_keys ] || die "no Teleport agent and /root/.ssh/authorized_keys is empty; refusing to disable password login"
        ports="$( { sshd_ports; echo 22; echo "$SSH_PORT"; } | sort -un | tr '\n' ' ')"
    fi

    log "SSH: hardening (key-only, MaxStartups 30:60:200) and ports: $ports"
    install -d -m 755 /etc/ssh/sshd_config.d
    cat >/etc/ssh/sshd_config.d/10-avail-hardening.conf <<'EOF'
# Managed by AvailCoolify bootstrap-host.sh. "10-" sorts first, so it beats cloud-init's 50-*.conf.
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
MaxStartups 30:60:200
EOF

    if systemctl list-unit-files ssh.socket >/dev/null 2>&1 && systemctl is-enabled ssh.socket >/dev/null 2>&1; then
        # Ubuntu 24.04: the socket unit owns the listening port; sshd_config's Port is ignored.
        install -d /etc/systemd/system/ssh.socket.d
        {
            echo "[Socket]"
            echo "ListenStream="
            for p in $ports; do
                echo "ListenStream=0.0.0.0:$p"
                echo "ListenStream=[::]:$p"
            done
        } >/etc/systemd/system/ssh.socket.d/override.conf
        sshd -t || die "sshd_config is invalid"
        systemctl daemon-reload
        systemctl restart ssh.socket
        systemctl reload ssh.service 2>/dev/null || true
    else
        rm -f /etc/ssh/sshd_config.d/20-avail-ports.conf
        for p in $ports; do echo "Port $p"; done >/etc/ssh/sshd_config.d/20-avail-ports.conf
        sshd -t || die "sshd_config is invalid"
        systemctl restart ssh 2>/dev/null || systemctl restart sshd
    fi

    sleep 2
    sshd_ports | grep -qx "$SSH_PORT" || die "sshd is not listening on $SSH_PORT after the change"

    # fail2ban must watch the port that is really in use, or its bans are a no-op.
    cat >/etc/fail2ban/jail.d/avail-sshd.local <<EOF
[sshd]
enabled = true
backend = systemd
port    = $(echo "$ports" | xargs | tr ' ' ',')
maxretry = 5
bantime  = 1h
EOF
    systemctl enable --now fail2ban >/dev/null
    systemctl restart fail2ban
}

install_packages() {
    log "Packages"
    apt-get update -qq
    apt-get install -y -qq curl ca-certificates jq openssl fail2ban unattended-upgrades >/dev/null
}

configure_upgrades() {
    log "Unattended security upgrades"
    cat >/etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
    systemctl enable --now unattended-upgrades >/dev/null 2>&1 || true
}

configure_swap() {
    if [ "$(swapon --show --noheadings | wc -l)" -eq 0 ]; then
        log "Swap: creating ${SWAP_GB} GB /swapfile"
        fallocate -l "${SWAP_GB}G" /swapfile
        chmod 600 /swapfile
        mkswap /swapfile >/dev/null
        swapon /swapfile
        grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >>/etc/fstab
    else
        log "Swap: already configured"
    fi
}

install_docker() {
    if ! command -v docker >/dev/null 2>&1; then
        log "Docker: installing"
        curl -fsSL https://get.docker.com | sh
    fi
    systemctl enable --now docker >/dev/null

    log "Docker: log rotation (10m x 3)"
    local daemon_json=/etc/docker/daemon.json new_json
    [ -s "$daemon_json" ] || echo '{}' >"$daemon_json"
    new_json="$(jq '. + {"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"3"}}' "$daemon_json")"
    if [ "$new_json" != "$(jq . "$daemon_json")" ]; then
        echo "$new_json" >"$daemon_json"
        systemctl restart docker
    fi
}

# --------------------------------------------------------------------- control plane
create_env_file() {
    if [ -f "$ENV_FILE" ]; then
        log "Control plane: $ENV_FILE exists, keeping it (APP_KEY untouched)"
        return 0
    fi
    log "Control plane: creating $ENV_FILE"
    [ -f "$ENV_TEMPLATE" ] || die "$ENV_TEMPLATE missing (the workflow copies it from the repo)"
    : "${DATABASE_URL:?DATABASE_URL (managed Postgres) is required}" "${ROOT_USER_EMAIL:?ROOT_USER_EMAIL is required}"
    case "$DATABASE_URL" in postgres://* | postgresql://*) ;; *) die "DATABASE_URL must start with postgres://" ;; esac

    local url="$DATABASE_URL"
    case "$url" in
        *sslmode=*) ;;
        *\?*) url="$url&sslmode=${DB_SSLMODE:-require}" ;;
        *) url="$url?sslmode=${DB_SSLMODE:-require}" ;;
    esac

    mkdir -p "$(dirname "$ENV_FILE")"
    cp "$ENV_TEMPLATE" "$ENV_FILE"
    chmod 600 "$ENV_FILE"
    chown 9999:root "$ENV_FILE"

    # set_env KEY VALUE: replace "KEY=" or "# KEY=" in place, else append. VALUE is escaped for sed.
    set_env() {
        local key="$1" value="$2" esc
        esc="$(printf '%s' "$value" | sed -e 's/[\\|&]/\\&/g')"
        if grep -qE "^#? ?${key}=" "$ENV_FILE"; then
            sed -i -E "0,/^#? ?${key}=.*/s||${key}=${esc}|" "$ENV_FILE"
        else
            printf '%s=%s\n' "$key" "$value" >>"$ENV_FILE"
        fi
    }

    if [ -n "${COOLIFY_APP_KEY:-}" ]; then
        set_env APP_KEY "$COOLIFY_APP_KEY"
    else
        set_env APP_KEY "base64:$(openssl rand -base64 32)"
    fi
    set_env APP_ID "$(openssl rand -hex 16)"
    set_env DATABASE_URL "$url"
    case "$url" in
        *sslmode=verify-full* | *sslmode=verify-ca*)
            if [ -z "${DB_CA_CERT:-}" ]; then
                echo "WARNING: sslmode verifies the server but no DB_CA_CERT was given; the connection will fail unless the CA is already on the host" >&2
            fi
            ;;
    esac
    if [ -n "${DB_CA_CERT:-}" ]; then
        mkdir -p "$DATA_DIR/ssh"
        printf '%s\n' "$DB_CA_CERT" >"$DATA_DIR/ssh/db-ca.pem"
        chown 9999 "$DATA_DIR/ssh/db-ca.pem"
        chmod 644 "$DATA_DIR/ssh/db-ca.pem"
        set_env DB_SSLROOTCERT /var/www/html/storage/app/ssh/db-ca.pem
    fi
    set_env REDIS_PASSWORD "$(openssl rand -hex 24)"
    set_env PUSHER_APP_ID "$(openssl rand -hex 32)"
    set_env PUSHER_APP_KEY "$(openssl rand -hex 32)"
    set_env PUSHER_APP_SECRET "$(openssl rand -hex 32)"
    set_env PUSHER_BACKEND_PORT 6001
    # Password login is disabled (Clerk only); the seeded root user just needs *a* password.
    set_env ROOT_USER_EMAIL "$ROOT_USER_EMAIL"
    set_env ROOT_USERNAME "Avail Owner"
    set_env ROOT_USER_PASSWORD "$(openssl rand -hex 24)"
    set_env AVAIL_AUTO_JOIN_DOMAINS "${AUTO_JOIN_DOMAINS:-availproject.org}"
    grep -q '^DATABASE_URL=' "$ENV_FILE" || die "failed to write DATABASE_URL"
    grep -q '^APP_KEY=base64:' "$ENV_FILE" || die "failed to write APP_KEY"
}

setup_control_plane() {
    log "Control plane: $DATA_DIR layout"
    local d
    for d in source ssh ssh/keys ssh/mux applications databases backups services proxy proxy/dynamic webhooks-during-maintenance; do
        mkdir -p "$DATA_DIR/$d"
    done
    chown -R 9999:root "$DATA_DIR"
    chmod -R 700 "$DATA_DIR"
    docker network inspect coolify >/dev/null 2>&1 || docker network create --attachable coolify >/dev/null

    # Coolify manages its own host ("localhost") over SSH through host.docker.internal. On the first start
    # it imports this key file into the database as "localhost's key" and uses that copy from then on.
    # So the key is made only on a host that has never been provisioned (no .env yet). On a re-run, or on a
    # new VM restored from an existing database, the key that counts is the one in the database, and the
    # deploy step (localhost-public-key.php) makes that one the only SSH key; creating another here would
    # replace it with a key Coolify does not know.
    local key="$DATA_DIR/ssh/keys/id.root@host.docker.internal"
    if [ -f "$ENV_FILE" ]; then
        log "Control plane: already provisioned, leaving Coolify's SSH key and authorized_keys alone"
    else
        if [ ! -f "$key" ]; then
            log "Control plane: key for Coolify's localhost server"
            ssh-keygen -t ed25519 -a 100 -f "$key" -q -N "" -C coolify
            chown 9999 "$key"
        fi
        # Coolify's key is the only one that may log in over SSH on this host.
        COOLIFY_PUBLIC_KEY="$(cat "$key.pub")" "$SCRIPT_DIR/ssh-authorized-keys.sh"
    fi

    create_env_file
}

main() {
    local role="" arg
    while [ $# -gt 0 ]; do
        arg="$1"
        case "$arg" in
            --role) role="${2:?--role needs a value}"; shift 2 ;;
            *) echo "unknown argument: $arg" >&2; exit 2 ;;
        esac
    done
    case "$role" in control-plane | app-server) ;; *) echo "--role must be control-plane or app-server" >&2; exit 2 ;; esac

    [ "$(id -u)" -eq 0 ] || die "run as root"
    case "$SSH_PORT" in '' | *[!0-9]*) die "SSH_PORT must be a number" ;; esac
    grep -qi '^ID=ubuntu' /etc/os-release || echo "WARNING: written for Ubuntu 24.04; other distros are untested" >&2
    require_teleport

    install_packages
    configure_ssh
    configure_upgrades
    configure_swap
    install_docker

    if [ "$role" = "app-server" ]; then
        log "App server ready. Coolify's key is installed by the deploy step once PROD_COOLIFY_SSH_PUBKEY is set (README, first run); then add the server in AvailCoolify (Servers -> Add, root, port $SSH_PORT) and set its wildcard domain."
        return 0
    fi

    setup_control_plane
    log "Bootstrap done ($role)"
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    main "$@"
fi

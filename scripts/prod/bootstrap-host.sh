#!/usr/bin/env bash
# Prepare a fresh Ubuntu 24.04 VM for AvailCoolify. Idempotent: safe to re-run.
#
#   bootstrap-host.sh --role control-plane|app-server [--finalize-ssh]
#
# Configuration comes from the environment (the CI job sends it as a 0600 env file):
#   SSH_PORT               SSH port to move to (default 58122)
#   SWAP_GB                swapfile size when the host has no swap (default 4)
#   -- control plane only --
#   DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD   managed Postgres (required)
#   DB_SSLMODE             default verify-full
#   DB_CA_CERT             PEM text of the provider's CA (written to /data/coolify/ssh/db-ca.pem)
#   COOLIFY_APP_KEY        reuse an existing APP_KEY (restore drill); generated when empty
#   ROOT_USER_EMAIL        first instance admin; their first Clerk login becomes the owner
#   AUTO_JOIN_DOMAINS      default availproject.org
#
# SSH is moved in two phases so a wrong port or firewall can't lock the CI out:
#   1. (default)       listen on the old port(s) AND the new one;
#   2. --finalize-ssh  run over the NEW port, drops everything else.
set -euo pipefail

ROLE=""
FINALIZE=0
while [ $# -gt 0 ]; do
    case "$1" in
        --role) ROLE="${2:?--role needs a value}"; shift 2 ;;
        --finalize-ssh) FINALIZE=1; shift ;;
        *) echo "unknown argument: $1" >&2; exit 2 ;;
    esac
done
case "$ROLE" in control-plane | app-server) ;; *) echo "--role must be control-plane or app-server" >&2; exit 2 ;; esac

SSH_PORT="${SSH_PORT:-58122}"
SWAP_GB="${SWAP_GB:-4}"
export DEBIAN_FRONTEND=noninteractive

log() { printf '\n==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "run as root"
case "$SSH_PORT" in '' | *[!0-9]*) die "SSH_PORT must be a number" ;; esac
grep -qi '^ID=ubuntu' /etc/os-release || echo "WARNING: written for Ubuntu 24.04; other distros are untested" >&2

# ----------------------------------------------------------------------------- SSH
# Ports sshd really listens on (with ssh.socket this differs from sshd_config's Port).
sshd_ports() { ss -H -ltnp 2>/dev/null | grep '"sshd"' | awk '{print $4}' | sed 's/.*://' | sort -un; }

configure_ssh() {
    [ -s /root/.ssh/authorized_keys ] || die "/root/.ssh/authorized_keys is empty; refusing to disable password login"

    local ports
    if [ "$FINALIZE" -eq 1 ]; then
        ports="$SSH_PORT"
    else
        # Old ports are kept: whatever sshd listens on now, plus the new port.
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

if [ "$FINALIZE" -eq 1 ]; then
    configure_ssh
    log "SSH finalized: only port $SSH_PORT is open"
    exit 0
fi

# ------------------------------------------------------------------------- packages
log "Packages"
apt-get update -qq
apt-get install -y -qq curl ca-certificates jq openssl fail2ban unattended-upgrades rsync >/dev/null

configure_ssh

log "Unattended security upgrades"
cat >/etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
systemctl enable --now unattended-upgrades >/dev/null 2>&1 || true

# ----------------------------------------------------------------------------- swap
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

# ---------------------------------------------------------------------------- docker
if ! command -v docker >/dev/null 2>&1; then
    log "Docker: installing"
    curl -fsSL https://get.docker.com | sh
fi
systemctl enable --now docker >/dev/null

log "Docker: log rotation (10m x 3)"
DAEMON_JSON=/etc/docker/daemon.json
[ -s "$DAEMON_JSON" ] || echo '{}' >"$DAEMON_JSON"
NEW_JSON="$(jq '. + {"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"3"}}' "$DAEMON_JSON")"
if [ "$NEW_JSON" != "$(jq . "$DAEMON_JSON")" ]; then
    echo "$NEW_JSON" >"$DAEMON_JSON"
    systemctl restart docker
fi

if [ "$ROLE" = "app-server" ]; then
    log "App server ready. Add it in AvailCoolify: Servers -> Add (root, port $SSH_PORT, its own SSH key), then set its wildcard domain."
    exit 0
fi

# --------------------------------------------------------------------- control plane
log "Control plane: /data/coolify layout"
for d in source ssh ssh/keys ssh/mux applications databases backups services proxy proxy/dynamic webhooks-during-maintenance; do
    mkdir -p "/data/coolify/$d"
done
chown -R 9999:root /data/coolify
chmod -R 700 /data/coolify
docker network inspect coolify >/dev/null 2>&1 || docker network create --attachable coolify >/dev/null

# Coolify manages its own host ("localhost") over SSH through host.docker.internal.
KEY=/data/coolify/ssh/keys/id.root@host.docker.internal
if [ ! -f "$KEY" ]; then
    log "Control plane: key for Coolify's localhost server"
    ssh-keygen -t ed25519 -a 100 -f "$KEY" -q -N "" -C coolify
    chown 9999 "$KEY"
fi
touch /root/.ssh/authorized_keys && chmod 600 /root/.ssh/authorized_keys
grep -qF "$(cut -d' ' -f1,2 "$KEY.pub")" /root/.ssh/authorized_keys || cat "$KEY.pub" >>/root/.ssh/authorized_keys

ENV_FILE=/data/coolify/source/.env
TEMPLATE="${ENV_TEMPLATE:-/root/availcoolify-release/.env.production}"
if [ -f "$ENV_FILE" ]; then
    log "Control plane: $ENV_FILE exists, keeping it (APP_KEY untouched)"
else
    log "Control plane: creating $ENV_FILE"
    [ -f "$TEMPLATE" ] || die "$TEMPLATE missing (the workflow rsyncs it from the repo)"
    : "${DB_HOST:?DB_HOST (managed Postgres) is required}" "${DB_PASSWORD:?DB_PASSWORD is required}"
    : "${ROOT_USER_EMAIL:?ROOT_USER_EMAIL is required}"

    cp "$TEMPLATE" "$ENV_FILE"
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
    set_env DB_HOST "$DB_HOST"
    set_env DB_PORT "${DB_PORT:-5432}"
    set_env DB_DATABASE "${DB_DATABASE:-coolify}"
    set_env DB_USERNAME "${DB_USERNAME:-coolify}"
    set_env DB_PASSWORD "$DB_PASSWORD"
    set_env DB_SSLMODE "${DB_SSLMODE:-verify-full}"
    if [ -n "${DB_CA_CERT:-}" ]; then
        printf '%s\n' "$DB_CA_CERT" >/data/coolify/ssh/db-ca.pem
        chown 9999 /data/coolify/ssh/db-ca.pem
        chmod 644 /data/coolify/ssh/db-ca.pem
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
    grep -q '^DB_HOST=' "$ENV_FILE" || die "failed to write DB_HOST"
    grep -q '^APP_KEY=base64:' "$ENV_FILE" || die "failed to write APP_KEY"
fi

log "Bootstrap done ($ROLE)"

#!/usr/bin/env bash
# Make one public key the only SSH key that can log in as root on this VM. Idempotent.
#
#   COOLIFY_PUBLIC_KEY='ssh-ed25519 AAAA... comment' scripts/prod/ssh-authorized-keys.sh
#
# People and CI reach the VMs through Teleport, so the only thing that should ever log in over SSH is
# Coolify: on the control plane its own "localhost" key, on an app server the key Coolify uses to manage
# it. Any other key in authorized_keys (for example one the provider injected when the VM was created) is
# listed by fingerprint and, while the Teleport agent is running (so nobody can be locked out), removed
# after the file has been backed up next to itself.
#
#   PRUNE_AUTHORIZED_KEYS   1 (default) removes the other keys, 0 only lists them
#   AUTHORIZED_KEYS         file to manage (default /root/.ssh/authorized_keys)
set -euo pipefail

FILE="${AUTHORIZED_KEYS:-/root/.ssh/authorized_keys}"
PRUNE="${PRUNE_AUTHORIZED_KEYS:-1}"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

KEY="$(printf '%s' "${COOLIFY_PUBLIC_KEY:-}" | tr -d '\r' | sed 's/[[:space:]]*$//')"
case "$KEY" in
    *$'\n'*) die "COOLIFY_PUBLIC_KEY must be a single line" ;;
    ssh-ed25519\ * | ssh-rsa\ * | ecdsa-sha2-*\ *) ;;
    *) die "COOLIFY_PUBLIC_KEY must be one public key line, starting with ssh-ed25519 (or ssh-rsa / ecdsa-sha2-*)" ;;
esac

# "type base64" of the first key found on a line, whatever options or comment surround it.
blob() { awk '{ for (i = 1; i < NF; i++) if ($i ~ /^(ssh-|ecdsa-)/) { print $i " " $(i + 1); exit } }'; }
want="$(printf '%s\n' "$KEY" | blob)"

install -d -m 700 "$(dirname "$FILE")"
touch "$FILE"
chmod 600 "$FILE"

if grep -qF "$want" "$FILE"; then
    echo "Coolify's key is already in $FILE"
else
    printf '%s\n' "$KEY" >>"$FILE"
    echo "Added Coolify's key to $FILE"
fi

foreign=()
while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in '' | '#'*) continue ;; esac
    [ "$(printf '%s\n' "$line" | blob)" = "$want" ] && continue
    foreign+=("$line")
done <"$FILE"

if [ "${#foreign[@]}" -eq 0 ]; then
    echo "Only Coolify's key can log in over SSH"
    exit 0
fi

echo "Other SSH keys in $FILE:" >&2
for line in "${foreign[@]}"; do
    printf '%s\n' "$line" | ssh-keygen -lf - >&2 || echo "  (a line that is not a valid key)" >&2
done

if [ "$PRUNE" != 1 ]; then
    echo "WARNING: ${#foreign[@]} other key(s) can still log in over SSH (PRUNE_AUTHORIZED_KEYS=0)" >&2
    exit 0
fi
if ! systemctl is-active --quiet teleport 2>/dev/null; then
    echo "WARNING: leaving those keys in place: the Teleport agent is not running, so they may be the only way in" >&2
    exit 0
fi

backup="$FILE.pre-avail-$(date +%Y%m%d-%H%M%S)"
cp -p "$FILE" "$backup"
printf '%s\n' "$KEY" >"$FILE"
chmod 600 "$FILE"
echo "Removed ${#foreign[@]} other key(s) from $FILE; the previous file is $backup"

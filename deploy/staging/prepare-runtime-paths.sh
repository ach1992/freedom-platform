#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

INPUT=/root/freedom-bootstrap/incoming
DOMAIN_FILE="$INPUT/domain"

test -s "$DOMAIN_FILE"
DOMAIN=$(cat "$DOMAIN_FILE")
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || {
    echo 'Invalid staging domain input.' >&2
    exit 65
}

ROOT="/www/acdomains/$DOMAIN"
SHARED="$ROOT/shared"
STORAGE="$SHARED/storage"

exec 9>/root/freedom-bootstrap/runtime-paths.lock
flock -w 300 9 || {
    echo 'Runtime path preparation lock timeout.' >&2
    exit 75
}

id www >/dev/null 2>&1

install -d -o www -g www -m 0750 "$ROOT" "$ROOT/releases" "$SHARED"
install -d -o www -g www -m 0750 \
    "$STORAGE" \
    "$STORAGE/app" \
    "$STORAGE/app/public" \
    "$STORAGE/framework" \
    "$STORAGE/framework/cache" \
    "$STORAGE/framework/sessions" \
    "$STORAGE/framework/views" \
    "$STORAGE/logs"
install -d -o www -g www -m 0700 \
    "$SHARED/backups" \
    "$SHARED/update-packages" \
    "$SHARED/restore-work" \
    "$SHARED/provider-certificates"

chown -R www:www "$STORAGE"
find "$STORAGE" -type d -exec chmod 0750 {} +
find "$STORAGE" -type f -exec chmod 0640 {} +

for release in "$ROOT"/releases/staging-*; do
    [ -d "$release" ] || continue

    if [ -d "$release/bootstrap/cache" ]; then
        chown -R www:www "$release/bootstrap/cache"
        find "$release/bootstrap/cache" -type d -exec chmod 0750 {} +
        find "$release/bootstrap/cache" -type f -exec chmod 0640 {} +
    fi

done

runuser -u www -- test -w "$STORAGE"

for release in "$ROOT"/releases/staging-*; do
    [ -d "$release" ] || continue
    [ ! -d "$release/bootstrap/cache" ] || runuser -u www -- test -w "$release/bootstrap/cache"
done

echo '# runtime path preparation evidence'
echo "utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "storage_mode=$(stat -Lc %a "$STORAGE")"
echo "storage_owner=$(stat -Lc %U "$STORAGE")"
echo "storage_group=$(stat -Lc %G "$STORAGE")"
echo 'storage_writable_as_www=yes'

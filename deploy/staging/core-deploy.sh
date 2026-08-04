#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

if [ "$#" -ne 1 ] || [[ ! "$1" =~ ^[0-9a-f]{40}$ ]]; then
    echo 'Usage: core-deploy.sh <verified-commit-sha>' >&2
    exit 64
fi

VERIFIED_SHA=$1
SHORT_SHA=${VERIFIED_SHA:0:12}
INPUT=/root/freedom-bootstrap/incoming
DOMAIN_FILE="$INPUT/domain"
TELEGRAM_TOKEN_FILE="$INPUT/telegram-token"
ARCHIVE="$INPUT/application.tar.gz"
DOMAIN=$(cat "$DOMAIN_FILE")
TELEGRAM_TOKEN=$(cat "$TELEGRAM_TOKEN_FILE")

[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || {
    echo 'Invalid staging domain input.' >&2
    exit 65
}

ROOT="/www/acdomains/$DOMAIN"
RELEASE_A="staging-a-$SHORT_SHA"
RELEASE_B="staging-b-$SHORT_SHA"
PHP=/www/server/php/84/bin/php
LSPHP=/usr/local/lsws/lsphp84/bin/lsphp
STATE=/root/freedom-bootstrap/application-secrets.env
EVIDENCE=/root/freedom-bootstrap/core-deploy-evidence.txt
RUNTIME_PROBE=/root/freedom-bootstrap/runtime-probe.php
COMPOSER_HOME=/tmp/freedom-composer-home

exec 9>/root/freedom-bootstrap/deploy.lock
flock -w 300 9 || {
    echo 'Deployment lock timeout.' >&2
    exit 75
}

cleanup_private_inputs() {
    rm -f "$TELEGRAM_TOKEN_FILE" "$DOMAIN_FILE" "$RUNTIME_PROBE"
}
trap cleanup_private_inputs EXIT

test -x "$PHP"
test -x "$LSPHP"
test -s "$ARCHIVE"
id www >/dev/null 2>&1

timedatectl set-timezone UTC
systemctl enable --now mariadb redis-server supervisor cron >/dev/null
systemctl restart mariadb >/dev/null

mariadb_ready=0
for _attempt in $(seq 1 60); do
    if mariadb-admin --protocol=socket ping --silent >/dev/null 2>&1; then
        mariadb_ready=1
        break
    fi
    sleep 1
done

if [ "$mariadb_ready" -ne 1 ]; then
    echo 'MariaDB did not become ready within 60 seconds.' >&2
    systemctl --no-pager --full status mariadb 2>&1 | tail -n 60 >&2 || true
    journalctl -u mariadb --no-pager -n 80 2>&1 | tail -n 80 >&2 || true
    exit 70
fi

cat > "$RUNTIME_PROBE" <<'PHP'
<?php

declare(strict_types=1);

$extensions = array_slice($argv, 1);
$missing = array_values(array_filter(
    $extensions,
    static fn (string $extension): bool => ! extension_loaded($extension),
));

echo 'version=' . PHP_VERSION . PHP_EOL;
echo 'sapi=' . PHP_SAPI . PHP_EOL;
echo 'missing=' . ($missing === [] ? 'none' : implode(',', $missing)) . PHP_EOL;

exit($missing === [] ? 0 : 2);
PHP
chmod 600 "$RUNTIME_PROBE"

COMMON_EXTENSIONS=(
    bcmath
    curl
    dom
    fileinfo
    intl
    json
    mbstring
    openssl
    pdo
    pdo_mysql
    redis
    sodium
    tokenizer
    xml
)
CLI_EXTENSIONS=("${COMMON_EXTENSIONS[@]}" pcntl)

set +e
CLI_RUNTIME=$($PHP "$RUNTIME_PROBE" "${CLI_EXTENSIONS[@]}" 2>&1)
CLI_RUNTIME_STATUS=$?
LSPHP_RUNTIME=$($LSPHP "$RUNTIME_PROBE" "${COMMON_EXTENSIONS[@]}" 2>&1)
LSPHP_RUNTIME_STATUS=$?
set -e

printf '%s\n' "$CLI_RUNTIME" | grep -qx 'missing=none'
printf '%s\n' "$LSPHP_RUNTIME" | grep -qx 'missing=none'
test "$CLI_RUNTIME_STATUS" -eq 0
test "$LSPHP_RUNTIME_STATUS" -eq 0

CLI_VERSION=$(printf '%s\n' "$CLI_RUNTIME" | awk -F= '$1=="version" {print $2}')
CLI_SAPI=$(printf '%s\n' "$CLI_RUNTIME" | awk -F= '$1=="sapi" {print $2}')
LSPHP_VERSION=$(printf '%s\n' "$LSPHP_RUNTIME" | awk -F= '$1=="version" {print $2}')
LSPHP_SAPI=$(printf '%s\n' "$LSPHP_RUNTIME" | awk -F= '$1=="sapi" {print $2}')

test "$CLI_VERSION" = "$LSPHP_VERSION"
test "$CLI_SAPI" = 'cli'
test "$LSPHP_SAPI" = 'litespeed'

if [ ! -s "$STATE" ]; then
    DB_PASSWORD=$(openssl rand -hex 32)
    REDIS_PASSWORD=$(openssl rand -hex 32)
    APP_KEY="base64:$($PHP -r 'echo base64_encode(random_bytes(32));')"
    WEBHOOK_SECRET=$(openssl rand -hex 32)

    cat > "$STATE" <<EOF
DB_PASSWORD=$DB_PASSWORD
REDIS_PASSWORD=$REDIS_PASSWORD
APP_KEY=$APP_KEY
WEBHOOK_SECRET=$WEBHOOK_SECRET
EOF
    chmod 600 "$STATE"
fi

# shellcheck disable=SC1090
. "$STATE"

mariadb --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS freedom_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'freedom_platform'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER 'freedom_platform'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON freedom_platform.* TO 'freedom_platform'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

if [ ! -e /etc/redis/redis.conf.freedom-before-auth ]; then
    cp -a /etc/redis/redis.conf /etc/redis/redis.conf.freedom-before-auth
fi

python3 - "$STATE" <<'PY'
from pathlib import Path
import sys

state = {}
for line in Path(sys.argv[1]).read_text().splitlines():
    if '=' in line:
        key, value = line.split('=', 1)
        state[key] = value

password = state['REDIS_PASSWORD']
path = Path('/etc/redis/redis.conf')
lines = path.read_text().splitlines()
managed = {'bind', 'protected-mode', 'requirepass'}
output = []

for line in lines:
    stripped = line.strip()
    uncommented = stripped.lstrip('#').strip()
    token = uncommented.split(maxsplit=1)[0] if uncommented else ''
    if token in managed:
        continue
    output.append(line)

output.extend([
    'bind 127.0.0.1 ::1',
    'protected-mode yes',
    f'requirepass {password}',
])

temporary = path.with_suffix('.conf.freedom-new')
temporary.write_text('\n'.join(output) + '\n')
temporary.chmod(0o640)
temporary.replace(path)
PY

chown redis:redis /etc/redis/redis.conf
systemctl restart redis-server
REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli ping | grep -qx PONG

install -d -o www -g www -m 0750 "$ROOT" "$ROOT/releases" "$ROOT/shared"
install -d -o www -g www -m 0750 \
    "$ROOT/shared/storage/app/public" \
    "$ROOT/shared/storage/framework/cache" \
    "$ROOT/shared/storage/framework/sessions" \
    "$ROOT/shared/storage/framework/views" \
    "$ROOT/shared/storage/logs"
install -d -o www -g www -m 0700 \
    "$ROOT/shared/backups" \
    "$ROOT/shared/update-packages" \
    "$ROOT/shared/restore-work" \
    "$ROOT/shared/provider-certificates"

cat > "$ROOT/shared/.env" <<EOF
APP_NAME="Freedom Platform"
APP_ENV=production
APP_KEY="$APP_KEY"
APP_DEBUG=false
APP_URL="https://$DOMAIN"
APP_VERSION="0.3.0-staging-$SHORT_SHA"
APP_TIMEZONE=UTC
BUSINESS_TIMEZONE=Asia/Tehran
APP_LOCALE=fa
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=info
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=freedom_platform
DB_USERNAME=freedom_platform
DB_PASSWORD="$DB_PASSWORD"
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN="$DOMAIN"
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis
CACHE_STORE=redis
CACHE_PREFIX=freedom_platform
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD="$REDIS_PASSWORD"
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_QUEUE_RETRY_AFTER=420
WORKER_HEARTBEAT_ENABLED=false
WORKER_NAME=
WORKER_QUEUE_GROUP=default
WORKER_HEARTBEAT_INTERVAL_SECONDS=30
WORKER_HEARTBEAT_STALE_AFTER_SECONDS=480
TELEGRAM_BOT_TOKEN="$TELEGRAM_TOKEN"
TELEGRAM_WEBHOOK_SECRET="$WEBHOOK_SECRET"
OWNER_TELEGRAM_ID=
REPORT_CHAT_ID=
INSTALLER_LOCK_PATH="$ROOT/shared/installer.lock"
INSTALLER_ACCESS_FILE="$ROOT/shared/installer-access.json"
INSTALLER_BOOTSTRAP_JOURNAL_PATH="$ROOT/shared/installer-bootstrap.json"
INSTALLER_ENVIRONMENT_PATH="$ROOT/shared/.env"
INSTALLER_ENVIRONMENT_SNAPSHOT_PATH="$ROOT/shared/installer-environment.snapshot"
INSTALLER_PHP_CLI_BINARY="$PHP"
INSTALLER_PHP_LSPHP_BINARY="$LSPHP"
INSTALLER_FINALIZATION_TIMEOUT_SECONDS=300
INSTALLER_MINIMUM_FREE_BYTES=1073741824
INSTALLER_EXPECTED_OWNER=www
INSTALLER_EXPECTED_GROUP=www
MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="staging@$DOMAIN"
MAIL_FROM_NAME="Freedom Platform"
EOF
chown www:www "$ROOT/shared/.env"
chmod 600 "$ROOT/shared/.env"

if [ ! -x /usr/local/bin/composer ]; then
    EXPECTED=$($PHP -r "copy('https://composer.github.io/installer.sig', 'php://stdout');")
    $PHP -r "copy('https://getcomposer.org/installer', '/root/freedom-bootstrap/composer-setup.php');"
    ACTUAL=$($PHP -r "echo hash_file('sha384', '/root/freedom-bootstrap/composer-setup.php');")
    test "$EXPECTED" = "$ACTUAL"
    $PHP /root/freedom-bootstrap/composer-setup.php \
        --quiet \
        --install-dir=/usr/local/bin \
        --filename=composer \
        --version=2.10.2
    rm -f /root/freedom-bootstrap/composer-setup.php
fi
$PHP /usr/local/bin/composer --version --no-ansi >/dev/null

prepare_release() {
    local release_id=$1
    local release_path="$ROOT/releases/$release_id"

    if [ -e "$release_path" ]; then
        test -f "$release_path/.staging-source-sha"
        test "$(cat "$release_path/.staging-source-sha")" = "$VERIFIED_SHA"
        return
    fi

    install -d -o www -g www -m 0750 "$release_path"
    tar -xzf "$ARCHIVE" -C "$release_path"
    rm -rf "$release_path/storage" "$release_path/.env"
    ln -s ../../shared/storage "$release_path/storage"
    ln -s ../../shared/.env "$release_path/.env"
    printf '%s\n' "$VERIFIED_SHA" > "$release_path/.staging-source-sha"
    chown -R www:www "$release_path"
    find "$release_path" -type d -exec chmod 0750 {} +
    find "$release_path" -type f -exec chmod 0640 {} +
    chmod 0750 "$release_path/artisan"
    install -d -o www -g www -m 0750 "$release_path/bootstrap/cache"
}

prepare_release "$RELEASE_A"
install -d -o www -g www -m 0700 "$COMPOSER_HOME"
runuser -u www -- env HOME="$COMPOSER_HOME" COMPOSER_HOME="$COMPOSER_HOME" \
    "$PHP" /usr/local/bin/composer install \
    --working-dir="$ROOT/releases/$RELEASE_A" \
    --no-dev \
    --classmap-authoritative \
    --no-interaction \
    --prefer-dist \
    --no-progress
runuser -u www -- env HOME="$COMPOSER_HOME" COMPOSER_HOME="$COMPOSER_HOME" \
    "$PHP" /usr/local/bin/composer check-platform-reqs \
    --working-dir="$ROOT/releases/$RELEASE_A" \
    --no-dev

if [ ! -e "$ROOT/releases/$RELEASE_B" ]; then
    cp -a "$ROOT/releases/$RELEASE_A" "$ROOT/releases/$RELEASE_B"
    chown -R www:www "$ROOT/releases/$RELEASE_B"
fi
prepare_release "$RELEASE_B"

for release_id in "$RELEASE_A" "$RELEASE_B"; do
    release_path="$ROOT/releases/$release_id"
    runuser -u www -- "$PHP" "$release_path/artisan" optimize:clear --no-ansi --no-interaction
done

runuser -u www -- "$PHP" "$ROOT/releases/$RELEASE_A/artisan" migrate --force --no-ansi --no-interaction
runuser -u www -- "$PHP" "$ROOT/releases/$RELEASE_A/artisan" db:seed --force --no-ansi --no-interaction

for release_id in "$RELEASE_A" "$RELEASE_B"; do
    release_path="$ROOT/releases/$release_id"
    runuser -u www -- "$PHP" "$release_path/artisan" config:cache --no-ansi --no-interaction
    runuser -u www -- "$PHP" "$release_path/artisan" route:cache --no-ansi --no-interaction
    runuser -u www -- "$PHP" "$release_path/artisan" view:cache --no-ansi --no-interaction
    runuser -u www -- "$PHP" "$release_path/artisan" health:check \
        --critical --json --redact --no-ansi --no-interaction >/dev/null
done

switch_release() {
    local release_id=$1
    shift

    runuser -u www -- "$PHP" "$ROOT/releases/$release_id/deploy/bin/release-switch.php" \
        --root="$ROOT" \
        --release="$release_id" \
        --php="$PHP" \
        --journal="$ROOT/shared/release-journal.json" \
        --timeout=60 \
        "$@"
}

{
    echo '# staging core deployment evidence'
    echo "verified_sha=$VERIFIED_SHA"
    echo "utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "php_cli=$CLI_VERSION"
    echo "php_cli_sapi=$CLI_SAPI"
    echo "php_lsphp=$LSPHP_VERSION"
    echo "php_lsphp_sapi=$LSPHP_SAPI"
    echo "mariadb=$(mariadb --version | head -n 1)"
    echo "redis=$(redis-cli --version)"
    echo "activation_a=$(switch_release "$RELEASE_A")"
    echo "activation_b=$(switch_release "$RELEASE_B")"
    echo "rollback_a=$(switch_release "$RELEASE_A" --rollback)"
    echo "reactivation_b=$(switch_release "$RELEASE_B")"
    echo "current_release=$(basename "$(readlink -f "$ROOT/current")")"
    echo "env_mode=$(stat -c %a "$ROOT/shared/.env")"
    echo "release_journal_mode=$(stat -c %a "$ROOT/shared/release-journal.json")"
} > "$EVIDENCE"

python3 - "$ROOT" <<'PY'
from pathlib import Path
import sys

root = sys.argv[1]
source = Path(root, 'current/deploy/supervisor/freedom-platform.conf').read_text()
source = source.replace('/www/acdomains/hell.hellpservice.ir', root)
destination = Path('/etc/supervisor/conf.d/freedom-platform.conf')
destination.write_text(source)
destination.chmod(0o644)
PY

cat > /etc/cron.d/freedom-platform <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * www cd $ROOT/current && $PHP artisan schedule:run >> /dev/null 2>&1
EOF
chmod 644 /etc/cron.d/freedom-platform
systemctl is-active --quiet cron

supervisorctl reread >> "$EVIDENCE"
supervisorctl update >> "$EVIDENCE"
sleep 5
supervisorctl status 'freedom-platform-workers:*' >> "$EVIDENCE"

runuser -u www -- "$PHP" "$ROOT/current/artisan" schedule:clear-cache --no-ansi --no-interaction >/dev/null
runuser -u www -- "$PHP" "$ROOT/current/artisan" schedule:run --no-ansi --no-interaction >/dev/null
sleep 45

worker_count=$(mariadb --protocol=socket -N -uroot freedom_platform -e \
    "SELECT COUNT(*) FROM worker_heartbeats WHERE worker_id <> 'scheduler';")
scheduler_count=$(mariadb --protocol=socket -N -uroot freedom_platform -e \
    "SELECT COUNT(*) FROM worker_heartbeats WHERE worker_id = 'scheduler';")
echo "worker_heartbeat_count=$worker_count" >> "$EVIDENCE"
echo "scheduler_heartbeat_count=$scheduler_count" >> "$EVIDENCE"
test "$worker_count" -ge 5
test "$scheduler_count" -eq 1

runuser -u www -- "$PHP" "$ROOT/current/artisan" operations:check-worker-heartbeats \
    --max-age=120 --json --no-ansi --no-interaction >> "$EVIDENCE"

supervisorctl stop 'freedom-platform-workers:freedom-platform-bulk_00' >/dev/null
mariadb --protocol=socket -uroot freedom_platform -e \
    "UPDATE worker_heartbeats SET last_seen_at = UTC_TIMESTAMP(6) - INTERVAL 600 SECOND WHERE worker_id LIKE 'freedom-platform-bulk%';"

set +e
stale_output=$(runuser -u www -- "$PHP" "$ROOT/current/artisan" operations:check-worker-heartbeats \
    --max-age=120 --json --no-ansi --no-interaction 2>&1)
stale_status=$?
set -e

test "$stale_status" -ne 0
printf 'stale_check=%s\n' "$stale_output" >> "$EVIDENCE"
unresolved=$(mariadb --protocol=socket -N -uroot freedom_platform -e \
    "SELECT COUNT(*) FROM alerts WHERE event_name='operations.worker_heartbeat_stale' AND resolved_at IS NULL;")
echo "unresolved_stale_alerts=$unresolved" >> "$EVIDENCE"
test "$unresolved" -ge 1

supervisorctl start 'freedom-platform-workers:freedom-platform-bulk_00' >/dev/null
sleep 45
runuser -u www -- "$PHP" "$ROOT/current/artisan" schedule:run --no-ansi --no-interaction >/dev/null
runuser -u www -- "$PHP" "$ROOT/current/artisan" operations:check-worker-heartbeats \
    --max-age=120 --json --no-ansi --no-interaction >> "$EVIDENCE"

unresolved_after=$(mariadb --protocol=socket -N -uroot freedom_platform -e \
    "SELECT COUNT(*) FROM alerts WHERE event_name='operations.worker_heartbeat_stale' AND resolved_at IS NULL;")
echo "unresolved_after_recovery=$unresolved_after" >> "$EVIDENCE"
test "$unresolved_after" -eq 0

runuser -u www -- "$PHP" "$ROOT/current/artisan" health:check \
    --critical --json --redact --no-ansi --no-interaction >> "$EVIDENCE"

cron_line="* * * * * www cd $ROOT/current && $PHP artisan schedule:run >> /dev/null 2>&1"
cron_entries=$(grep -Fxc "$cron_line" /etc/cron.d/freedom-platform)
echo "cron_entries=$cron_entries" >> "$EVIDENCE"
test "$cron_entries" -eq 1

cat > "$ROOT/shared/installer.lock" <<EOF
{"locked_at":"$(date -u +%Y-%m-%dT%H:%M:%SZ)","source":"verified-staging-deployment"}
EOF
chown www:www "$ROOT/shared/installer.lock"
chmod 600 "$ROOT/shared/installer.lock"
echo "installer_lock_mode=$(stat -c %a "$ROOT/shared/installer.lock")" >> "$EVIDENCE"

cat "$EVIDENCE"
rm -f "$ARCHIVE"

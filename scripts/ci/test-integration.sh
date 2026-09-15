#!/usr/bin/env bash

set -euo pipefail

compose_file="docker-compose.ci.yml"
fixture_dir=''
compose_attempted=false
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-freedom-platform-test-$$}"

cleanup() {
    local status=$?
    trap - EXIT

    if [[ "${compose_attempted}" == true ]]; then
        if ((status != 0)); then
            docker compose -f "${compose_file}" ps -a >&2 || true
            docker compose -f "${compose_file}" logs --no-color >&2 || true
        fi
        docker compose -f "${compose_file}" down --volumes --remove-orphans >/dev/null 2>&1 || true
    fi

    [[ -z "${fixture_dir}" ]] || rm -rf "${fixture_dir}"
    exit "${status}"
}
trap cleanup EXIT

fixture_dir=$(mktemp -d)
chmod 0755 "${fixture_dir}"
install -m 0644 docker/mariadb/ci-init.sql "${fixture_dir}/ci-init.sql"

export APP_ENV=testing
export APP_DEBUG=false
export APP_TIMEZONE=UTC
export BUSINESS_TIMEZONE=Asia/Tehran
export APP_KEY='base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='
export DB_CONNECTION=mysql
export DB_HOST=127.0.0.1
export DB_DATABASE=freedom_platform_ci
export DB_USERNAME=freedom_ci
export DB_PASSWORD=ci-only-database-password
export REDIS_HOST=127.0.0.1
export REDIS_PASSWORD=ci-only-redis-password
export CACHE_STORE=redis
export QUEUE_CONNECTION=redis
export MARIADB_HOST_PORT=0
export MARIADB_SHADOW_HOST_PORT=0
export REDIS_HOST_PORT=0
export MARIADB_INIT_SQL_PATH="${fixture_dir}/ci-init.sql"

docker compose -f "${compose_file}" config --quiet
compose_attempted=true
docker compose -f "${compose_file}" up -d --wait

DB_PORT=$(docker compose -f "${compose_file}" port mariadb 3306 | sed -E 's/.*:([0-9]+)$/\1/')
MARIADB_SHADOW_PORT=$(docker compose -f "${compose_file}" port mariadb-shadow 3306 | sed -E 's/.*:([0-9]+)$/\1/')
REDIS_PORT=$(docker compose -f "${compose_file}" port redis 6379 | sed -E 's/.*:([0-9]+)$/\1/')

[[ "${DB_PORT}" =~ ^[0-9]{2,5}$ ]]
[[ "${MARIADB_SHADOW_PORT}" =~ ^[0-9]{2,5}$ ]]
[[ "${REDIS_PORT}" =~ ^[0-9]{2,5}$ ]]
export DB_PORT MARIADB_SHADOW_PORT REDIS_PORT

php artisan config:clear --ansi
php vendor/bin/phpunit \
    --configuration phpunit.xml \
    --display-warnings \
    --fail-on-warning \
    "$@"

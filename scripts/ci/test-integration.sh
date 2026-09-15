#!/usr/bin/env bash

set -euo pipefail

compose_file="docker-compose.ci.yml"

export APP_ENV=testing
export APP_DEBUG=false
export APP_TIMEZONE=UTC
export BUSINESS_TIMEZONE=Asia/Tehran
export APP_KEY='base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='
export DB_CONNECTION=mysql
export DB_HOST=127.0.0.1
export DB_PORT=33067
export DB_DATABASE=freedom_platform_ci
export DB_USERNAME=freedom_ci
export DB_PASSWORD=ci-only-database-password
export REDIS_HOST=127.0.0.1
export REDIS_PORT=6380
export REDIS_PASSWORD=ci-only-redis-password
export CACHE_STORE=redis
export QUEUE_CONNECTION=redis

cleanup() {
    docker compose -f "${compose_file}" down --volumes --remove-orphans
}
trap cleanup EXIT

docker compose -f "${compose_file}" config --quiet
docker compose -f "${compose_file}" up -d --wait

php artisan config:clear --ansi
php vendor/bin/phpunit \
    --configuration phpunit.xml \
    --display-warnings \
    --fail-on-warning \
    "$@"

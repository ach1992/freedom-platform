#!/usr/bin/env bash

set -Eeuo pipefail

if [[ "$#" -eq 0 ]]; then
  echo 'Usage: run-php-container.sh <command> [arguments...]' >&2
  exit 64
fi

image="${CI_PHP_IMAGE:-freedom-platform-ci-php:8.4}"
cache_dir="${HOME}/.cache/freedom-platform-composer"

mkdir -p "$cache_dir"

pass_env=(
  APP_ENV
  APP_DEBUG
  APP_TIMEZONE
  BUSINESS_TIMEZONE
  APP_KEY
  DB_CONNECTION
  DB_HOST
  DB_PORT
  DB_DATABASE
  DB_USERNAME
  DB_PASSWORD
  REDIS_HOST
  REDIS_PORT
  REDIS_PASSWORD
  CACHE_STORE
  QUEUE_CONNECTION
)

env_args=(
  --env CI=1
  --env HOME=/tmp/ci-home
  --env COMPOSER_CACHE_DIR=/tmp/composer-cache
)

for variable in "${pass_env[@]}"; do
  if [[ -v "$variable" ]]; then
    env_args+=(--env "$variable")
  fi
done

exec docker run --rm \
  --init \
  --network host \
  --user "$(id -u):$(id -g)" \
  --volume "$PWD:/workspace" \
  --volume "$cache_dir:/tmp/composer-cache" \
  --workdir /workspace \
  "${env_args[@]}" \
  "$image" \
  "$@"

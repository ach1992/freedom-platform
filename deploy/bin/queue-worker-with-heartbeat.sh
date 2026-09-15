#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
APPLICATION_ROOT=${FREEDOM_PLATFORM_APPLICATION_ROOT:-$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd -P)}
PHP_BINARY=${FREEDOM_PLATFORM_PHP_BINARY:-/www/server/php/84/bin/php}
HEARTBEAT_INTERVAL=${WORKER_HEARTBEAT_INTERVAL_SECONDS:-30}
WORKER_PID=''
HEARTBEAT_PID=''

fail() {
    printf '%s\n' 'Queue worker heartbeat wrapper configuration is invalid.' >&2
    exit 64
}

[[ -x "$PHP_BINARY" ]] || fail
[[ -f "$APPLICATION_ROOT/artisan" ]] || fail
[[ "${WORKER_HEARTBEAT_ENABLED:-}" == 'true' ]] || fail
[[ "${WORKER_NAME:-}" =~ ^[A-Za-z0-9._-]{1,191}$ ]] || fail
[[ "${WORKER_QUEUE_GROUP:-}" =~ ^[A-Za-z0-9._,-]{1,191}$ ]] || fail
[[ "$HEARTBEAT_INTERVAL" =~ ^[0-9]+$ ]] || fail
(( HEARTBEAT_INTERVAL >= 1 && HEARTBEAT_INTERVAL <= 300 )) || fail
(( $# >= 1 )) || fail

record_heartbeat() {
    if ! "$PHP_BINARY" "$APPLICATION_ROOT/artisan" operations:worker-heartbeat \
        "$WORKER_NAME" \
        "--queue=$WORKER_QUEUE_GROUP" \
        --no-ansi \
        --no-interaction \
        >/dev/null; then
        printf '%s\n' 'Queue worker heartbeat could not be recorded.' >&2
    fi
}

heartbeat_loop() {
    while true; do
        record_heartbeat
        sleep "$HEARTBEAT_INTERVAL"
    done
}

stop_children() {
    local signal=${1:-TERM}

    if [[ -n "$WORKER_PID" ]] && kill -0 "$WORKER_PID" 2>/dev/null; then
        kill -"$signal" "$WORKER_PID" 2>/dev/null || true
    fi

    if [[ -n "$HEARTBEAT_PID" ]] && kill -0 "$HEARTBEAT_PID" 2>/dev/null; then
        kill -"$signal" "$HEARTBEAT_PID" 2>/dev/null || true
    fi
}

cleanup() {
    stop_children TERM

    if [[ -n "$HEARTBEAT_PID" ]]; then
        wait "$HEARTBEAT_PID" 2>/dev/null || true
    fi
}

trap cleanup EXIT
trap 'stop_children TERM' TERM INT HUP

heartbeat_loop &
HEARTBEAT_PID=$!

"$PHP_BINARY" "$APPLICATION_ROOT/artisan" queue:work redis "$@" &
WORKER_PID=$!

set +e
wait "$WORKER_PID"
WORKER_STATUS=$?
set -e

exit "$WORKER_STATUS"

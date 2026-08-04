#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

if [ "$#" -ne 1 ] || [[ ! "$1" =~ ^[A-Za-z0-9.-]+$ ]]; then
    echo 'Usage: verify-telegram-webhook.sh <staging-domain>' >&2
    exit 64
fi

DOMAIN=$1
ROOT="/www/acdomains/$DOMAIN"
PHP=/www/server/php/84/bin/php
ARTISAN="$ROOT/current/artisan"
VERIFY_SCRIPT=/root/freedom-bootstrap/verify-telegram-contract.php

cleanup() {
    rm -f "$VERIFY_SCRIPT"
}
trap cleanup EXIT

test -x "$PHP"
test -f "$ARTISAN"
test -s "$ROOT/shared/.env"
test "$(stat -c %a "$ROOT/shared/.env")" = '600'

supervisorctl restart 'freedom-platform-workers:*' >/dev/null
sleep 5
runuser -u www -- "$PHP" "$ARTISAN" queue:clear redis --queue=critical --force --no-ansi --no-interaction >/dev/null

CONFIGURE_OUTPUT=$(runuser -u www -- "$PHP" "$ARTISAN" telegram:webhook:configure --json --no-ansi --no-interaction)
STATUS_OUTPUT=$(runuser -u www -- "$PHP" "$ARTISAN" telegram:webhook:configure --status-only --json --no-ansi --no-interaction)

python3 - "$CONFIGURE_OUTPUT" "$STATUS_OUTPUT" <<'PY'
import json
import sys

for label, raw in zip(('configure', 'status'), sys.argv[1:]):
    payload = json.loads(raw)
    if payload.get('configured') is not True:
        raise SystemExit(f'{label}: webhook is not configured')
    if payload.get('targets_expected_url') is not True:
        raise SystemExit(f'{label}: webhook targets an unexpected URL')
    if not isinstance(payload.get('pending_update_count'), int):
        raise SystemExit(f'{label}: pending update count is invalid')
    print(f'{label}_configured=yes')
    print(f'{label}_targets_expected_url=yes')
    print(f'{label}_pending_update_count={payload["pending_update_count"]}')
    print(f'{label}_last_error_present={str(bool(payload.get("last_error_present"))).lower()}')
PY

NO_SECRET_CODE=$(curl --silent --show-error --max-time 20 \
    --output /dev/null \
    --write-out '%{http_code}' \
    --header 'Content-Type: application/json' \
    --data '{"update_id":900000000000000001}' \
    "https://$DOMAIN/api/telegram/webhook")

WRONG_SECRET_CODE=$(curl --silent --show-error --max-time 20 \
    --output /dev/null \
    --write-out '%{http_code}' \
    --header 'Content-Type: application/json' \
    --header 'X-Telegram-Bot-Api-Secret-Token: deliberately-wrong-secret' \
    --data '{"update_id":900000000000000002}' \
    "https://$DOMAIN/api/telegram/webhook")

test "$NO_SECRET_CODE" = '403'
test "$WRONG_SECRET_CODE" = '403'
echo "missing_secret_http=$NO_SECRET_CODE"
echo "wrong_secret_http=$WRONG_SECRET_CODE"

cat > "$VERIFY_SCRIPT" <<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;

$root = $argv[1] ?? '';

if (! is_string($root) || $root === '') {
    fwrite(STDERR, "Application root is required.\n");
    exit(64);
}

require $root.'/current/vendor/autoload.php';
$app = require $root.'/current/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$config = $app->make(Repository::class);
$database = $app->make(DatabaseManager::class);
$url = $config->get('app.url').'/'.trim((string) $config->get('telegram.webhook_path'), '/');
$secret = $config->get('telegram.webhook_secret');
$botToken = $config->get('telegram.bot_token');

if (! is_string($url) || ! str_starts_with($url, 'https://')) {
    throw new RuntimeException('Webhook URL is not HTTPS.');
}

if (! is_string($secret) || strlen($secret) < 32) {
    throw new RuntimeException('Webhook secret is not available.');
}

if (! is_string($botToken) || preg_match('/\A([1-9][0-9]{5,19}):/', $botToken, $matches) !== 1) {
    throw new RuntimeException('Bot identity is not available.');
}

$botId = $matches[1];
$updateId = 900000000000000000 + time();

$database->connection()->table('processed_telegram_updates')
    ->where('bot_id', $botId)
    ->where('update_id', '>=', 900000000000000000)
    ->delete();

set_exception_handler(static function (Throwable $exception) use ($database, $botId, &$updateId): never {
    $database->connection()->table('processed_telegram_updates')
        ->where('bot_id', $botId)
        ->where('update_id', $updateId)
        ->delete();
    fwrite(STDERR, "Telegram contract verification failed.\n");
    exit(1);
});

$requeueStatus = $kernel->call('telegram:updates:requeue', [
    '--older-than' => 0,
    '--limit' => 100,
    '--include-failed' => true,
    '--json' => true,
]);
$requeueOutput = trim($kernel->output());

if ($requeueStatus !== 0) {
    throw new RuntimeException('Telegram stranded-update recovery failed.');
}

$requeueResult = json_decode($requeueOutput, true, 16, JSON_THROW_ON_ERROR);

if (! is_array($requeueResult) || ! is_int($requeueResult['requeued'] ?? null)) {
    throw new RuntimeException('Telegram stranded-update recovery returned invalid output.');
}

echo 'stranded_updates_requeued='.$requeueResult['requeued'].PHP_EOL;

$payload = [
    'update_id' => $updateId,
    'poll' => [
        'id' => 'staging-contract-'.$updateId,
        'question' => 'staging-contract',
        'options' => [],
        'total_voter_count' => 0,
        'is_closed' => false,
        'is_anonymous' => true,
        'type' => 'regular',
        'allows_multiple_answers' => false,
    ],
];

/** @param array<string, mixed> $body */
function postUpdate(string $url, string $secret, array $body): array
{
    $handle = curl_init($url);

    if ($handle === false) {
        throw new RuntimeException('Unable to initialize webhook request.');
    }

    $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Telegram-Bot-Api-Secret-Token: '.$secret,
        ],
        CURLOPT_POSTFIELDS => $encoded,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);

    $responseBody = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if (! is_string($responseBody) || $error !== '') {
        throw new RuntimeException('Webhook request failed.');
    }

    return [$status, $responseBody];
}

[$acceptedStatus, $acceptedBody] = postUpdate($url, $secret, $payload);
[$duplicateStatus, $duplicateBody] = postUpdate($url, $secret, $payload);
$collision = $payload;
$collision['poll']['question'] = 'different-contract';
[$collisionStatus] = postUpdate($url, $secret, $collision);

if ($acceptedStatus !== 200 || json_decode($acceptedBody, true) !== ['ok' => true]) {
    throw new RuntimeException('Valid webhook request was not accepted.');
}

if ($duplicateStatus !== 200 || json_decode($duplicateBody, true) !== ['ok' => true]) {
    throw new RuntimeException('Exact duplicate was not acknowledged.');
}

if ($collisionStatus !== 409) {
    throw new RuntimeException('Update collision was not rejected.');
}

$record = null;
$deadline = time() + 90;

while (time() <= $deadline) {
    $record = $database->connection()->table('processed_telegram_updates')
        ->where('bot_id', $botId)
        ->where('update_id', $updateId)
        ->first(['state', 'attempt_count', 'last_error_class']);

    if ($record !== null && in_array((string) $record->state, ['processed', 'failed'], true)) {
        break;
    }

    usleep(500_000);
}

if ($record === null || (string) $record->state !== 'processed') {
    throw new RuntimeException('Synthetic Telegram update was not processed by a worker.');
}

if ($record->last_error_class !== null) {
    throw new RuntimeException('Synthetic Telegram update recorded an error.');
}

$rows = $database->connection()->table('processed_telegram_updates')
    ->where('bot_id', $botId)
    ->where('update_id', $updateId)
    ->count();

if ($rows !== 1) {
    throw new RuntimeException('Synthetic Telegram update was not deduplicated.');
}

$database->connection()->table('processed_telegram_updates')
    ->where('bot_id', $botId)
    ->where('update_id', $updateId)
    ->delete();

echo 'valid_secret_http='.$acceptedStatus.PHP_EOL;
echo 'exact_duplicate_http='.$duplicateStatus.PHP_EOL;
echo 'collision_http='.$collisionStatus.PHP_EOL;
echo 'synthetic_row_count='.$rows.PHP_EOL;
echo 'synthetic_state=processed'.PHP_EOL;
echo 'synthetic_attempt_count='.(int) $record->attempt_count.PHP_EOL;
echo 'synthetic_cleanup=complete'.PHP_EOL;
PHP
chmod 600 "$VERIFY_SCRIPT"
"$PHP" "$VERIFY_SCRIPT" "$ROOT"

for _attempt in $(seq 1 30); do
    STATUS_OUTPUT=$(runuser -u www -- "$PHP" "$ARTISAN" telegram:webhook:configure --status-only --json --no-ansi --no-interaction)
    PENDING=$(python3 - "$STATUS_OUTPUT" <<'PY'
import json
import sys
print(json.loads(sys.argv[1]).get('pending_update_count', -1))
PY
)
    if [ "$PENDING" = '0' ]; then
        break
    fi
    sleep 2
done

test "$PENDING" = '0'
echo 'final_pending_update_count=0'
echo 'telegram_webhook_contract=passed'

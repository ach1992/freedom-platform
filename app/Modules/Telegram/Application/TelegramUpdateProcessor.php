<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramRuntime;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class TelegramUpdateProcessor
{
    /** @requirement ONB-001 PAY-003 SEC-003 SEC-009 OPS-003 */
    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
        private TelegramIdentitySynchronizer $identitySynchronizer,
        private TelegramInteractionDispatcher $interactionDispatcher,
        private TelegramRuntime $configuration,
    ) {}

    public function process(string $botId, int $updateId): void
    {
        if (! hash_equals($this->configuration->botId(), $botId)) {
            throw new RuntimeException('Telegram update bot identifier is not configured.');
        }

        $record = $this->claim($botId, $updateId);

        if ($record === null) {
            return;
        }

        try {
            $rawPayload = $this->encrypter->decryptString($record['payload_ciphertext']);

            if (! hash_equals($record['payload_hash'], hash('sha256', $rawPayload))) {
                throw new RuntimeException('Telegram update payload integrity check failed.');
            }

            $payload = $this->decode($rawPayload);

            if (($payload['update_id'] ?? null) !== $updateId) {
                throw new RuntimeException('Telegram update identifier does not match the stored payload.');
            }

            $userId = $this->identitySynchronizer->synchronize($botId, $updateId, $payload);
            $this->interactionDispatcher->dispatch($botId, $updateId, $userId, $payload);
            $now = now('UTC')->format('Y-m-d H:i:s.u');

            $this->database->connection()->table('processed_telegram_updates')
                ->where('bot_id', $botId)
                ->where('update_id', $updateId)
                ->update([
                    'state' => 'processed',
                    'processed_at' => $now,
                    'failed_at' => null,
                    'last_error_class' => null,
                    'last_error_code' => null,
                    'updated_at' => $now,
                ]);
        } catch (Throwable $exception) {
            $now = now('UTC')->format('Y-m-d H:i:s.u');
            $this->database->connection()->table('processed_telegram_updates')
                ->where('bot_id', $botId)
                ->where('update_id', $updateId)
                ->update([
                    'state' => 'failed',
                    'failed_at' => $now,
                    'last_error_class' => $exception::class,
                    'last_error_code' => $this->errorCode($exception),
                    'updated_at' => $now,
                ]);

            throw new RuntimeException('Telegram update processing failed.');
        }
    }

    /** @return array{payload_hash: string, payload_ciphertext: string}|null */
    private function claim(string $botId, int $updateId): ?array
    {
        return $this->database->connection()->transaction(function () use ($botId, $updateId): ?array {
            $row = $this->database->connection()->table('processed_telegram_updates')
                ->where('bot_id', $botId)
                ->where('update_id', $updateId)
                ->lockForUpdate()
                ->first([
                    'payload_hash',
                    'payload_ciphertext',
                    'state',
                    'processing_started_at',
                ]);

            if ($row === null) {
                throw new RuntimeException('Telegram update record does not exist.');
            }

            if ((string) $row->state === 'processed') {
                return null;
            }

            $processingStartedAt = is_string($row->processing_started_at) ? strtotime($row->processing_started_at) : false;
            $leaseIsActive = (string) $row->state === 'processing'
                && $processingStartedAt !== false
                && $processingStartedAt > time() - $this->configuration->processingLeaseSeconds();

            if ($leaseIsActive) {
                return null;
            }

            if (! is_string($row->payload_ciphertext) || $row->payload_ciphertext === '') {
                throw new RuntimeException('Telegram update payload is unavailable.');
            }

            $now = now('UTC')->format('Y-m-d H:i:s.u');
            $this->database->connection()->table('processed_telegram_updates')
                ->where('bot_id', $botId)
                ->where('update_id', $updateId)
                ->update([
                    'state' => 'processing',
                    'processing_started_at' => $now,
                    'attempt_count' => $this->database->connection()->raw('attempt_count + 1'),
                    'updated_at' => $now,
                ]);

            return [
                'payload_hash' => (string) $row->payload_hash,
                'payload_ciphertext' => $row->payload_ciphertext,
            ];
        });
    }

    /** @return array<string, mixed> */
    private function decode(string $rawPayload): array
    {
        try {
            $payload = json_decode($rawPayload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Telegram update payload cannot be decoded.');
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('Telegram update payload must be an object.');
        }

        return $payload;
    }

    private function errorCode(Throwable $exception): string
    {
        return substr(hash('sha256', $exception::class), 0, 32);
    }
}

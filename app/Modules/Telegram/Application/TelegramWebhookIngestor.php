<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Exceptions\InvalidTelegramWebhookPayload;
use App\Modules\Telegram\Application\Exceptions\TelegramUpdateCollision;
use App\Modules\Telegram\Application\Jobs\ProcessTelegramUpdateJob;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use JsonException;

final readonly class TelegramWebhookIngestor
{
    /** @requirement ONB-001 PAY-003 SEC-003 SEC-009 ARCH-004 */
    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
        private TelegramRuntimeConfiguration $configuration,
    ) {}

    public function ingest(string $rawPayload, string $correlationId): TelegramWebhookReceipt
    {
        $payload = $this->decode($rawPayload);
        $updateId = $payload['update_id'] ?? null;

        if (! is_int($updateId) || $updateId < 0) {
            throw new InvalidTelegramWebhookPayload('Telegram update_id must be a non-negative integer.');
        }

        $payloadHash = hash('sha256', $rawPayload);
        $now = now('UTC')->format('Y-m-d H:i:s.u');
        $ciphertext = $this->encrypter->encryptString($rawPayload);

        $duplicate = $this->database->connection()->transaction(function () use (
            $updateId,
            $payloadHash,
            $ciphertext,
            $correlationId,
            $rawPayload,
            $now,
        ): bool {
            $inserted = $this->database->connection()->table('processed_telegram_updates')->insertOrIgnore([
                'bot_id' => $this->configuration->botId,
                'update_id' => $updateId,
                'payload_hash' => $payloadHash,
                'payload_ciphertext' => $ciphertext,
                'payload_size' => strlen($rawPayload),
                'state' => 'accepted',
                'correlation_id' => $correlationId,
                'received_at' => $now,
                'attempt_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                return false;
            }

            $existing = $this->database->connection()->table('processed_telegram_updates')
                ->where('bot_id', $this->configuration->botId)
                ->where('update_id', $updateId)
                ->lockForUpdate()
                ->first(['payload_hash']);

            if ($existing === null || ! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                throw new TelegramUpdateCollision('Telegram update identifier was reused with a different payload.');
            }

            return true;
        });

        ProcessTelegramUpdateJob::dispatch($this->configuration->botId, $updateId)
            ->onQueue($this->configuration->queue)
            ->afterCommit();

        $this->database->connection()->table('processed_telegram_updates')
            ->where('bot_id', $this->configuration->botId)
            ->where('update_id', $updateId)
            ->whereNull('queued_at')
            ->update([
                'queued_at' => $now,
                'state' => 'queued',
                'updated_at' => $now,
            ]);

        return new TelegramWebhookReceipt($this->configuration->botId, $updateId, $duplicate);
    }

    /** @return array<string, mixed> */
    private function decode(string $rawPayload): array
    {
        try {
            $decoded = json_decode($rawPayload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidTelegramWebhookPayload('Telegram webhook body must be valid JSON.');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidTelegramWebhookPayload('Telegram webhook body must be a JSON object.');
        }

        return $decoded;
    }
}

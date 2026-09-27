<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\Clock;
use DateInterval;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramUpdatePayloadRetentionService
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function terminalizeFailedPayloads(int $olderThanSeconds, int $limit): int
    {
        if ($olderThanSeconds < 1) {
            throw new InvalidArgumentException('Telegram failed-update retention age must be positive.');
        }
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Telegram failed-update retention batch size must be between 1 and 1000.');
        }

        $now = $this->clock->now();
        $cutoff = $now->sub(new DateInterval('PT'.$olderThanSeconds.'S'))->format('Y-m-d H:i:s.u');
        $candidates = $this->database->connection()->table('processed_telegram_updates')
            ->where('state', 'failed')
            ->whereNotNull('failed_at')
            ->where('failed_at', '<=', $cutoff)
            ->whereNotNull('payload_ciphertext')
            ->orderBy('failed_at')
            ->orderBy('bot_id')
            ->orderBy('update_id')
            ->limit($limit)
            ->get(['bot_id', 'update_id']);

        $terminalized = 0;
        foreach ($candidates as $candidate) {
            $changed = $this->database->connection()->transaction(function () use ($candidate, $cutoff, $now): bool {
                $row = $this->database->connection()->table('processed_telegram_updates')
                    ->where('bot_id', (string) $candidate->bot_id)
                    ->where('update_id', (int) $candidate->update_id)
                    ->lockForUpdate()
                    ->first(['state', 'failed_at', 'payload_ciphertext']);

                if ($row === null
                    || (string) $row->state !== 'failed'
                    || ! is_string($row->failed_at)
                    || $row->failed_at > $cutoff
                    || ! is_string($row->payload_ciphertext)
                    || $row->payload_ciphertext === '') {
                    return false;
                }

                $updated = $this->database->connection()->table('processed_telegram_updates')
                    ->where('bot_id', (string) $candidate->bot_id)
                    ->where('update_id', (int) $candidate->update_id)
                    ->where('state', 'failed')
                    ->update([
                        'state' => 'failed_terminal',
                        'payload_ciphertext' => null,
                        'payload_size' => null,
                        'interaction_rate_authorized_at' => null,
                        'processing_started_at' => null,
                        'updated_at' => $now->format('Y-m-d H:i:s.u'),
                    ]);

                if ($updated !== 1) {
                    throw new RuntimeException('Telegram failed-update retention lost its exact row state.');
                }

                return true;
            }, 3);

            if ($changed) {
                $terminalized++;
            }
        }

        return $terminalized;
    }
}

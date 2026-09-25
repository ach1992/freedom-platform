<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramServiceReconfigurationExecution
{
    public function __construct(
        public string $servicePublicId,
        public string $operationPublicId,
        public string $state,
        public bool $replayed,
    ) {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $servicePublicId) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $operationPublicId) !== 1
            || ! in_array($state, [
                'queued', 'running', 'uncertain_remote_result', 'retry_scheduled',
                'succeeded', 'failed_final', 'needs_review', 'compensating', 'compensated',
            ], true)) {
            throw new InvalidArgumentException('Telegram Service reconfiguration execution is invalid.');
        }
    }
}

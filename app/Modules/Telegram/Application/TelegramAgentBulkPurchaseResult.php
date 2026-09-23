<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramAgentBulkPurchaseResult
{
    public function __construct(
        public string $bulkOrderPublicId,
        public int $succeededCount,
        public int $failedCount,
        public bool $replayed,
    ) {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $bulkOrderPublicId) !== 1
            || $succeededCount < 0
            || $failedCount < 0
            || ($succeededCount + $failedCount) < 1) {
            throw new InvalidArgumentException('Telegram Agent bulk purchase result is invalid.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramAgentBulkPurchaseCandidate
{
    public function __construct(
        public string $purchaseSettlementPublicId,
        public string $offeringCode,
        public int $amountIrr,
        public string $settledAt,
    ) {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $purchaseSettlementPublicId) !== 1) {
            throw new InvalidArgumentException('Telegram Agent bulk purchase settlement public ID is invalid.');
        }
        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $offeringCode) !== 1 || $amountIrr < 1) {
            throw new InvalidArgumentException('Telegram Agent bulk purchase commercial facts are invalid.');
        }
        if ($settledAt === '') {
            throw new InvalidArgumentException('Telegram Agent bulk purchase settlement timestamp is invalid.');
        }
    }
}

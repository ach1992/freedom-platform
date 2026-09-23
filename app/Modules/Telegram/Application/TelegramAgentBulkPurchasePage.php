<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramAgentBulkPurchasePage
{
    /** @param list<TelegramAgentBulkPurchaseCandidate> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $totalPages,
        public int $totalItems,
    ) {
        if ($page < 1 || $totalPages < 1 || $page > $totalPages || $totalItems < 0) {
            throw new InvalidArgumentException('Telegram Agent bulk purchase page metadata is invalid.');
        }
        foreach ($items as $item) {
            if (! $item instanceof TelegramAgentBulkPurchaseCandidate) {
                throw new InvalidArgumentException('Telegram Agent bulk purchase page item is invalid.');
            }
        }
    }
}

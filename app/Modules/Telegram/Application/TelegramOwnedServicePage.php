<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramOwnedServicePage
{
    /** @param list<TelegramOwnedServiceListItem> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $totalPages,
        public int $totalItems,
    ) {
        if ($page < 1 || $totalPages < 1 || $page > $totalPages || $totalItems < 0) {
            throw new InvalidArgumentException('Telegram owned Service page metadata is invalid.');
        }
        foreach ($items as $item) {
            if (! $item instanceof TelegramOwnedServiceListItem) {
                throw new InvalidArgumentException('Telegram owned Service page item is invalid.');
            }
        }
    }
}

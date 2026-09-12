<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerTrialCatalogPage
{
    /** @param list<TelegramCustomerTrialOffering> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $totalPages,
        public int $totalItems,
    ) {
        if ($page < 1 || $totalPages < 1 || $page > $totalPages || $totalItems < 0) {
            throw new InvalidArgumentException('Telegram Trial catalog page metadata is invalid.');
        }
        foreach ($items as $item) {
            if (! $item instanceof TelegramCustomerTrialOffering) {
                throw new InvalidArgumentException('Telegram Trial catalog page item is invalid.');
            }
        }
    }
}

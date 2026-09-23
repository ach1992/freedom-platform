<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use InvalidArgumentException;

final readonly class ClientGuideCatalogPage
{
    /** @param list<ClientGuideResourceView> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $totalPages,
        public int $totalItems,
    ) {
        if ($page < 1 || $totalPages < 1 || $page > $totalPages || $totalItems < 0) {
            throw new InvalidArgumentException('Client-guide page metadata is invalid.');
        }
    }
}

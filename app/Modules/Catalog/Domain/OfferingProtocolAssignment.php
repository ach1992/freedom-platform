<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use InvalidArgumentException;

final readonly class OfferingProtocolAssignment
{
    public function __construct(
        public int $protocolProfileId,
        public bool $customerSelectable,
        public bool $default,
    ) {
        if ($protocolProfileId < 1) {
            throw new InvalidArgumentException('Protocol profile ID must be positive.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application\Contracts;

final readonly class GiftCardProviderCapabilities
{
    public function __construct(
        public bool $validate,
        public bool $reserve,
        public bool $redeem,
        public bool $release,
        public bool $status,
    ) {}
}

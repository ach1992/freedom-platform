<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\OrderSourceType;
use App\Modules\Orders\Domain\OrderState;
use App\Shared\Domain\Money;

final readonly class NonPaidOrderReceipt
{
    public function __construct(
        public int $orderId,
        public string $orderPublicId,
        public string $orderItemPublicId,
        public int $sourceAuthorizationId,
        public string $sourceAuthorizationPublicId,
        public OrderSourceType $sourceType,
        public int $userId,
        public int $planOfferingId,
        public OrderState $state,
        public int $stateVersion,
        public Money $commercialAmount,
        public bool $replayed = false,
    ) {}
}

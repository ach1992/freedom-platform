<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\OrderState;
use App\Shared\Domain\Money;

final readonly class PurchaseOrderOpeningReceipt
{
    public function __construct(
        public int $orderId,
        public string $orderPublicId,
        public string $orderItemPublicId,
        public string $sourceQuotePublicId,
        public int $userId,
        public OrderState $state,
        public int $stateVersion,
        public Money $commercialAmount,
        public bool $replayed = false,
    ) {}
}

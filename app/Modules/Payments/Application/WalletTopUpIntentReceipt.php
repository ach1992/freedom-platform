<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PaymentIntentState;
use App\Shared\Domain\Money;

final readonly class WalletTopUpIntentReceipt
{
    public function __construct(
        public string $intentPublicId,
        public PaymentIntentState $state,
        public int $userId,
        public int $walletAccountId,
        public string $providerCode,
        public Money $amount,
        public bool $replayed = false,
    ) {}
}

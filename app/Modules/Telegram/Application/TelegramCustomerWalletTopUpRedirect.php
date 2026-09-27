<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramCustomerWalletTopUpRedirect
{
    public function __construct(
        public string $requestPublicId,
        public string $paymentIntentPublicId,
        public string $state,
        public int $amountIrr,
        public ?string $redirectUrl,
        public bool $replayed,
        public bool $manualReviewRequired,
    ) {}
}

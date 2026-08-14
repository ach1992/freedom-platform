<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

final readonly class CardToCardBankTransactionReceipt
{
    public function __construct(
        public int $transactionId,
        public string $publicId,
        public string $providerCode,
        public string $providerTransactionId,
        public string $destinationPublicId,
        public int $amountIrr,
        public string $status,
        public bool $replayed,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application;

final readonly class GiftCardProcessingReceipt
{
    public function __construct(
        public string $submissionPublicId,
        public string $state,
        public ?string $reviewPublicId,
        public ?string $redemptionPublicId,
        public ?string $purchaseSettlementPublicId,
        public bool $replayed,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

final readonly class UsdtProcessingReceipt
{
    public function __construct(
        public string $submissionPublicId,
        public string $state,
        public ?string $reviewPublicId,
        public ?string $verifiedTransferPublicId,
        public ?string $purchaseSettlementPublicId,
        public bool $replayed,
    ) {}
}

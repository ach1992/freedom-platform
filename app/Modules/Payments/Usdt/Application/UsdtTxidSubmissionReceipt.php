<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

final readonly class UsdtTxidSubmissionReceipt
{
    public function __construct(
        public int $submissionId,
        public string $publicId,
        public string $authorityPublicId,
        public string $paymentIntentPublicId,
        public string $txid,
        public string $state,
        public bool $replayed,
    ) {}
}

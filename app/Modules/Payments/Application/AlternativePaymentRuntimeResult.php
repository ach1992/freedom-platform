<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

final readonly class AlternativePaymentRuntimeResult
{
    public function __construct(
        public int $c2cProvidersPolled,
        public int $c2cTransactionsIngested,
        public int $c2cMatches,
        public int $c2cCaptures,
        public int $c2cReviews,
        public int $giftCardSubmissionsProcessed,
        public int $giftCardSubmissionsReconciled,
        public int $failures,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use DateTimeImmutable;
use DomainException;

final readonly class AdministratorWalletRefundCandidate
{
    public function __construct(
        public string $selectionToken,
        public int $remainingIrr,
        public DateTimeImmutable $settledAt,
    ) {
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1 || $remainingIrr < 1) {
            throw new DomainException('Administrator wallet refund candidate is invalid.');
        }
    }
}

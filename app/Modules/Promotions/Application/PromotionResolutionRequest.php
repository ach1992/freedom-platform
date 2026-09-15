<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Modules\Promotions\Domain\PromotionAction;
use InvalidArgumentException;

final readonly class PromotionResolutionRequest
{
    public function __construct(
        public string $resolutionKey,
        public int $userId,
        public int $planOfferingId,
        public PromotionAction $action,
        public int $inputPriceIrr,
        public int $observedTotalUses,
        public int $observedUserUses,
        public bool $hasPriorSuccessfulPurchase,
        public ?string $referralSourceCode = null,
    ) {
        if (preg_match('/\A[A-Za-z0-9:_.-]{8,128}\z/', $resolutionKey) !== 1) {
            throw new InvalidArgumentException('Promotion resolution key is invalid.');
        }
        if ($userId < 1 || $planOfferingId < 1) {
            throw new InvalidArgumentException('Promotion resolution identifiers must be positive.');
        }
        if ($inputPriceIrr < 0) {
            throw new InvalidArgumentException('Promotion resolution price must be non-negative integer IRR.');
        }
        if ($observedTotalUses < 0 || $observedUserUses < 0 || $observedUserUses > $observedTotalUses) {
            throw new InvalidArgumentException('Promotion observed usage counts are invalid.');
        }
        if ($referralSourceCode !== null && preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $referralSourceCode) !== 1) {
            throw new InvalidArgumentException('Promotion referral source code is invalid.');
        }
    }
}

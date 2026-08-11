<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Application;

use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;

final readonly class BenefitCodeRedemptionReceipt
{
    public function __construct(
        public int $redemptionId,
        public string $redemptionPublicId,
        public string $codePublicId,
        public int $userId,
        public string $campaignCode,
        public int $campaignVersion,
        public BenefitCodeType $type,
        public ?int $ledgerTransactionId,
        public ?string $entitlementPublicId,
        public ?string $discountGrantPublicId,
        public bool $replayed,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Modules\Promotions\Domain\PromotionRuleKind;

final readonly class PromotionResolutionReceipt
{
    public function __construct(
        public int $resolutionId,
        public string $resolutionPublicId,
        public string $resolutionKey,
        public int $userId,
        public int $planOfferingId,
        public int $inputPriceIrr,
        public int $discountIrr,
        public ?string $ruleCode,
        public ?PromotionRuleKind $ruleKind,
        public ?int $ruleVersion,
        public ?string $ruleConfigurationHash,
        public string $configurationSnapshotHash,
        public bool $replayed,
    ) {}

    public function matched(): bool
    {
        return $this->ruleCode !== null;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Modules\Promotions\Domain\PromotionRuleKind;
use App\Modules\Promotions\Domain\PromotionRuleState;

final readonly class PromotionRuleVersionReceipt
{
    public function __construct(
        public int $ruleId,
        public string $rulePublicId,
        public string $ruleCode,
        public PromotionRuleKind $kind,
        public int $versionId,
        public int $version,
        public PromotionRuleState $state,
        public string $configurationHash,
        public bool $replayed,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Application;

use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeState;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;

final readonly class BenefitCodeCampaignVersionReceipt
{
    public function __construct(
        public int $campaignId,
        public string $campaignPublicId,
        public string $campaignCode,
        public BenefitCodeType $type,
        public int $version,
        public BenefitCodeState $state,
        public string $configurationHash,
        public bool $replayed,
    ) {}
}

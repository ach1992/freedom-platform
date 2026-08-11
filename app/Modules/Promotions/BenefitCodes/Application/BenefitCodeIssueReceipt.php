<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Application;

final readonly class BenefitCodeIssueReceipt
{
    /** @param list<BenefitCodeIssuedItem> $items */
    public function __construct(
        public int $issuanceId,
        public string $issuancePublicId,
        public string $campaignCode,
        public int $campaignVersion,
        public array $items,
        public bool $replayed,
    ) {}

    public function oneTimeCodesAvailable(): bool
    {
        foreach ($this->items as $item) {
            if ($item->fullCode !== null) {
                return true;
            }
        }

        return false;
    }
}

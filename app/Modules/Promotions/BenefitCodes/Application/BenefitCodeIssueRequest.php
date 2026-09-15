<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Application;

use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeCampaignCode;
use InvalidArgumentException;

final readonly class BenefitCodeIssueRequest
{
    /** @param list<string> $ownerChosenCodes */
    public function __construct(
        public string $issuanceKey,
        public BenefitCodeCampaignCode $campaignCode,
        public int $quantity,
        public array $ownerChosenCodes = [],
    ) {
        if (preg_match('/\A[A-Za-z0-9:_-]{8,128}\z/', $issuanceKey) !== 1) {
            throw new InvalidArgumentException('Benefit code issuance key is invalid.');
        }
        if ($quantity < 1 || $quantity > 500) {
            throw new InvalidArgumentException('Benefit code issuance quantity must be between 1 and 500.');
        }
        if ($ownerChosenCodes !== [] && count($ownerChosenCodes) !== $quantity) {
            throw new InvalidArgumentException('Owner-chosen benefit code count must equal the issuance quantity.');
        }
    }
}

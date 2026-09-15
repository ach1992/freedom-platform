<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\QuoteAction;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use DateTimeImmutable;

final readonly class QuoteReceipt
{
    public function __construct(
        public int $quoteId,
        public string $quotePublicId,
        public string $quoteKey,
        public int $userId,
        public string $accountType,
        public QuoteAction $action,
        public int $planOfferingId,
        public string $offeringCode,
        public int $offeringVersion,
        public string $offeringConfigurationHash,
        public bool $offeringDiscountEligible,
        public int $basePriceIrr,
        public QuoteOverrideSource $overrideSource,
        public ?string $overrideReferenceCode,
        public ?int $overridePriceIrr,
        public int $effectivePriceIrr,
        public ?string $discountReferenceCode,
        public int $discountIrr,
        public int $finalPriceIrr,
        public string $currency,
        public string $configurationSnapshotHash,
        public ?QuoteAgentPricingSnapshot $agentPricing,
        public ?ServicePackageQuoteSnapshot $servicePackage,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $expiresAt,
        public bool $replayed,
    ) {}

    public function isExpiredAt(DateTimeImmutable $time): bool
    {
        return $time >= $this->expiresAt;
    }
}

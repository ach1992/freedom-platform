<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PaymentEligibilityAction;

final readonly class PaymentEligibilityDecisionReceipt
{
    /** @param list<EligiblePaymentMethodReceipt> $eligibleMethods */
    public function __construct(
        public int $decisionId,
        public string $publicId,
        public string $decisionKey,
        public int $userId,
        public string $quotePublicId,
        public PaymentEligibilityAction $action,
        public int $amountIrr,
        public string $currency,
        public array $eligibleMethods,
        public string $configurationSnapshotHash,
        public bool $replayed,
    ) {}

    /** @return list<string> */
    public function eligibleMethodCodes(): array
    {
        return array_map(
            static fn (EligiblePaymentMethodReceipt $method): string => $method->methodCode,
            $this->eligibleMethods,
        );
    }

    public function hasEligibleMethods(): bool
    {
        return $this->eligibleMethods !== [];
    }
}

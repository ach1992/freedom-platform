<?php

declare(strict_types=1);

namespace App\Modules\Payments\Eligibility\Application;

final readonly class PaymentEligibilityDecisionReceipt
{
    /**
     * @param  list<array{method_code:string, method_version:int, route_order:int, reason:string}>  $methods
     */
    public function __construct(
        public int $decisionId,
        public string $publicId,
        public string $decisionKey,
        public string $sourceQuotePublicId,
        public int $userId,
        public array $methods,
        public string $configurationSnapshotHash,
        public bool $replayed,
    ) {}
}

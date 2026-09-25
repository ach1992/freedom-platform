<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

final readonly class ServiceReconfigurationQuoteSnapshot
{
    public function __construct(
        public int $previewId,
        public string $previewPublicId,
        public int $serviceSubscriptionId,
        public string $serviceSubscriptionPublicId,
        public int $sourceServiceTargetId,
        public int $sourceRemoteIdentityGeneration,
        public int $sourceLifecycleVersion,
        public int $sourceMutationGeneration,
        public ?int $sourceRouteSelectionId,
        public int $targetRouteSelectionId,
        public int $targetServiceTargetId,
        public int $targetProtocolProfileId,
        public int $priceDifferenceIrr,
        public int $operationFeeIrr,
        public int $totalPriceIrr,
        public bool $changesPlan,
        public bool $changesTarget,
        public bool $changesProtocol,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\QuoteAction;

final readonly class ServicePackageQuoteSnapshot
{
    public function __construct(
        public QuoteAction $action,
        public int $serviceSubscriptionId,
        public string $serviceSubscriptionPublicId,
        public int $serviceTargetId,
        public int $remoteIdentityGeneration,
        public int $lifecycleVersion,
        public int $packageId,
        public string $packageCode,
        public string $packageType,
        public ?int $durationDays,
        public ?int $dataBytes,
        public ?string $requiredCapabilityCode,
    ) {}
}

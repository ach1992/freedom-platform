<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

final readonly class ServiceImportReceipt
{
    public function __construct(
        public int $importId,
        public string $importPublicId,
        public string $state,
        public int $userId,
        public int $planOfferingId,
        public int $serviceTargetId,
        public string $remoteServiceId,
        public string $remoteUsername,
        public ?string $serviceSubscriptionPublicId = null,
        public ?string $orderPublicId = null,
        public bool $replayed = false,
    ) {}
}

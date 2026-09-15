<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

final readonly class ServiceOwnershipTransferReceipt
{
    public function __construct(
        public int $transferId,
        public string $transferPublicId,
        public string $serviceSubscriptionPublicId,
        public int $fromUserId,
        public int $toUserId,
        public int $remoteIdentityGeneration,
        public int $lifecycleVersion,
        public bool $replayed = false,
    ) {}
}

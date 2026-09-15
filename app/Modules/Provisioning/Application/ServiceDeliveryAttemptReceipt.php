<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ServiceDeliveryPurpose;

final readonly class ServiceDeliveryAttemptReceipt
{
    public function __construct(
        public string $servicePublicId,
        public string $attemptPublicId,
        public ServiceDeliveryPurpose $purpose,
        public int $targetRemoteIdentityGeneration,
        public int $targetLifecycleVersion,
        public string $outboxEventId,
        public bool $replayed,
    ) {}
}

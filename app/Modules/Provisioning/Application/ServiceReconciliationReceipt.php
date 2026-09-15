<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

final readonly class ServiceReconciliationReceipt
{
    public function __construct(
        public int $caseId,
        public string $casePublicId,
        public string $serviceSubscriptionPublicId,
        public string $remoteDisposition,
        public string $state,
        public string $beforeRemoteServiceId,
        public ?string $proposedRemoteServiceId,
        public int $targetRemoteIdentityGeneration,
        public int $targetLifecycleVersion,
        public bool $replayed = false,
    ) {}
}

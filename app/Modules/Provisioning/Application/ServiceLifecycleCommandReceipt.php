<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

final readonly class ServiceLifecycleCommandReceipt
{
    public function __construct(
        public ServiceMutationReceipt $mutation,
        public int $auditLogId,
        public bool $auditReplayed,
    ) {}
}

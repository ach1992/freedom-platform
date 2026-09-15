<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

final readonly class ServiceDeliveryResendReceipt
{
    public function __construct(
        public ServiceDeliveryAttemptReceipt $attempt,
        public int $auditLogId,
        public bool $auditReplayed,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ServiceSyncResolutionAction;

final readonly class ServiceSyncResolutionReceipt
{
    public function __construct(
        public string $anomalyPublicId,
        public string $servicePublicId,
        public ServiceSyncResolutionAction $action,
        public string $state,
        public bool $replayed,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceMutationType;

final readonly class ServiceMutationReceipt
{
    public function __construct(
        public string $servicePublicId,
        public string $operationPublicId,
        public ServiceMutationType $type,
        public int $generation,
        public ProvisioningState $state,
        public int $stateVersion,
        public bool $replayed,
    ) {}
}

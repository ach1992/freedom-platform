<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ProvisioningState;

final readonly class InitialProvisioningExecutionReceipt
{
    public function __construct(
        public int $operationId,
        public string $operationPublicId,
        public ProvisioningState $state,
        public int $stateVersion,
        public int $attemptCount,
        public ?int $routeSelectionId,
        public ?int $serviceTargetId,
        public ?string $remoteServiceId,
        public ?string $resultCode,
        public bool $replayed,
    ) {}
}

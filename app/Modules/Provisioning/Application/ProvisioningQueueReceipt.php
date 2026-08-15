<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Orders\Domain\OrderState;
use App\Modules\Provisioning\Domain\ProvisioningState;

final readonly class ProvisioningQueueReceipt
{
    public function __construct(
        public int $orderId,
        public string $orderPublicId,
        public string $orderItemPublicId,
        public int $serviceSubscriptionId,
        public string $serviceSubscriptionPublicId,
        public int $provisioningOperationId,
        public string $provisioningOperationPublicId,
        public string $outboxEventId,
        public OrderState $orderState,
        public int $orderStateVersion,
        public ProvisioningState $provisioningState,
        public int $provisioningStateVersion,
        public bool $replayed = false,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ServiceDeliveryEffectState;

final readonly class ServiceDeliveryExecutionReceipt
{
    public function __construct(
        public string $attemptPublicId,
        public ServiceDeliveryEffectState $state,
        public ?int $telegramMessageId,
        public ?string $resultCode,
        public ?int $retryAfterSeconds,
        public bool $replayed,
    ) {}
}

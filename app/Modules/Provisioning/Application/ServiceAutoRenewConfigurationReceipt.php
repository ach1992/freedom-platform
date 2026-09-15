<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use DateTimeImmutable;

final readonly class ServiceAutoRenewConfigurationReceipt
{
    public function __construct(
        public int $configurationId,
        public string $servicePublicId,
        public bool $enabled,
        public string $packageCode,
        public int $acceptedPriceIrr,
        public ?DateTimeImmutable $observedExpiresAt,
        public int $configurationVersion,
        public bool $replayed,
    ) {}
}

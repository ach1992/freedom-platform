<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

final readonly class ServiceTargetRecord
{
    public function __construct(
        public int $id,
        public int $panelConnectionId,
        public string $code,
        public string $kind,
        public string $nameFa,
        public ?string $nameEn,
        public string $configurationHash,
        public int $configurationKeyVersion,
        public string $state,
        public string $capabilityStatus,
        public int $version,
    ) {}
}

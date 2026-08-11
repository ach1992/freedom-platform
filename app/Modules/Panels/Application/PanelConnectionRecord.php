<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

final readonly class PanelConnectionRecord
{
    public function __construct(
        public int $id,
        public string $code,
        public string $providerType,
        public string $nameFa,
        public ?string $nameEn,
        public string $baseUrl,
        public string $tlsPolicy,
        public ?string $customCaDisk,
        public ?string $customCaPath,
        public ?string $certificatePinSha256,
        public string $networkPolicy,
        public string $state,
        public ?string $lastTestStatus,
        public ?string $lastPanelVersion,
        public ?string $lastCapabilitiesHash,
        public ?string $lastTestedAt,
        public int $version,
    ) {}
}

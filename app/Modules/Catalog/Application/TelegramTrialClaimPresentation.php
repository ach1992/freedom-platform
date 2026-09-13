<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

final readonly class TelegramTrialClaimPresentation
{
    public function __construct(
        public string $serverNameFa,
        public ?string $serverNameEn,
        public string $protocolNameFa,
        public ?string $protocolNameEn,
        public ?string $fallbackDisclosureFa,
        public ?string $fallbackDisclosureEn,
    ) {}
}

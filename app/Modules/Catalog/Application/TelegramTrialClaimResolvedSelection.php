<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

final readonly class TelegramTrialClaimResolvedSelection
{
    public function __construct(
        public int $offeringId,
        public ?int $requestedRouteId,
        public ?int $requestedProtocolProfileId,
    ) {}
}

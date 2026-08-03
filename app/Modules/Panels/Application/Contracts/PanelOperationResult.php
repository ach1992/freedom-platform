<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

final readonly class PanelOperationResult
{
    public function __construct(
        public PanelOperationOutcome $outcome,
        public ?RemoteServiceSnapshot $service,
        public ?string $providerCode,
        public ?string $safeMessage,
    ) {}
}

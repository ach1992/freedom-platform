<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use RuntimeException;

final class PanelGatewayRequestFailure extends RuntimeException
{
    public function __construct(
        public readonly PanelOperationOutcome $outcome,
        public readonly string $providerCode,
        string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }
}

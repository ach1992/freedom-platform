<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use InvalidArgumentException;

final readonly class PanelMappedMutationOutcome
{
    public string $providerCode;

    public function __construct(
        public PanelOperationOutcome $outcome,
        string $providerCode,
        public bool $requiresDiscoveryBeforeRetry,
        public bool $manualReview,
    ) {
        if (preg_match('/\A[a-z0-9_.:-]{1,128}\z/', $providerCode) !== 1) {
            throw new InvalidArgumentException('Panel mapped mutation provider code is invalid.');
        }
        if ($requiresDiscoveryBeforeRetry && $outcome !== PanelOperationOutcome::UncertainResult) {
            throw new InvalidArgumentException('Only uncertain mutations may require discovery before retry.');
        }
        if ($manualReview && $outcome === PanelOperationOutcome::Success) {
            throw new InvalidArgumentException('Successful mutations cannot require manual review.');
        }

        $this->providerCode = $providerCode;
    }
}

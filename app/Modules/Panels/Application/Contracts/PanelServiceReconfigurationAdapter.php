<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

/**
 * Optional provider boundary for an atomic Service destination/profile reconfiguration.
 *
 * Implementations MUST apply the requested target and protocol under the supplied idempotency key.
 * A Success result MUST include the authoritative post-change RemoteServiceSnapshot and MUST NOT be
 * returned before the provider has confirmed that the requested destination/profile is effective.
 * Throwing, retryable, or otherwise ambiguous provider outcomes are treated as uncertain effects and
 * require reconciliation before another provider attempt.
 */
interface PanelServiceReconfigurationAdapter
{
    public function reconfigureService(PanelServiceReconfigurationRequest $request): PanelOperationResult;
}

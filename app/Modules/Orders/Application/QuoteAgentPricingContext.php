<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Agents\Domain\AgentPricingAction;
use InvalidArgumentException;

/** @requirement AGT-005 BUY-002 SEC-002 QUA-001 */
final readonly class QuoteAgentPricingContext
{
    public function __construct(
        public int $actorUserId,
        public AgentPricingAction $action,
    ) {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Quote agent pricing actor user ID must be positive.');
        }
    }
}

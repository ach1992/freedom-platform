<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Agents\Application\AgentPricingService;
use App\Shared\Application\Clock;
use Illuminate\Database\DatabaseManager;

final readonly class QuoteService
{
    use QuoteServiceAgentPricing;
    use QuoteServiceCreatesQuotes;
    use QuoteServiceReadsQuotes;
    use QuoteServiceServicePackages;
    use QuoteServiceSupport;

    private const FORMULA_VERSION = 'buy-002-v1';

    private const AGENT_FORMULA_VERSION = 'buy-002-agent-pricing-v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AgentPricingService $agentPricingService,
    ) {}
}

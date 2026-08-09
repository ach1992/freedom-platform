<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Agents\Application\AgentPricingResolutionContext;
use App\Modules\Agents\Application\AgentPricingResolutionRequest;
use App\Modules\Agents\Application\AgentPricingService;
use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Agents\Domain\AgentPricingProfileDefinition;
use App\Modules\Agents\Domain\AgentPricingRuleDefinition;
use App\Modules\Agents\Domain\AgentPricingState;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** @requirement AGT-005 BUY-002 ACL-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
final class AgentPricingResolutionFoundationTest extends TestCase
{
    use RefreshDatabase;
    use AgentPricingResolutionSelectionScenarios;
    use AgentPricingResolutionIntegrityScenarios;
    use AgentPricingResolutionTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }
}

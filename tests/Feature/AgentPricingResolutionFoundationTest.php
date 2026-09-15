<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** @requirement AGT-005 BUY-002 ACL-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
final class AgentPricingResolutionFoundationTest extends TestCase
{
    use AgentPricingResolutionIntegrityScenarios;
    use AgentPricingResolutionSelectionScenarios;
    use AgentPricingResolutionTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }
}

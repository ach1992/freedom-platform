<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';
require_once __DIR__.'/ServiceAutoRenewalAgentPricingTestSupport.php';
require_once __DIR__.'/ServiceAutoRenewalRuntimeTestSupport.php';
require_once __DIR__.'/ServiceAutoRenewalRuntimeTestHelpers.php';
require_once __DIR__.'/ServiceAutoRenewalRuntimeScenariosA.php';
require_once __DIR__.'/ServiceAutoRenewalRuntimeScenariosB.php';
require_once __DIR__.'/ServiceAutoRenewalRuntimeScenariosC.php';
require_once __DIR__.'/ServiceAutoRenewalRuntimeScenariosD.php';

use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

final class ServiceAutoRenewalRuntimeTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;
    use ServiceAutoRenewalAgentPricingTestSupport;
    use ServiceAutoRenewalRuntimeScenariosA;
    use ServiceAutoRenewalRuntimeScenariosB;
    use ServiceAutoRenewalRuntimeScenariosC;
    use ServiceAutoRenewalRuntimeScenariosD;
    use ServiceAutoRenewalRuntimeTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
        config()->set('auto_renew.window_hours', 24);
        config()->set('auto_renew.quote_ttl_minutes', 15);
        config()->set('auto_renew.batch_limit', 50);
        config()->set('auto_renew.max_retry_count', 5);
        config()->set('auto_renew.retry_initial_delay_minutes', 15);
        config()->set('auto_renew.retry_max_delay_minutes', 240);
    }
}

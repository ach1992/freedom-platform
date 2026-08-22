<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';
require_once __DIR__.'/ServiceAutoRenewalRuntimeTestSupport.php';
require_once __DIR__.'/ServiceAutoRenewalRuntimeTestHelpers.php';

use App\Modules\Provisioning\Application\ServiceAutoRenewDatabaseAuthority;
use App\Modules\Provisioning\Application\ServiceAutoRenewPolicyService;
use App\Modules\Provisioning\Domain\AutoRenewPriceChangeMode;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement SVC-007 DAT-003 SEC-002 QUA-004 */
final class ServiceAutoRenewDatabaseCapabilityVerificationTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;
    use ServiceAutoRenewalRuntimeTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_forged_fixed_policy_session_authority_is_rejected_without_app_key_capability(): void
    {
        $scenario = $this->scenario('forged-policy-capability');
        $administratorId = $this->ownerAdministrator();
        $correlationId = $this->purchaseOrderCorrelation('forged-policy-capability');

        $this->app->make(ServiceAutoRenewPolicyService::class)->configure(
            'service.auto-renew.policy.forged-capability.000001',
            $administratorId,
            $scenario['offering_id'],
            AutoRenewPriceChangeMode::Stop,
            null,
            null,
            'capability_verification',
            'Create an authorized policy before the forged session capability test.',
            $correlationId,
        );

        DB::statement(
            <<<'SQL'
SET @app_service_auto_renew_authority = 'service_auto_renew_policy_v1',
    @app_service_auto_renew_actor_id = ?,
    @app_service_auto_renew_scope_id = ?,
    @app_service_auto_renew_quote_id = NULL,
    @app_service_auto_renew_attempt_id = NULL,
    @app_service_auto_renew_correlation_id = ?,
    @app_service_operational_capability = NULL
SQL,
            [$administratorId, $scenario['offering_id'], $correlationId],
        );

        try {
            DB::table('plan_offering_auto_renew_policies')
                ->where('plan_offering_id', $scenario['offering_id'])
                ->update([
                    'price_change_mode' => AutoRenewPriceChangeMode::Continue->value,
                    'version' => DB::raw('version + 1'),
                    'actor_administrator_id' => $administratorId,
                    'correlation_id' => $correlationId,
                    'updated_at' => $this->purchaseOrderTimestamp(),
                ]);
            self::fail('Forged fixed auto-renew session authority must not replace the app-key capability.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('Auto-renew database capability is invalid.', $exception->getMessage());
        } finally {
            ServiceAutoRenewDatabaseAuthority::clear(DB::connection());
        }
    }
}

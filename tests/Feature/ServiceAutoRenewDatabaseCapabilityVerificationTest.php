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
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function test_valid_runtime_attempt_write_is_rejected_without_app_key_capability(): void
    {
        $scenario = $this->scenario('forged-runtime-capability');
        $configuration = $this->enableAutoRenew($scenario, 'forged-runtime-capability');
        $configRow = DB::table('service_auto_renew_configurations')
            ->where('id', $configuration->configurationId)
            ->first();
        self::assertNotNull($configRow);
        self::assertNotNull($configRow->observed_remote_identity_generation);
        self::assertNotNull($configRow->observed_expires_at);
        self::assertNotNull($configRow->observed_expiry_evidence_hash);
        self::assertNotNull($configRow->observed_expiry_source);

        $cycleKey = hash('sha256', implode('|', [
            (string) $configuration->configurationId,
            (string) $scenario['service_id'],
            (string) $configuration->configurationVersion,
            (string) $configRow->observed_remote_identity_generation,
            (string) $configRow->observed_expires_at,
        ]));
        ServiceAutoRenewDatabaseAuthority::clear(DB::connection());

        try {
            DB::table('service_auto_renew_attempts')->insert([
                'public_id' => (string) Str::ulid(),
                'cycle_key' => $cycleKey,
                'auto_renew_configuration_id' => $configuration->configurationId,
                'service_subscription_id' => $scenario['service_id'],
                'configuration_version' => $configuration->configurationVersion,
                'remote_identity_generation' => (int) $configRow->observed_remote_identity_generation,
                'observed_expires_at' => (string) $configRow->observed_expires_at,
                'observed_expiry_evidence_hash' => (string) $configRow->observed_expiry_evidence_hash,
                'observed_expiry_source' => (string) $configRow->observed_expiry_source,
                'state' => 'pending',
                'reason_code' => null,
                'baseline_price_irr' => $configuration->acceptedPriceIrr,
                'current_price_irr' => null,
                'quote_id' => null,
                'payment_eligibility_decision_id' => null,
                'payment_intent_id' => null,
                'purchase_settlement_id' => null,
                'provisioning_operation_id' => null,
                'correlation_id' => $this->purchaseOrderCorrelation('forged-runtime-capability-attempt'),
                'completed_at' => null,
                'created_at' => $this->purchaseOrderTimestamp(),
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::fail('Valid-shaped runtime evidence must still require the app-key capability.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('Auto-renew runtime database capability is invalid.', $exception->getMessage());
        }
    }

    public function test_runtime_capability_rollback_refuses_with_configuration_before_first_attempt(): void
    {
        $scenario = $this->scenario('runtime-capability-rollback');
        $this->enableAutoRenew($scenario, 'runtime-capability-rollback');
        self::assertSame(0, DB::table('service_auto_renew_attempts')->count());

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_21_000235_require_service_auto_renew_runtime_capability.php');
        try {
            $migration->down();
            self::fail('Runtime capability rollback must refuse while auto-renew configuration authority exists.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Cannot remove Service auto-renew runtime capability guards while auto-renew authority rows exist.',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            1,
            (int) DB::selectOne(
                "SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'sara_cap_insert_guard'",
            )->aggregate,
        );
    }
}

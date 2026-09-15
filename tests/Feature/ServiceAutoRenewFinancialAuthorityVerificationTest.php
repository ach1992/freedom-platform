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
final class ServiceAutoRenewFinancialAuthorityVerificationTest extends TestCase
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
        config()->set('auto_renew.window_hours', 24);
        config()->set('auto_renew.quote_ttl_minutes', 15);
        config()->set('auto_renew.batch_limit', 50);
        config()->set('auto_renew.max_retry_count', 5);
        config()->set('auto_renew.retry_initial_delay_minutes', 15);
        config()->set('auto_renew.retry_max_delay_minutes', 240);

        ServiceAutoRenewDatabaseAuthority::beginRuntime(DB::connection());
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->app)) {
                ServiceAutoRenewDatabaseAuthority::endRuntime(DB::connection());
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_direct_policy_write_is_rejected_after_legitimate_policy_configuration(): void
    {
        $scenario = $this->scenario('financial-policy-authority');
        $administratorId = $this->ownerAdministrator();
        $correlationId = $this->purchaseOrderCorrelation('auto-renew-financial-policy-authority');
        $this->app->make(ServiceAutoRenewPolicyService::class)->configure(
            'service.auto-renew.policy.financial-authority.000001',
            $administratorId,
            $scenario['offering_id'],
            AutoRenewPriceChangeMode::Stop,
            null,
            null,
            'financial_authority_test',
            'Verify direct policy mutation is rejected after the authorized path clears its capability.',
            $correlationId,
        );

        try {
            DB::table('plan_offering_auto_renew_policies')
                ->where('plan_offering_id', $scenario['offering_id'])
                ->update([
                    'price_change_mode' => AutoRenewPriceChangeMode::Continue->value,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => $this->purchaseOrderTimestamp(),
                ]);
            self::fail('Direct auto-renew policy mutation must require explicit administrator authority.');
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'Auto-renew policy mutation requires explicit administrator authority.',
                $exception->getMessage(),
            );
        }
    }

    public function test_direct_configuration_financial_and_observation_authority_writes_are_rejected(): void
    {
        $scenario = $this->scenario('financial-config-authority');
        $configuration = $this->enableAutoRenew($scenario, 'financial-config-authority');
        $stored = DB::table('service_auto_renew_configurations')
            ->where('id', $configuration->configurationId)
            ->first([
                'accepted_price_irr',
                'configuration_version',
                'observed_expires_at',
                'observed_remote_identity_generation',
            ]);
        self::assertNotNull($stored);
        self::assertNotNull($stored->observed_expires_at);
        self::assertNotNull($stored->observed_remote_identity_generation);

        try {
            DB::table('service_auto_renew_configurations')
                ->where('id', $configuration->configurationId)
                ->update([
                    'accepted_price_irr' => (int) $stored->accepted_price_irr + 1,
                    'configuration_version' => (int) $stored->configuration_version + 1,
                    'updated_at' => $this->purchaseOrderTimestamp(),
                ]);
            self::fail('Direct accepted auto-renew price mutation must require explicit owner authority.');
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'Auto-renew configuration mutation requires explicit owner authority.',
                $exception->getMessage(),
            );
        }

        try {
            DB::table('service_auto_renew_configurations')
                ->where('id', $configuration->configurationId)
                ->update([
                    'last_settled_price_irr' => (int) $stored->accepted_price_irr,
                    'last_correlation_id' => $this->purchaseOrderCorrelation('forged-settled-price'),
                    'updated_at' => $this->purchaseOrderTimestamp(),
                ]);
            self::fail('Direct settled auto-renew price mutation must require captured settlement authority.');
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'Auto-renew settled price requires captured settlement authority.',
                $exception->getMessage(),
            );
        }

        try {
            DB::table('service_auto_renew_configurations')
                ->where('id', $configuration->configurationId)
                ->update([
                    'observed_expires_at' => (new \DateTimeImmutable((string) $stored->observed_expires_at))
                        ->modify('+1 hour')
                        ->format('Y-m-d H:i:s.u'),
                    'expiry_observed_at' => $this->purchaseOrderTimestamp(),
                    'observed_expiry_evidence_hash' => hash('sha256', 'forged-auto-renew-observation'),
                    'observed_expiry_source' => 'remote_snapshot',
                    'observed_remote_identity_generation' => (int) $stored->observed_remote_identity_generation,
                    'last_correlation_id' => $this->purchaseOrderCorrelation('forged-observation'),
                    'updated_at' => $this->purchaseOrderTimestamp(),
                ]);
            self::fail('Direct renewal timing mutation must require explicit observation authority.');
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'Auto-renew expiry observation requires explicit observation authority.',
                $exception->getMessage(),
            );
        }
    }
}

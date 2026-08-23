<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';
require_once __DIR__.'/ServiceAutoRenewalAgentPricingTestSupport.php';
require_once __DIR__.'/ServiceAutoRenewalRuntimeTestSupport.php';
require_once __DIR__.'/ServiceAutoRenewalRuntimeTestHelpers.php';

use App\Modules\Provisioning\Application\ServiceAutoRenewalProcessor;
use App\Modules\Provisioning\Application\ServiceNotificationThresholdService;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\WalletFinancialFoundationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement SVC-007 SVC-013 SVC-014 WAL-002 DAT-003 DAT-004 QUA-004 */
final class ServiceNotificationRenewalIntentAuthorityTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;
    use ServiceAutoRenewalAgentPricingTestSupport;
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
        config()->set('service_notifications.low_balance_irr', 0);

        /** @var Migration $cursorMigration */
        $cursorMigration = require database_path('migrations/2026_08_23_000210_enable_service_notification_scan_cursor.php');
        $cursorMigration->down();
        $cursorMigration->up();
        $this->app->forgetInstance(ServiceNotificationThresholdService::class);
    }

    public function test_insufficient_wallet_notification_binds_to_exact_durable_auto_renew_intent(): void
    {
        $scenario = $this->scenario('notification-renewal-insufficient');
        $this->enableWalletMethod('notification-renewal-insufficient');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->fundWallet($scenario['user_id'], 1, 'notification-renewal-insufficient');
        $this->enableAutoRenew($scenario, 'notification-renewal-insufficient');

        $renewals = $this->app->make(ServiceAutoRenewalProcessor::class);
        $renewal = $renewals->processDue(10);
        self::assertSame(1, $renewal->insufficientWallet);

        $intent = DB::table('service_auto_renew_notification_intents as intent')
            ->join('service_auto_renew_attempts as attempt', 'attempt.id', '=', 'intent.auto_renew_attempt_id')
            ->where('attempt.service_subscription_id', $scenario['service_id'])
            ->where('intent.outcome', 'insufficient_wallet')
            ->first(['intent.id', 'attempt.state as attempt_state']);
        self::assertNotNull($intent);
        self::assertSame('insufficient_wallet', $intent->attempt_state);

        $notifications = $this->app->make(ServiceNotificationThresholdService::class);
        $result = $notifications->processBatch(1);
        self::assertSame(1, $result->triggered);
        self::assertSame(1, $result->queued);

        $state = DB::table('service_notification_states')
            ->where('service_subscription_id', $scenario['service_id'])
            ->where('notification_type', 'renewal_failure')
            ->first([
                'id', 'state', 'threshold_code', 'source_type', 'source_id',
                'latest_delivery_attempt_id', 'latest_retry_ordinal',
            ]);
        self::assertNotNull($state);
        self::assertSame('triggered', $state->state);
        self::assertSame('renewal_insufficient_wallet', $state->threshold_code);
        self::assertSame('auto_renew_notification_intent', $state->source_type);
        self::assertSame((int) $intent->id, (int) $state->source_id);
        self::assertNotNull($state->latest_delivery_attempt_id);
        self::assertSame(0, (int) $state->latest_retry_ordinal);

        self::assertSame(1, DB::table('service_notification_delivery_bindings')
            ->where('service_notification_state_id', (int) $state->id)
            ->where('service_delivery_attempt_id', (int) $state->latest_delivery_attempt_id)
            ->count());
    }

    public function test_notification_expires_when_durable_renewal_intent_no_longer_matches_attempt_state(): void
    {
        $scenario = $this->scenario('notification-renewal-reset');
        $this->enableWalletMethod('notification-renewal-reset');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->fundWallet($scenario['user_id'], 1, 'notification-renewal-reset');
        $this->enableAutoRenew($scenario, 'notification-renewal-reset');

        $renewals = $this->app->make(ServiceAutoRenewalProcessor::class);
        self::assertSame(1, $renewals->processDue(10)->insufficientWallet);

        $notifications = $this->app->make(ServiceNotificationThresholdService::class);
        $initial = $notifications->processBatch(1);
        self::assertSame(1, $initial->triggered);
        self::assertSame(1, $initial->queued);
        $stateId = (int) DB::table('service_notification_states')
            ->where('service_subscription_id', $scenario['service_id'])
            ->where('threshold_code', 'renewal_insufficient_wallet')
            ->value('id');
        self::assertGreaterThan(0, $stateId);

        $this->fundWallet($scenario['user_id'], 600_000, 'notification-renewal-reset-recovery');
        $this->purchaseOrderClock->value = $this->purchaseOrderClock->value->modify('+16 minutes');
        $retry = $renewals->processDue(10);
        self::assertSame(1, $retry->queued);
        self::assertNotSame(
            'insufficient_wallet',
            DB::table('service_auto_renew_attempts')
                ->where('service_subscription_id', $scenario['service_id'])
                ->value('state'),
        );

        $expired = $notifications->processBatch(1);
        self::assertSame(0, $expired->triggered);
        self::assertSame(1, $expired->expired);
        self::assertSame('expired', DB::table('service_notification_states')->where('id', $stateId)->value('state'));
        self::assertSame(1, DB::table('service_notification_states')
            ->where('service_subscription_id', $scenario['service_id'])
            ->where('notification_type', 'renewal_failure')
            ->count());
    }
}

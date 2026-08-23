<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';
require_once __DIR__.'/ServiceAutoRenewalAgentPricingTestSupport.php';
require_once __DIR__.'/ServiceAutoRenewalRuntimeTestSupport.php';
require_once __DIR__.'/ServiceAutoRenewalRuntimeTestHelpers.php';

use App\Modules\Provisioning\Application\ServiceAutoRenewalProcessor;
use App\Modules\Provisioning\Application\ServiceAutoRenewDatabaseAuthority;
use App\Modules\Provisioning\Application\ServiceNotificationThresholdService;
use App\Modules\Provisioning\Domain\AutoRenewAttemptState;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\WalletFinancialFoundationSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
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
        $lockOrder = [];
        $this->captureRenewalLockOrder($lockOrder);
        $result = $notifications->processBatch(1);
        self::assertSame(1, $result->triggered);
        self::assertSame(1, $result->queued);
        $this->assertAttemptBeforeServiceLock($lockOrder);

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
        $walletId = $this->fundWallet($scenario['user_id'], 1, 'notification-renewal-reset');
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

        $this->topUpWallet($walletId, 600_000, 'notification-renewal-reset-recovery');
        $this->purchaseOrderClock->value = $this->purchaseOrderClock->value->modify('+16 minutes');
        $retry = $renewals->processDue(10);
        self::assertSame(1, $retry->queued);
        self::assertNotSame(
            'insufficient_wallet',
            DB::table('service_auto_renew_attempts')
                ->where('service_subscription_id', $scenario['service_id'])
                ->value('state'),
        );

        $lockOrder = [];
        $this->captureRenewalLockOrder($lockOrder);
        $expired = $notifications->processBatch(1);
        self::assertSame(0, $expired->triggered);
        self::assertSame(1, $expired->expired);
        $this->assertAttemptBeforeServiceLock($lockOrder);
        self::assertSame('expired', DB::table('service_notification_states')->where('id', $stateId)->value('state'));
        self::assertSame(1, DB::table('service_notification_states')
            ->where('service_subscription_id', $scenario['service_id'])
            ->where('notification_type', 'renewal_failure')
            ->count());
    }

    public function test_stale_resolved_observation_cannot_expire_reactivated_same_renewal_episode(): void
    {
        $scenario = $this->scenario('notification-renewal-reactivation-race');
        $this->enableWalletMethod('notification-renewal-reactivation-race');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->fundWallet($scenario['user_id'], 1, 'notification-renewal-reactivation-race');
        $this->enableAutoRenew($scenario, 'notification-renewal-reactivation-race');

        $renewals = $this->app->make(ServiceAutoRenewalProcessor::class);
        self::assertSame(1, $renewals->processDue(10)->insufficientWallet);
        $attempt = DB::table('service_auto_renew_attempts')
            ->where('service_subscription_id', $scenario['service_id'])
            ->first(['id', 'state']);
        self::assertNotNull($attempt);
        self::assertSame(AutoRenewAttemptState::InsufficientWallet->value, $attempt->state);

        $notifications = $this->app->make(ServiceNotificationThresholdService::class);
        self::assertSame(1, $notifications->processBatch(1)->triggered);
        $state = DB::table('service_notification_states')
            ->where('service_subscription_id', $scenario['service_id'])
            ->where('threshold_code', 'renewal_insufficient_wallet')
            ->first(['id', 'episode_key_hash', 'source_id', 'state']);
        self::assertNotNull($state);
        self::assertSame('triggered', $state->state);
        $intentId = (int) $state->source_id;
        self::assertGreaterThan(0, $intentId);

        // Simulate the observation that made this episode appear resolved. The scanner can carry
        // that stale observation while auto-renew later returns the exact same durable attempt to the
        // same insufficient-wallet outcome.
        $this->forceAutoRenewRetryState(
            $renewals,
            (int) $attempt->id,
            AutoRenewAttemptState::RetryPending,
            'notification_test_retry_pending',
        );
        self::assertSame(
            AutoRenewAttemptState::RetryPending->value,
            DB::table('service_auto_renew_attempts')->where('id', (int) $attempt->id)->value('state'),
        );

        $this->forceAutoRenewRetryState(
            $renewals,
            (int) $attempt->id,
            AutoRenewAttemptState::InsufficientWallet,
            'notification_test_insufficient_again',
        );
        self::assertSame(
            AutoRenewAttemptState::InsufficientWallet->value,
            DB::table('service_auto_renew_attempts')->where('id', (int) $attempt->id)->value('state'),
        );
        self::assertSame(1, DB::table('service_auto_renew_notification_intents')
            ->where('auto_renew_attempt_id', (int) $attempt->id)
            ->where('outcome', 'insufficient_wallet')
            ->where('id', $intentId)
            ->count());

        $service = DB::table('service_subscriptions')
            ->where('id', $scenario['service_id'])
            ->first([
                'id', 'public_id', 'user_id', 'service_target_id', 'remote_service_id', 'provisioned_at',
                'lifecycle_state', 'lifecycle_version', 'remote_identity_generation', 'mutation_generation',
                'remote_deleted_at',
            ]);
        self::assertNotNull($service);

        $expire = new ReflectionMethod($notifications, 'expireStaleTriggeredStates');
        $lockOrder = [];
        $this->captureRenewalLockOrder($lockOrder);
        $expired = $expire->invoke($notifications, $service, []);

        self::assertSame(0, $expired);
        self::assertSame('triggered', DB::table('service_notification_states')->where('id', (int) $state->id)->value('state'));
        self::assertSame($state->episode_key_hash, DB::table('service_notification_states')->where('id', (int) $state->id)->value('episode_key_hash'));
        $this->assertAttemptBeforeServiceLock($lockOrder);
    }

    private function forceAutoRenewRetryState(
        ServiceAutoRenewalProcessor $processor,
        int $attemptId,
        AutoRenewAttemptState $state,
        string $reasonCode,
    ): void {
        $connection = DB::connection();
        ServiceAutoRenewDatabaseAuthority::beginRuntime($connection);

        try {
            $method = new ReflectionMethod($processor, 'scheduleRetryWithBudget');
            $method->invoke($processor, $attemptId, $state, $reasonCode, true);
        } finally {
            ServiceAutoRenewDatabaseAuthority::endRuntime($connection);
        }
    }

    /** @param list<string> $lockOrder */
    private function assertAttemptBeforeServiceLock(array $lockOrder): void
    {
        $attemptIndex = array_search('attempt', $lockOrder, true);
        $serviceIndex = array_search('service', $lockOrder, true);
        if (! is_int($attemptIndex) || ! is_int($serviceIndex)) {
            self::fail(
                'Expected auto-renew Attempt and Service Subscription FOR UPDATE locks: '
                .json_encode($lockOrder, JSON_THROW_ON_ERROR),
            );
        }
        self::assertTrue(
            $attemptIndex < $serviceIndex,
            'Renewal notification lock order must remain Attempt -> Service: '.json_encode($lockOrder, JSON_THROW_ON_ERROR),
        );
    }

    /** @param list<string> $lockOrder */
    private function captureRenewalLockOrder(array &$lockOrder): void
    {
        DB::connection()->beforeExecuting(static function (string $query, array $bindings, Connection $connection) use (&$lockOrder): void {
            unset($bindings, $connection);
            $sql = strtolower($query);
            if (! str_contains($sql, 'for update')) {
                return;
            }
            if (str_contains($sql, 'service_auto_renew_attempts')) {
                $lockOrder[] = 'attempt';

                return;
            }
            if (str_contains($sql, 'service_subscriptions')) {
                $lockOrder[] = 'service';
            }
        });
    }

    private function topUpWallet(int $walletId, int $amountIrr, string $suffix): void
    {
        $now = now('UTC');
        $assetId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'system.auto.renew.wallet.asset.'.$suffix,
            'account_class' => 'asset',
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.auto.renew.wallet.fund.'.$suffix.'.000001',
            'auto_renew_test_funding',
            $this->purchaseOrderCorrelation('auto-renew-wallet-fund-'.$suffix),
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amountIrr)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amountIrr)),
            ],
            'test_fixture',
            'auto-renew-wallet-'.$suffix,
        );
    }
}

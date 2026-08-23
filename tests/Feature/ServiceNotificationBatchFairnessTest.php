<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/ServiceOperationalPanelAdapter.php';

use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Provisioning\Application\ProvisioningPanelAdapterResolver;
use App\Modules\Provisioning\Application\ServiceImportService;
use App\Modules\Provisioning\Application\ServiceNotificationThresholdService;
use App\Modules\Provisioning\Application\ServiceMutationQueueService;
use App\Modules\Provisioning\Application\ServiceOperationalContext;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement SVC-013 SVC-014 WAL-002 DAT-003 QUA-004 */
final class ServiceNotificationBatchFairnessTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config()->set('service_notifications.low_balance_irr', 0);

        /** @var Migration $operationalMigration */
        $operationalMigration = require database_path('migrations/2026_08_19_000140_enable_service_operational_authority.php');
        $operationalMigration->up();

        /** @var Migration $cursorMigration */
        $cursorMigration = require database_path('migrations/2026_08_23_000210_enable_service_notification_scan_cursor.php');
        $cursorMigration->down();
        $cursorMigration->up();
    }

    public function test_bounded_batches_advance_deterministically_and_wrap_without_starvation(): void
    {
        $fixture = $this->fixture();
        for ($index = 1; $index <= 3; $index++) {
            $this->attachService($fixture, $index);
        }

        $eligibleIds = DB::table('service_subscriptions')
            ->whereNotNull('provisioned_at')
            ->whereNotNull('service_target_id')
            ->whereNotNull('remote_service_id')
            ->whereNull('remote_deleted_at')
            ->whereIn('lifecycle_state', ['active', 'suspended'])
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        self::assertGreaterThanOrEqual(3, count($eligibleIds));

        $service = $this->app->make(ServiceNotificationThresholdService::class);
        $observedCursorIds = [];
        for ($iteration = 0; $iteration <= count($eligibleIds); $iteration++) {
            $receipt = $service->processBatch(1);
            self::assertSame(1, $receipt->candidates);
            $observedCursorIds[] = (int) DB::table('service_notification_scan_cursor')
                ->where('id', 1)
                ->value('last_service_subscription_id');
        }

        self::assertSame(
            [...$eligibleIds, $eligibleIds[0]],
            $observedCursorIds,
            'The bounded notification selector must visit every eligible Service before wrapping.',
        );
    }

    public function test_low_balance_is_revalidated_after_the_initial_observation_before_persistence(): void
    {
        config()->set('service_notifications.low_balance_irr', 500_000);
        $fixture = $this->fixture();
        $this->attachService($fixture, 1);

        $assetId = $this->account('system.notification.balance.asset', 'asset');
        $walletId = $this->account(
            'wallet.cash.notification.'.$fixture['user_id'],
            'liability',
            $fixture['user_id'],
            'cash',
        );
        $this->fundWallet($assetId, $walletId, 100_000, 'initial');

        $fundedAfterObservation = false;
        DB::listen(function (QueryExecuted $query) use (
            &$fundedAfterObservation,
            $assetId,
            $walletId,
        ): void {
            $sql = strtolower($query->sql);
            if ($fundedAfterObservation
                || ! str_contains($sql, 'wallet_holds')
                || ! str_contains($sql, 'sum')) {
                return;
            }

            $fundedAfterObservation = true;
            DB::connection()->afterCommit(function () use ($assetId, $walletId): void {
                $this->fundWallet($assetId, $walletId, 600_000, 'after-observation');
            });
        });

        $receipt = $this->app->make(ServiceNotificationThresholdService::class)->processBatch(1);

        self::assertTrue($fundedAfterObservation, 'The regression must change balance after the initial low-balance observation.');
        self::assertSame(0, $receipt->triggered);
        self::assertSame(0, $receipt->queued);
        self::assertSame(0, DB::table('service_notification_states')
            ->where('notification_type', 'low_balance')
            ->count());
    }

    public function test_promotional_balance_does_not_mask_low_cash_wallet(): void
    {
        config()->set('service_notifications.low_balance_irr', 500_000);
        $fixture = $this->fixture();
        $this->attachService($fixture, 1);

        $assetId = $this->account('system.notification.cash-truth.asset', 'asset');
        $cashId = $this->account(
            'wallet.cash.notification.cash-truth.'.$fixture['user_id'],
            'liability',
            $fixture['user_id'],
            'cash',
        );
        $promotionalId = $this->account(
            'wallet.promotional.notification.cash-truth.'.$fixture['user_id'],
            'liability',
            $fixture['user_id'],
            'promotional',
        );
        $this->fundWallet($assetId, $cashId, 100_000, 'cash-truth-cash');
        $this->fundWallet($assetId, $promotionalId, 1_000_000, 'cash-truth-promotional');

        $receipt = $this->app->make(ServiceNotificationThresholdService::class)->processBatch(1);

        self::assertSame(1, $receipt->triggered);
        self::assertSame(1, $receipt->queued);
        self::assertSame(1, DB::table('service_notification_states')
            ->where('notification_type', 'low_balance')
            ->count());
    }

    public function test_temporary_delivery_blocker_is_isolated_per_service_in_batch(): void
    {
        config()->set('service_notifications.low_balance_irr', 500_000);
        $fixture = $this->fixture();
        $blockedServicePublicId = $this->attachService($fixture, 1);
        $healthyServicePublicId = $this->attachService($fixture, 2);

        $assetId = $this->account('system.notification.batch-isolation.asset', 'asset');
        $cashId = $this->account(
            'wallet.cash.notification.batch-isolation.'.$fixture['user_id'],
            'liability',
            $fixture['user_id'],
            'cash',
        );
        $this->fundWallet($assetId, $cashId, 100_000, 'batch-isolation');

        $this->app->make(ServiceMutationQueueService::class)->queue(
            $blockedServicePublicId,
            ServiceMutationType::ResetUsage,
            'notification-batch-isolation-request',
            'notification-batch-isolation-correlation',
        );

        $receipt = $this->app->make(ServiceNotificationThresholdService::class)->processBatch(2);
        $blockedServiceId = (int) DB::table('service_subscriptions')->where('public_id', $blockedServicePublicId)->value('id');
        $healthyServiceId = (int) DB::table('service_subscriptions')->where('public_id', $healthyServicePublicId)->value('id');

        self::assertSame(2, $receipt->candidates);
        self::assertSame(2, $receipt->triggered);
        self::assertSame(1, $receipt->queued);
        self::assertGreaterThanOrEqual(1, $receipt->skipped);
        self::assertNull(DB::table('service_notification_states')
            ->where('service_subscription_id', $blockedServiceId)
            ->value('latest_delivery_attempt_id'));
        self::assertNotNull(DB::table('service_notification_states')
            ->where('service_subscription_id', $healthyServiceId)
            ->value('latest_delivery_attempt_id'));
    }

    /** @return array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} */
    private function fixture(): array
    {
        $offering = $this->activeBenefitOffering('service-notification-fairness');
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $targetId = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('panel_service_target_id');
        $connectionId = (int) DB::table('panel_service_targets')->where('id', $targetId)->value('panel_connection_id');
        $profileId = DB::table('panel_target_protocol_profiles')
            ->where('panel_service_target_id', $targetId)
            ->value('panel_protocol_profile_id');
        self::assertNotNull($profileId);
        DB::table('panel_protocol_profiles')->where('id', (int) $profileId)->update([
            'host' => 'panel.example.com',
            'updated_at' => now('UTC'),
        ]);
        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'notification-fairness-test'], JSON_THROW_ON_ERROR)),
            'base_url' => 'https://panel.example.com',
            'state' => 'active',
            'updated_at' => now('UTC'),
        ]);
        DB::table('panel_service_targets')->where('id', $targetId)->update([
            'state' => 'active',
            'updated_at' => now('UTC'),
        ]);

        $adapter = new ServiceOperationalPanelAdapter;
        $this->app->instance(
            PanelAdapterRegistry::class,
            new PanelAdapterRegistry(
                [new ServiceOperationalPanelAdapterFactory($adapter)],
                $this->app->make(PanelCredentialPolicy::class),
            ),
        );
        $this->app->forgetInstance(ProvisioningPanelAdapterResolver::class);
        $this->app->forgetInstance(ServiceImportService::class);
        $this->app->forgetInstance(ServiceNotificationThresholdService::class);

        return [
            'owner_id' => $ownerId,
            'user_id' => $userId,
            'offering_id' => $offering['id'],
            'target_id' => $targetId,
            'adapter' => $adapter,
        ];
    }

    /** @param array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} $fixture */
    private function attachService(array $fixture, int $index): string
    {
        $remoteId = 'notification-fairness-'.$index;
        $expiresAt = new \DateTimeImmutable('+30 days', new \DateTimeZone('UTC'));
        $snapshot = new RemoteServiceSnapshot(
            $remoteId,
            'notification-fairness-user-'.$index,
            PanelServiceStatus::Active,
            10_000,
            0,
            $expiresAt,
            hash('sha256', $remoteId.'|'.$expiresAt->format(DATE_ATOM)),
            hash('sha256', 'equivalence:'.$remoteId),
        );
        $fixture['adapter']->seed($snapshot);

        $context = new ServiceOperationalContext(
            'notification-fairness-import-'.str_pad((string) $index, 8, '0', STR_PAD_LEFT),
            'notification-fairness-correlation-'.$index,
            'service_notification_test',
            'Attach a remote Service for notification batch fairness coverage.',
            $fixture['owner_id'],
        );
        $imports = $this->app->make(ServiceImportService::class);
        $preview = $imports->preview(
            'https://panel.example.com/sub/'.$remoteId,
            $fixture['target_id'],
            $fixture['user_id'],
            $fixture['offering_id'],
            $context,
        );
        $attached = $imports->attach($preview->importPublicId, $context);
        self::assertNotNull($attached->serviceSubscriptionPublicId);

        return $attached->serviceSubscriptionPublicId;
    }

    private function account(
        string $code,
        string $class,
        ?int $userId = null,
        ?string $bucket = null,
    ): int {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => $code,
            'account_class' => $class,
            'owner_user_id' => $userId,
            'wallet_bucket' => $bucket,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function fundWallet(int $assetId, int $walletId, int $amount, string $suffix): void
    {
        $token = substr(hash('sha256', $suffix), 0, 24);
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.notification.fund.'.$token,
            'wallet_topup_capture',
            'notification-fund-'.$token,
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amount)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amount)),
            ],
            'payment_intent',
            'pi-notification-fund-'.$token,
        );
    }
}

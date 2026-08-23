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
use App\Modules\Provisioning\Application\ServiceOperationalContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement SVC-013 SVC-014 DAT-003 QUA-004 */
final class ServiceNotificationBatchFairnessTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config()->set('service_notifications.low_balance_irr', 0);

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

    /** @return array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} */
    private function fixture(): array
    {
        $offering = $this->activeBenefitOffering('service-notification-fairness');
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $targetId = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('panel_service_target_id');
        $connectionId = (int) DB::table('panel_service_targets')->where('id', $targetId)->value('panel_connection_id');
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
    private function attachService(array $fixture, int $index): void
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
    }
}

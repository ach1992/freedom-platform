<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Provisioning\Application\ProvisioningPanelAdapterResolver;
use App\Modules\Provisioning\Application\ServiceImportReceipt;
use App\Modules\Provisioning\Application\ServiceImportService;
use App\Modules\Provisioning\Application\ServiceOperationalContext;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceProjection;
use App\Modules\Telegram\Application\TelegramOwnedServiceAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement SVC-001 DAT-002 DAT-003 SEC-002 QUA-001 */
final class TelegramOwnedServiceAllowedActionsProjectionTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_19_000140_enable_service_operational_authority.php');
        $migration->up();
    }

    public function test_projection_uses_current_policy_package_verified_capability_and_owner_truth_without_sync_dependency(): void
    {
        $fixture = $this->operationalFixture('telegram-allowed-actions');
        $attached = $this->attachedService($fixture, 'remote-allowed-actions', 'allowed-actions-user');
        self::assertIsString($attached->serviceSubscriptionPublicId);

        $projection = $this->app->make(TelegramOwnedServiceProjection::class);
        $page = $projection->pageForSelf($fixture['user_id'], $fixture['user_id'], 1, 6);
        self::assertCount(1, $page->items);
        $selectionToken = $page->items[0]->selectionToken;
        $syncRunsBeforeRead = DB::table('service_sync_runs')->count();
        $provisioningOperationsBeforeRead = DB::table('provisioning_operations')->count();
        $outboxBeforeRead = DB::table('outbox_messages')->count();

        self::assertSame([], $projection->detailForSelf(
            $fixture['user_id'],
            $fixture['user_id'],
            $selectionToken,
        )->allowedActions, 'Renew policy alone is insufficient without a renewal package and intrinsic capability.');

        $now = now('UTC');
        DB::table('plan_offering_packages')->insert([
            'plan_offering_id' => $fixture['offering_id'],
            'code' => 'telegram-renewal-package',
            'package_type' => 'renewal',
            'name_fa' => 'تمدید تست',
            'name_en' => 'Test renewal',
            'price_irr' => 100_000,
            'duration_days' => 30,
            'data_bytes' => null,
            'discount_eligible' => true,
            'sort_order' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::assertSame([], $projection->detailForSelf(
            $fixture['user_id'],
            $fixture['user_id'],
            $selectionToken,
        )->allowedActions, 'Package presence must not bypass missing verified update_expiry capability.');

        $this->insertCapability($fixture['target_id'], 'update_expiry', 'verified');
        self::assertSame(
            [TelegramOwnedServiceAction::Renew],
            $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $selectionToken)->allowedActions,
        );

        DB::table('panel_target_capabilities')
            ->where('panel_service_target_id', $fixture['target_id'])
            ->where('capability_code', 'update_expiry')
            ->update(['verification_status' => 'stale', 'updated_at' => $now]);
        self::assertSame([], $projection->detailForSelf(
            $fixture['user_id'],
            $fixture['user_id'],
            $selectionToken,
        )->allowedActions, 'Declared/stale capability evidence must fail closed.');
        DB::table('panel_target_capabilities')
            ->where('panel_service_target_id', $fixture['target_id'])
            ->where('capability_code', 'update_expiry')
            ->update(['verification_status' => 'verified', 'updated_at' => $now]);

        DB::table('plan_offering_operations')->insert([
            'plan_offering_id' => $fixture['offering_id'],
            'operation_code' => 'add_data',
            'customer_enabled' => true,
            'administrator_enabled' => true,
            'price_irr' => 0,
            'discount_eligible' => true,
            'required_capability_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::assertSame(
            [TelegramOwnedServiceAction::Renew],
            $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $selectionToken)->allowedActions,
            'Customer policy and package must still require the intrinsic capability.',
        );
        $this->insertCapability($fixture['target_id'], 'add_data_allowance', 'verified');
        self::assertSame(
            [TelegramOwnedServiceAction::Renew, TelegramOwnedServiceAction::AddData],
            $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $selectionToken)->allowedActions,
        );

        DB::table('plan_offering_operations')->insert([
            'plan_offering_id' => $fixture['offering_id'],
            'operation_code' => 'reset_usage',
            'customer_enabled' => true,
            'administrator_enabled' => true,
            'price_irr' => 0,
            'discount_eligible' => false,
            'required_capability_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::assertSame(
            [TelegramOwnedServiceAction::Renew, TelegramOwnedServiceAction::AddData],
            $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $selectionToken)->allowedActions,
            'Reset usage must require verified reset_usage capability but no paid package.',
        );
        $this->insertCapability($fixture['target_id'], 'reset_usage', 'verified');
        self::assertSame(
            [TelegramOwnedServiceAction::Renew, TelegramOwnedServiceAction::AddData, TelegramOwnedServiceAction::ResetUsage],
            $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $selectionToken)->allowedActions,
        );

        DB::table('plan_offering_operations')
            ->where('plan_offering_id', $fixture['offering_id'])
            ->where('operation_code', 'reset_usage')
            ->update(['required_capability_code' => 'custom_reset_gate', 'updated_at' => $now]);
        self::assertSame(
            [TelegramOwnedServiceAction::Renew, TelegramOwnedServiceAction::AddData],
            $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $selectionToken)->allowedActions,
            'Policy-specific capability is additive to the intrinsic reset capability.',
        );
        $this->insertCapability($fixture['target_id'], 'custom_reset_gate', 'verified');
        self::assertSame(
            [TelegramOwnedServiceAction::Renew, TelegramOwnedServiceAction::AddData, TelegramOwnedServiceAction::ResetUsage],
            $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $selectionToken)->allowedActions,
        );

        DB::table('plan_offering_operations')
            ->where('plan_offering_id', $fixture['offering_id'])
            ->where('operation_code', 'renew')
            ->update(['customer_enabled' => false, 'updated_at' => $now]);
        self::assertSame(
            [TelegramOwnedServiceAction::AddData, TelegramOwnedServiceAction::ResetUsage],
            $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $selectionToken)->allowedActions,
            'Administrator availability must not substitute for customer_enabled.',
        );

        $otherUserId = $this->benefitUser();
        try {
            $projection->detailForSelf($otherUserId, $otherUserId, $selectionToken);
            self::fail('Another actor must not resolve the owner-bound selection token or action availability.');
        } catch (AuthorizationException) {
            // Expected owner-only boundary.
        }

        self::assertSame($syncRunsBeforeRead, DB::table('service_sync_runs')->count(), 'Allowed-action projection must not trigger provider synchronization.');
        self::assertSame($provisioningOperationsBeforeRead, DB::table('provisioning_operations')->count(), 'Allowed-action projection must not create provisioning or Service mutation authority.');
        self::assertSame($outboxBeforeRead, DB::table('outbox_messages')->count(), 'Allowed-action projection must not enqueue external effects.');
    }

    /** @return array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} */
    private function operationalFixture(string $suffix): array
    {
        $offering = $this->activeBenefitOffering('service-operational-'.$suffix);
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $targetId = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('panel_service_target_id');
        $connectionId = (int) DB::table('panel_service_targets')->where('id', $targetId)->value('panel_connection_id');
        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'service-operational-test'], JSON_THROW_ON_ERROR)),
            'base_url' => 'https://panel.example.com',
            'state' => 'active',
            'updated_at' => now('UTC'),
        ]);
        DB::table('panel_service_targets')->where('id', $targetId)->update(['state' => 'active', 'updated_at' => now('UTC')]);

        $now = now('UTC');
        $profileId = (int) DB::table('panel_protocol_profiles')->insertGetId([
            'code' => 'svc-action-'.substr(hash('sha256', $suffix), 0, 20),
            'name_fa' => 'Service action test profile',
            'name_en' => 'Service action test profile',
            'protocol_family' => 'vless',
            'transport' => 'tcp',
            'security_layer' => 'tls',
            'host' => 'panel.example.com',
            'sni' => 'panel.example.com',
            'path' => null,
            'port' => 443,
            'flow' => null,
            'state' => 'disabled',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('panel_target_protocol_profiles')->insert([
            'panel_service_target_id' => $targetId,
            'panel_protocol_profile_id' => $profileId,
            'customer_selectable' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('panel_protocol_profiles')->where('id', $profileId)->update([
            'state' => 'active',
            'version' => 2,
            'updated_at' => $now,
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

        return [
            'owner_id' => $ownerId,
            'user_id' => $userId,
            'offering_id' => $offering['id'],
            'target_id' => $targetId,
            'adapter' => $adapter,
        ];
    }

    /** @param array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} $fixture */
    private function attachedService(array $fixture, string $remoteId, string $username): ServiceImportReceipt
    {
        $fixture['adapter']->seed(new RemoteServiceSnapshot(
            $remoteId,
            $username,
            PanelServiceStatus::Active,
            null,
            0,
            null,
            hash('sha256', 'canonical:'.$remoteId.':'.$username),
            hash('sha256', 'equivalence:'.$remoteId.':'.$username),
        ));
        $context = new ServiceOperationalContext(
            'service-action-import-'.$remoteId,
            'svc-action-'.substr(hash('sha256', 'correlation:'.$remoteId), 0, 32),
            'telegram_service_action_test',
            'Telegram allowed Service action projection test.',
            $fixture['owner_id'],
        );
        $service = $this->app->make(ServiceImportService::class);
        $preview = $service->preview(
            'https://panel.example.com/sub/'.$remoteId,
            $fixture['target_id'],
            $fixture['user_id'],
            $fixture['offering_id'],
            $context,
        );

        return $service->attach($preview->importPublicId, $context);
    }

    private function insertCapability(int $targetId, string $code, string $verificationStatus): void
    {
        $now = now('UTC');
        DB::table('panel_target_capabilities')->insert([
            'panel_service_target_id' => $targetId,
            'capability_code' => $code,
            'verification_status' => $verificationStatus,
            'evidence_hash' => $verificationStatus === 'verified' ? hash('sha256', 'evidence:'.$code) : null,
            'verified_at' => $verificationStatus === 'verified' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

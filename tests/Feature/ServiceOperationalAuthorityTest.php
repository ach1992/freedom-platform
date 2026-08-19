<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\AdministratorAccessService;
use App\Modules\AccessControl\Domain\PermissionEffect;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Provisioning\Application\ProvisioningPanelAdapterResolver;
use App\Modules\Provisioning\Application\ServiceBatchGrantService;
use App\Modules\Provisioning\Application\ServiceImportService;
use App\Modules\Provisioning\Application\ServiceOperationalAuthorityGuard;
use App\Modules\Provisioning\Application\ServiceOperationalContext;
use App\Modules\Provisioning\Application\ServiceOwnershipTransferService;
use App\Modules\Provisioning\Application\ServiceRepairService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement SVC-008 SVC-009 SVC-010 SVC-011 SVC-012 PRV-003 ARCH-003 ARCH-004 DAT-003 DAT-004 SEC-002 SEC-005 SEC-008 QUA-004 */
final class ServiceOperationalAuthorityTest extends TestCase
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

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_operational_migration_reenters_partial_release_and_survives_historical_authority_reentry(): void
    {
        DB::statement('ALTER TABLE service_batch_grant_items ADD CONSTRAINT service_batch_items_bootstrap_block_chk CHECK (0 = 1)');
        $this->expectOperationalAuthorityRejected();

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_19_000140_enable_service_operational_authority.php');
        $migration->up();
        $this->app->make(ServiceOperationalAuthorityGuard::class)->assertFinalized();

        /** @var Migration $serviceMutation */
        $serviceMutation = require database_path('migrations/2026_08_17_000300_enable_service_mutation_authority.php');
        $serviceMutation->up();
        $this->assertServiceOperationalMarkers();

        /** @var Migration $nonPaid */
        $nonPaid = require database_path('migrations/2026_08_19_000120_activate_non_paid_order_authority.php');
        $nonPaid->up();
        $this->assertServiceOperationalMarkers();

        $offering = $this->activeBenefitOffering('forged-import');
        $targetId = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('panel_service_target_id');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Service operational evidence insert authority is invalid.');
        DB::table('service_imports')->insert([
            'public_id' => (string) Str::ulid(),
            'request_key_hash' => hash('sha256', 'forged-import'),
            'actor_administrator_id' => $this->benefitOwner(),
            'user_id' => $this->benefitUser(),
            'plan_offering_id' => $offering['id'],
            'service_target_id' => $targetId,
            'subscription_link_hash' => hash('sha256', 'https://panel.example.com/sub/forged'),
            'registered_host' => 'panel.example.com',
            'remote_service_id' => 'forged',
            'remote_username' => 'forged',
            'remote_canonical_hash' => hash('sha256', 'forged-remote'),
            'remote_status' => 'active',
            'state' => 'previewed',
            'correlation_id' => 'forged-import-correlation',
            'created_at' => now('UTC'),
        ]);
    }

    public function test_import_is_ssrf_fail_closed_revalidates_remote_and_attaches_without_payment_or_provider_create(): void
    {
        $fixture = $this->operationalFixture('import');
        $service = $this->app->make(ServiceImportService::class);
        $context = $this->context('import', $fixture['owner_id']);

        try {
            $service->preview('http://panel.example.com/sub/remote-import', $fixture['target_id'], $fixture['user_id'], $fixture['offering_id'], $context);
            self::fail('HTTP subscription link must fail closed before provider lookup.');
        } catch (DomainException) {
            self::assertSame([], $fixture['adapter']->lookupTransactionLevels);
        }
        try {
            $service->preview('https://127.0.0.1/sub/remote-import', $fixture['target_id'], $fixture['user_id'], $fixture['offering_id'], $context);
            self::fail('IP-literal subscription link must fail closed before provider lookup.');
        } catch (DomainException) {
            self::assertSame([], $fixture['adapter']->lookupTransactionLevels);
        }

        $snapshot = $this->snapshot('remote-import', 'import-user');
        $fixture['adapter']->seed($snapshot);
        $link = 'https://panel.example.com/sub/remote-import';
        $preview = $service->preview($link, $fixture['target_id'], $fixture['user_id'], $fixture['offering_id'], $context);
        self::assertSame('previewed', $preview->state);
        self::assertSame([0], $fixture['adapter']->lookupTransactionLevels);
        $stored = DB::table('service_imports')->where('id', $preview->importId)->first();
        self::assertNotNull($stored);
        self::assertSame(hash('sha256', $link), $stored->subscription_link_hash);
        self::assertStringNotContainsString($link, json_encode($stored, JSON_THROW_ON_ERROR));

        $attached = $service->attach($preview->importPublicId, $context);
        self::assertSame('attached', $attached->state);
        self::assertSame([0, 0], $fixture['adapter']->lookupTransactionLevels);
        self::assertNotNull($attached->serviceSubscriptionPublicId);
        self::assertNotNull($attached->orderPublicId);
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('provisioning_operations')->count());
        self::assertSame('admin_grant', DB::table('orders')->where('public_id', $attached->orderPublicId)->value('source_type'));
        self::assertSame('authorized', DB::table('orders')->where('public_id', $attached->orderPublicId)->value('state'));
        self::assertSame($fixture['target_id'], (int) DB::table('service_subscriptions')->where('public_id', $attached->serviceSubscriptionPublicId)->value('service_target_id'));
        self::assertSame('remote-import', DB::table('service_subscriptions')->where('public_id', $attached->serviceSubscriptionPublicId)->value('remote_service_id'));

        $replay = $service->attach($preview->importPublicId, $context);
        self::assertTrue($replay->replayed);
        self::assertSame($attached->serviceSubscriptionPublicId, $replay->serviceSubscriptionPublicId);
        self::assertSame(1, DB::table('service_imports')->count());
        self::assertSame(1, DB::table('orders')->count());
        self::assertSame(1, DB::table('service_subscriptions')->count());
    }

    public function test_import_absence_and_authoritative_lookup_failure_create_no_local_authority(): void
    {
        $fixture = $this->operationalFixture('import-absent');
        $service = $this->app->make(ServiceImportService::class);
        $context = $this->context('import-absent', $fixture['owner_id']);

        try {
            $service->preview('https://panel.example.com/sub/missing-remote', $fixture['target_id'], $fixture['user_id'], $fixture['offering_id'], $context);
            self::fail('Missing remote Service must fail closed.');
        } catch (DomainException) {
            self::assertSame(0, DB::table('service_imports')->count());
        }
        $fixture['adapter']->lookupUnavailable = true;
        try {
            $service->preview('https://panel.example.com/sub/unavailable-remote', $fixture['target_id'], $fixture['user_id'], $fixture['offering_id'], $context);
            self::fail('Unavailable authoritative lookup must fail closed.');
        } catch (DomainException) {
            self::assertSame(0, DB::table('service_imports')->count());
            self::assertSame(0, DB::table('order_source_authorizations')->count());
            self::assertSame(0, DB::table('orders')->count());
            self::assertSame(0, DB::table('service_subscriptions')->count());
        }
        self::assertSame([0, 0], $fixture['adapter']->lookupTransactionLevels);
    }

    public function test_import_requires_operational_permission_and_existing_single_grant_authority(): void
    {
        $fixture = $this->operationalFixture('import-permission');
        $fixture['adapter']->seed($this->snapshot('remote-permission', 'permission-user'));
        $administratorId = $this->nonOwnerAdministrator();
        $context = $this->context('import-permission', $administratorId);
        $service = $this->app->make(ServiceImportService::class);
        $link = 'https://panel.example.com/sub/remote-permission';

        try {
            $service->preview($link, $fixture['target_id'], $fixture['user_id'], $fixture['offering_id'], $context);
            self::fail('Service import must authorize the administrator before provider lookup.');
        } catch (AuthorizationException) {
            self::assertSame([], $fixture['adapter']->lookupTransactionLevels);
            self::assertSame(0, DB::table('service_imports')->count());
        }

        $access = $this->app->make(AdministratorAccessService::class);
        $access->setPermissionOverride(
            $administratorId,
            'services.import',
            PermissionEffect::Allow,
            $this->accessContext($fixture['owner_id'], 'allow-import'),
        );
        $preview = $service->preview($link, $fixture['target_id'], $fixture['user_id'], $fixture['offering_id'], $context);
        self::assertSame('previewed', $preview->state);

        try {
            $service->attach($preview->importPublicId, $context);
            self::fail('Import attach must retain canonical services.grant_single authority.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('orders')->count());
            self::assertSame(0, DB::table('service_subscriptions')->count());
        }

        $access->setPermissionOverride(
            $administratorId,
            'services.grant_single',
            PermissionEffect::Allow,
            $this->accessContext($fixture['owner_id'], 'allow-single-grant'),
        );
        $attached = $service->attach($preview->importPublicId, $context);
        self::assertSame('attached', $attached->state);
    }

    public function test_ownership_transfer_is_audited_generation_fenced_replay_safe_and_not_a_financial_rewrite(): void
    {
        $fixture = $this->operationalFixture('transfer');
        $attached = $this->attachedService($fixture, 'remote-transfer', 'transfer-user', 'transfer-import');
        $serviceRow = DB::table('service_subscriptions')->where('public_id', $attached->serviceSubscriptionPublicId)->first();
        self::assertNotNull($serviceRow);
        $originalOrderUser = (int) DB::table('orders')->where('public_id', $attached->orderPublicId)->value('user_id');
        $targetUserId = $this->benefitUser();
        $transferService = $this->app->make(ServiceOwnershipTransferService::class);
        $unauthorized = $this->nonOwnerAdministrator();
        try {
            $transferService->transfer(
                $attached->serviceSubscriptionPublicId,
                $targetUserId,
                $this->context('transfer-denied', $unauthorized),
            );
            self::fail('Ownership transfer must authorize the administrator before evidence mutation.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('service_ownership_transfers')->count());
        }
        $context = $this->context('transfer-command', $fixture['owner_id']);

        $transfer = $transferService->transfer(
            $attached->serviceSubscriptionPublicId,
            $targetUserId,
            $context,
        );
        self::assertFalse($transfer->replayed);
        self::assertSame($fixture['user_id'], $transfer->fromUserId);
        self::assertSame($targetUserId, $transfer->toUserId);
        self::assertSame((int) $serviceRow->lifecycle_version + 1, $transfer->lifecycleVersion);
        self::assertSame($targetUserId, (int) DB::table('service_subscriptions')->where('public_id', $attached->serviceSubscriptionPublicId)->value('user_id'));
        self::assertSame($originalOrderUser, (int) DB::table('orders')->where('public_id', $attached->orderPublicId)->value('user_id'));
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'service.operational.ownership.transferred')->count());

        $replay = $transferService->transfer(
            $attached->serviceSubscriptionPublicId,
            $targetUserId,
            $context,
        );
        self::assertTrue($replay->replayed);
        self::assertSame($transfer->transferId, $replay->transferId);

        try {
            DB::table('service_subscriptions')->where('public_id', $attached->serviceSubscriptionPublicId)->update(['user_id' => $fixture['user_id']]);
            self::fail('Direct Service owner rewrite must fail closed.');
        } catch (QueryException) {
            self::assertSame($targetUserId, (int) DB::table('service_subscriptions')->where('public_id', $attached->serviceSubscriptionPublicId)->value('user_id'));
        }
    }

    public function test_repair_records_absent_uncertain_or_present_before_allowing_generation_fenced_identity_change(): void
    {
        $fixture = $this->operationalFixture('repair');
        $attached = $this->attachedService($fixture, 'remote-before', 'repair-user', 'repair-import');
        $repair = $this->app->make(ServiceRepairService::class);

        $absentContext = $this->context('repair-absent', $fixture['owner_id']);
        $absent = $repair->previewRemoteIdentity($attached->serviceSubscriptionPublicId, 'remote-absent', $absentContext);
        self::assertSame('absent', $absent->remoteDisposition);
        try {
            $repair->apply($absent->casePublicId, $absentContext);
            self::fail('Known-absent repair candidate must not be applied.');
        } catch (DomainException) {
            self::assertSame('remote-before', DB::table('service_subscriptions')->where('public_id', $attached->serviceSubscriptionPublicId)->value('remote_service_id'));
        }

        $fixture['adapter']->lookupUnavailable = true;
        $uncertainContext = $this->context('repair-uncertain', $fixture['owner_id']);
        $uncertain = $repair->previewRemoteIdentity($attached->serviceSubscriptionPublicId, 'remote-uncertain', $uncertainContext);
        self::assertSame('uncertain', $uncertain->remoteDisposition);
        $fixture['adapter']->lookupUnavailable = false;

        $fixture['adapter']->seed($this->snapshot('remote-after', 'repair-user-after'));
        $presentContext = $this->context('repair-present', $fixture['owner_id']);
        $present = $repair->previewRemoteIdentity($attached->serviceSubscriptionPublicId, 'remote-after', $presentContext);
        self::assertSame('present', $present->remoteDisposition);
        $before = DB::table('service_subscriptions')->where('public_id', $attached->serviceSubscriptionPublicId)->first();
        self::assertNotNull($before);

        $applied = $repair->apply($present->casePublicId, $presentContext);
        self::assertSame('applied', $applied->state);
        $after = DB::table('service_subscriptions')->where('public_id', $attached->serviceSubscriptionPublicId)->first();
        self::assertNotNull($after);
        self::assertSame('remote-after', $after->remote_service_id);
        self::assertSame((int) $before->remote_identity_generation + 1, (int) $after->remote_identity_generation);
        self::assertSame((int) $before->lifecycle_version + 1, (int) $after->lifecycle_version);
        self::assertSame(1, DB::table('service_reconciliation_changes')->where('service_reconciliation_case_id', $present->caseId)->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'service.operational.repair.applied')->count());
        self::assertSame([0, 0, 0, 0, 0, 0], $fixture['adapter']->lookupTransactionLevels);

        try {
            DB::table('service_subscriptions')->where('public_id', $attached->serviceSubscriptionPublicId)->update(['remote_service_id' => 'forged-remote']);
            self::fail('Direct remote identity repair must fail closed.');
        } catch (QueryException) {
            self::assertSame('remote-after', DB::table('service_subscriptions')->where('public_id', $attached->serviceSubscriptionPublicId)->value('remote_service_id'));
        }
    }

    public function test_resumable_batch_grants_preserve_success_across_partial_failure_without_financial_authority(): void
    {
        $offering = $this->activeBenefitOffering('batch-grant');
        $ownerId = $this->benefitOwner();
        $firstUserId = $this->benefitUser();
        $secondUserId = $this->benefitUser();
        $context = $this->context('batch-grant', $ownerId);
        $service = $this->app->make(ServiceBatchGrantService::class);

        $created = $service->create($context, [
            ['user_id' => $firstUserId, 'plan_offering_id' => $offering['id']],
            ['user_id' => $secondUserId, 'plan_offering_id' => $offering['id']],
        ]);
        self::assertSame('active', $created->state);
        DB::table('users')->where('id', $secondUserId)->update(['account_status' => 'deleted']);

        $partial = $service->resume($created->batchPublicId, $context);
        self::assertSame('active', $partial->state);
        self::assertSame(1, $partial->succeededCount);
        self::assertSame(1, $partial->failedCount);
        self::assertSame(1, DB::table('service_subscriptions')->where('user_id', $firstUserId)->count());
        self::assertSame(0, DB::table('service_subscriptions')->where('user_id', $secondUserId)->count());
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());

        $again = $service->resume($created->batchPublicId, $context);
        self::assertSame(1, $again->succeededCount);
        self::assertSame(1, DB::table('service_subscriptions')->where('user_id', $firstUserId)->count());

        DB::table('users')->where('id', $secondUserId)->update(['account_status' => 'active']);
        $completed = $service->resume($created->batchPublicId, $context);
        self::assertSame('completed', $completed->state);
        self::assertSame(2, $completed->succeededCount);
        self::assertSame(0, $completed->failedCount);
        self::assertSame(2, DB::table('order_source_authorizations')->where('source_type', 'admin_grant')->count());
        self::assertSame(2, DB::table('orders')->where('source_type', 'admin_grant')->count());
        self::assertSame(2, DB::table('service_subscriptions')->count());
        self::assertSame(2, DB::table('provisioning_operations')->where('operation_type', 'initial_provision')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());

        $replay = $service->resume($created->batchPublicId, $context);
        self::assertTrue($replay->replayed);
        self::assertSame(2, DB::table('service_subscriptions')->count());
        self::assertSame(2, DB::table('provisioning_operations')->count());
    }

    public function test_batch_bounds_and_active_claim_are_fail_closed(): void
    {
        $offering = $this->activeBenefitOffering('batch-bounds');
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $context = $this->context('batch-bounds', $ownerId);
        $service = $this->app->make(ServiceBatchGrantService::class);

        try {
            $service->create($context, []);
            self::fail('Empty batch grant must fail before authority creation.');
        } catch (DomainException) {
            self::assertSame(0, DB::table('service_batch_grants')->count());
        }
        $tooMany = [];
        for ($index = 1; $index <= 51; $index++) {
            $tooMany[] = ['user_id' => $this->benefitUser(), 'plan_offering_id' => $offering['id']];
        }
        try {
            $service->create($this->context('batch-too-many', $ownerId), $tooMany);
            self::fail('Oversized batch grant must fail before authority creation.');
        } catch (DomainException) {
            self::assertSame(0, DB::table('service_batch_grants')->count());
        }

        $created = $service->create($context, [['user_id' => $userId, 'plan_offering_id' => $offering['id']]]);
        $itemId = (int) DB::table('service_batch_grant_items')->where('service_batch_grant_id', $created->batchId)->value('id');
        DB::statement("SET @app_service_batch_authority = 'service_batch_grant_v1'");
        try {
            DB::table('service_batch_grant_items')->where('id', $itemId)->update([
                'state' => 'processing',
                'attempt_count' => 1,
                'claim_token' => (string) Str::ulid(),
                'claim_expires_at' => now('UTC')->addMinutes(5),
                'updated_at' => now('UTC'),
            ]);
        } finally {
            DB::statement('SET @app_service_batch_authority = NULL');
        }

        $blocked = $service->resume($created->batchPublicId, $context);
        self::assertTrue($blocked->replayed);
        self::assertSame(0, DB::table('order_source_authorizations')->count());
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('service_subscriptions')->count());
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
        $this->app->forgetInstance(ServiceRepairService::class);

        return [
            'owner_id' => $ownerId,
            'user_id' => $userId,
            'offering_id' => $offering['id'],
            'target_id' => $targetId,
            'adapter' => $adapter,
        ];
    }

    /** @param array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} $fixture */
    private function attachedService(array $fixture, string $remoteId, string $username, string $contextSuffix): \App\Modules\Provisioning\Application\ServiceImportReceipt
    {
        $fixture['adapter']->seed($this->snapshot($remoteId, $username));
        $context = $this->context($contextSuffix, $fixture['owner_id']);
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

    private function snapshot(string $remoteId, string $username): RemoteServiceSnapshot
    {
        return new RemoteServiceSnapshot(
            $remoteId,
            $username,
            PanelServiceStatus::Active,
            null,
            0,
            null,
            hash('sha256', 'canonical:'.$remoteId.':'.$username),
            hash('sha256', 'equivalence:'.$remoteId.':'.$username),
        );
    }

    private function nonOwnerAdministrator(): int
    {
        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->benefitUser(),
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function accessContext(int $ownerId, string $suffix): AccessChangeContext
    {
        return new AccessChangeContext(
            hash('sha256', 'service-operational-access:'.$suffix),
            'svc-access-'.substr(hash('sha256', $suffix), 0, 24),
            'service_operational_test',
            'Service operational permission test reason.',
            $ownerId,
        );
    }

    private function context(string $suffix, int $ownerId): ServiceOperationalContext
    {
        return new ServiceOperationalContext(
            'service-operational-'.$suffix,
            'svc-op-'.substr(hash('sha256', 'correlation:'.$suffix), 0, 32),
            'service_operational_test',
            'Service operational authority test reason.',
            $ownerId,
        );
    }

    private function expectOperationalAuthorityRejected(): void
    {
        try {
            $this->app->make(ServiceOperationalAuthorityGuard::class)->assertFinalized();
            self::fail('Partial release must remain consumer-fail-closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Service operational authority is not finalized.', $exception->getMessage());
        }
    }

    private function assertServiceOperationalMarkers(): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT ACTION_STATEMENT AS action_statement
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = 'service_subscriptions_update_guard'
SQL);
        self::assertNotNull($row);
        $body = $row->action_statement;
        self::assertIsString($body);
        self::assertStringContainsString('service_import_attach_v1', $body);
        self::assertStringContainsString('service_ownership_transfer_v1', $body);
        self::assertStringContainsString('service_repair_v1', $body);
        self::assertStringContainsString('initial_remote_effect_v1', $body);
        self::assertStringContainsString('service_mutation_queue_v1', $body);
        self::assertStringContainsString('service_mutation_effect_v1', $body);
    }
}

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
use App\Modules\Provisioning\Application\ServiceImportReceipt;
use App\Modules\Provisioning\Application\ServiceImportService;
use App\Modules\Provisioning\Application\ServiceOperationalAuthorityGuard;
use App\Modules\Provisioning\Application\ServiceOperationalContext;
use App\Modules\Provisioning\Application\ServiceOperationalDatabaseCapability;
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
        $this->expectExceptionMessage('Service import evidence insert authority is invalid.');
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

    public function test_internal_flags_without_application_capability_cannot_forge_operational_evidence(): void
    {
        $fixture = $this->operationalFixture('capability-forgery');
        $attached = $this->attachedService($fixture, 'remote-capability', 'capability-user', 'capability-import');
        $service = DB::table('service_subscriptions')->where('public_id', $attached->serviceSubscriptionPublicId)->first();
        self::assertNotNull($service);
        $ownerId = $fixture['owner_id'];

        DB::statement("SET @app_service_operational_evidence_authority = 'service_operational_evidence_v1'");
        try {
            try {
                DB::table('service_imports')->insert([
                    'public_id' => (string) Str::ulid(),
                    'request_key_hash' => hash('sha256', 'forged-import-with-flag'),
                    'actor_administrator_id' => $ownerId,
                    'user_id' => $fixture['user_id'],
                    'plan_offering_id' => $fixture['offering_id'],
                    'service_target_id' => $fixture['target_id'],
                    'subscription_link_hash' => hash('sha256', 'https://panel.example.com/sub/forged-with-flag'),
                    'registered_host' => 'panel.example.com',
                    'remote_service_id' => 'forged-with-flag',
                    'remote_username' => 'forged-with-flag',
                    'remote_canonical_hash' => hash('sha256', 'forged-with-flag'),
                    'remote_status' => 'active',
                    'state' => 'previewed',
                    'correlation_id' => 'forged-import-with-flag-correlation',
                    'created_at' => now('UTC'),
                ]);
                self::fail('Operational session flag alone must not create import evidence.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service import evidence insert authority is invalid.', $exception->getMessage());
            }

            try {
                DB::table('service_reconciliation_cases')->insert([
                    'public_id' => (string) Str::ulid(),
                    'request_key_hash' => hash('sha256', 'forged-present-with-flag'),
                    'service_subscription_id' => (int) $service->id,
                    'actor_administrator_id' => $ownerId,
                    'service_target_id' => (int) $service->service_target_id,
                    'before_remote_service_id' => (string) $service->remote_service_id,
                    'proposed_remote_service_id' => 'forged-present-remote',
                    'remote_disposition' => 'present',
                    'remote_canonical_hash' => hash('sha256', 'forged-present-canonical'),
                    'target_remote_identity_generation' => (int) $service->remote_identity_generation,
                    'target_lifecycle_version' => (int) $service->lifecycle_version,
                    'state' => 'previewed',
                    'audit_log_id' => null,
                    'correlation_id' => 'forged-present-with-flag-correlation',
                    'created_at' => now('UTC'),
                    'applied_at' => null,
                ]);
                self::fail('Operational session flag alone must not synthesize present remote evidence.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service reconciliation evidence insert authority is invalid.', $exception->getMessage());
            }

            try {
                DB::table('service_ownership_transfers')->insert([
                    'public_id' => (string) Str::ulid(),
                    'request_key_hash' => hash('sha256', 'forged-transfer-with-flag'),
                    'service_subscription_id' => (int) $service->id,
                    'from_user_id' => (int) $service->user_id,
                    'to_user_id' => $this->benefitUser(),
                    'actor_administrator_id' => $ownerId,
                    'target_remote_identity_generation' => (int) $service->remote_identity_generation,
                    'target_lifecycle_version' => (int) $service->lifecycle_version,
                    'state' => 'pending',
                    'audit_log_id' => null,
                    'correlation_id' => 'forged-transfer-with-flag-correlation',
                    'created_at' => now('UTC'),
                    'completed_at' => null,
                ]);
                self::fail('Operational session flag alone must not synthesize transfer evidence.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service ownership transfer evidence insert authority is invalid.', $exception->getMessage());
            }
        } finally {
            DB::statement('SET @app_service_operational_evidence_authority = NULL');
        }

        DB::statement("SET @app_service_operational_audit_authority = 'service_operational_audit_v1'");
        DB::statement('SET @app_service_operational_request_hash = ?', [hash('sha256', 'forged-audit-with-flag')]);
        try {
            try {
                DB::table('audit_logs')->insert([
                    'actor_type' => 'administrator',
                    'actor_id' => (string) $ownerId,
                    'action' => 'service.operational.repair.applied',
                    'target_type' => 'service_subscription',
                    'target_id' => (string) $service->public_id,
                    'before_safe_data' => json_encode(['remote_service_id_hash' => hash('sha256', (string) $service->remote_service_id)], JSON_THROW_ON_ERROR),
                    'after_safe_data' => json_encode(['remote_service_id_hash' => hash('sha256', 'forged-audit-remote')], JSON_THROW_ON_ERROR),
                    'reason_code' => 'service_operational_test',
                    'reason' => 'Forged operational audit.',
                    'correlation_id' => 'forged-audit-with-flag-correlation',
                    'request_fingerprint' => hash('sha256', 'forged-audit-with-flag'),
                    'created_at' => now('UTC'),
                ]);
                self::fail('Operational audit session flag alone must not create durable authority.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service operational audit authority is invalid.', $exception->getMessage());
            }
        } finally {
            DB::statement('SET @app_service_operational_audit_authority = NULL');
            DB::statement('SET @app_service_operational_request_hash = NULL');
        }
    }

    public function test_operational_evidence_cross_binding_rejects_reuse_and_terminal_rewrite_even_with_capability(): void
    {
        $fixture = $this->operationalFixture('cross-binding');
        $attached = $this->attachedService($fixture, 'remote-cross-binding', 'cross-binding-user', 'cross-binding-import');
        $service = DB::table('service_subscriptions')->where('public_id', $attached->serviceSubscriptionPublicId)->first();
        self::assertNotNull($service);
        $sourceImport = DB::table('service_imports')->where('service_subscription_id', (int) $service->id)->first();
        self::assertNotNull($sourceImport);
        self::assertSame('attached', $sourceImport->state);

        $transferRequestHash = hash('sha256', 'forged-cross-transfer');
        $transferCorrelationId = 'forged-cross-transfer-correlation';
        $forgedTransferTargetUserId = $this->benefitUser();
        $transferId = 0;
        $repairRequestHash = hash('sha256', 'forged-cross-repair');
        $repairCorrelationId = 'forged-cross-repair-correlation';
        $repairProposedRemoteId = 'forged-cross-repair-remote';
        $repairId = 0;

        $this->setEvidenceAuthorityWithCapability();
        try {
            try {
                DB::table('service_imports')->where('id', (int) $sourceImport->id)->update([
                    'remote_username' => 'forged-terminal-rewrite',
                ]);
                self::fail('Terminal import evidence must be immutable even with the application capability.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service import evidence update authority is invalid.', $exception->getMessage());
            }

            $forgedImportPublicId = (string) Str::ulid();
            $forgedImportRequestHash = hash('sha256', 'forged-cross-import');
            $forgedCorrelationId = 'forged-cross-import-correlation';
            $forgedImportId = (int) DB::table('service_imports')->insertGetId([
                'public_id' => $forgedImportPublicId,
                'request_key_hash' => $forgedImportRequestHash,
                'actor_administrator_id' => $fixture['owner_id'],
                'user_id' => $fixture['user_id'],
                'plan_offering_id' => $fixture['offering_id'],
                'service_target_id' => $fixture['target_id'],
                'subscription_link_hash' => hash('sha256', 'https://panel.example.com/sub/forged-cross-import'),
                'registered_host' => 'panel.example.com',
                'remote_service_id' => 'forged-cross-import',
                'remote_username' => 'forged-cross-import',
                'remote_canonical_hash' => hash('sha256', 'forged-cross-import-canonical'),
                'remote_status' => 'active',
                'state' => 'previewed',
                'order_source_authorization_id' => null,
                'order_id' => null,
                'service_subscription_id' => null,
                'audit_log_id' => null,
                'correlation_id' => $forgedCorrelationId,
                'created_at' => now('UTC'),
                'attached_at' => null,
            ]);
            try {
                DB::table('service_imports')->where('id', $forgedImportId)->update([
                    'state' => 'attaching',
                    'order_source_authorization_id' => $sourceImport->order_source_authorization_id,
                    'order_id' => $sourceImport->order_id,
                    'service_subscription_id' => $sourceImport->service_subscription_id,
                    'audit_log_id' => $sourceImport->audit_log_id,
                ]);
                self::fail('Import evidence from another request must not authorize a forged attachment.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service import evidence update authority is invalid.', $exception->getMessage());
            }

            $transferId = (int) DB::table('service_ownership_transfers')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'request_key_hash' => $transferRequestHash,
                'service_subscription_id' => (int) $service->id,
                'from_user_id' => (int) $service->user_id,
                'to_user_id' => $forgedTransferTargetUserId,
                'actor_administrator_id' => $fixture['owner_id'],
                'target_remote_identity_generation' => (int) $service->remote_identity_generation,
                'target_lifecycle_version' => (int) $service->lifecycle_version,
                'state' => 'pending',
                'audit_log_id' => null,
                'correlation_id' => $transferCorrelationId,
                'created_at' => now('UTC'),
                'completed_at' => null,
            ]);
            try {
                DB::table('service_ownership_transfers')->where('id', $transferId)->update([
                    'state' => 'applying',
                    'audit_log_id' => $sourceImport->audit_log_id,
                ]);
                self::fail('Cross-action audit evidence must not authorize a forged transfer.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service ownership transfer evidence update authority is invalid.', $exception->getMessage());
            }

            $repairId = (int) DB::table('service_reconciliation_cases')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'request_key_hash' => $repairRequestHash,
                'service_subscription_id' => (int) $service->id,
                'actor_administrator_id' => $fixture['owner_id'],
                'service_target_id' => (int) $service->service_target_id,
                'before_remote_service_id' => (string) $service->remote_service_id,
                'proposed_remote_service_id' => $repairProposedRemoteId,
                'remote_disposition' => 'present',
                'remote_canonical_hash' => hash('sha256', 'forged-cross-repair-canonical'),
                'target_remote_identity_generation' => (int) $service->remote_identity_generation,
                'target_lifecycle_version' => (int) $service->lifecycle_version,
                'state' => 'previewed',
                'audit_log_id' => null,
                'correlation_id' => $repairCorrelationId,
                'created_at' => now('UTC'),
                'applied_at' => null,
            ]);
            try {
                DB::table('service_reconciliation_cases')->where('id', $repairId)->update([
                    'state' => 'applying',
                    'audit_log_id' => $sourceImport->audit_log_id,
                ]);
                self::fail('Cross-action audit evidence must not authorize forged present repair evidence.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service reconciliation evidence update authority is invalid.', $exception->getMessage());
            }
        } finally {
            $this->clearEvidenceAuthorityWithCapability();
        }

        $this->setServiceAuthorityWithCapability('service_ownership_transfer_v1', $transferId, $transferRequestHash, $transferCorrelationId);
        try {
            try {
                DB::table('service_subscriptions')->where('id', (int) $service->id)->update([
                    'user_id' => $forgedTransferTargetUserId,
                    'lifecycle_version' => (int) $service->lifecycle_version + 1,
                    'updated_at' => now('UTC'),
                ]);
                self::fail('Pending forged transfer evidence must not authorize a Service transition.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service ownership transfer authority is invalid.', $exception->getMessage());
            }
        } finally {
            $this->clearServiceAuthorityWithCapability();
        }

        $this->setServiceAuthorityWithCapability('service_repair_v1', $repairId, $repairRequestHash, $repairCorrelationId);
        try {
            try {
                DB::table('service_subscriptions')->where('id', (int) $service->id)->update([
                    'remote_service_id' => $repairProposedRemoteId,
                    'remote_identity_generation' => (int) $service->remote_identity_generation + 1,
                    'lifecycle_version' => (int) $service->lifecycle_version + 1,
                    'updated_at' => now('UTC'),
                ]);
                self::fail('Preview-only forged repair evidence must not authorize a Service identity transition.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service reconciliation repair authority is invalid.', $exception->getMessage());
            }
        } finally {
            $this->clearServiceAuthorityWithCapability();
        }
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
        $this->setBatchAuthorityWithCapability();
        try {
            DB::table('service_batch_grant_items')->where('id', $itemId)->update([
                'state' => 'processing',
                'attempt_count' => 1,
                'claim_token' => (string) Str::ulid(),
                'claim_expires_at' => now('UTC')->addMinutes(5),
                'updated_at' => now('UTC'),
            ]);
        } finally {
            $this->clearBatchAuthorityWithCapability();
        }

        try {
            $service->pause($created->batchPublicId, $context);
            self::fail('Batch grant must not pause while a live item claim is active.');
        } catch (DomainException) {
            self::assertSame('active', DB::table('service_batch_grants')->where('id', $created->batchId)->value('state'));
        }

        $this->setBatchAuthorityWithCapability();
        try {
            try {
                DB::table('service_batch_grants')->where('id', $created->batchId)->update([
                    'state' => 'paused',
                    'updated_at' => now('UTC'),
                ]);
                self::fail('DB authority must not pause a batch while a live item claim exists.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service batch grant update authority is invalid.', $exception->getMessage());
            }
        } finally {
            $this->clearBatchAuthorityWithCapability();
        }

        $blocked = $service->resume($created->batchPublicId, $context);
        self::assertTrue($blocked->replayed);
        self::assertSame(0, DB::table('order_source_authorizations')->count());
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('service_subscriptions')->count());
    }

    public function test_batch_family_rejects_extra_items_and_resume_reason_drift(): void
    {
        $offering = $this->activeBenefitOffering('batch-family-commitment');
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $otherUserId = $this->benefitUser();
        $context = $this->context('batch-family-commitment', $ownerId);
        $service = $this->app->make(ServiceBatchGrantService::class);
        $created = $service->create($context, [[
            'user_id' => $userId,
            'plan_offering_id' => $offering['id'],
        ]]);
        $batch = DB::table('service_batch_grants')->where('id', $created->batchId)->first();
        self::assertNotNull($batch);
        self::assertNotNull($batch->items_committed_at);
        self::assertSame(1, DB::table('service_batch_grant_items')->where('service_batch_grant_id', $created->batchId)->count());

        $this->setBatchAuthorityWithCapability();
        try {
            foreach ([[0, $otherUserId], [2, $otherUserId]] as [$position, $targetUserId]) {
                try {
                    DB::table('service_batch_grant_items')->insert([
                        'public_id' => (string) Str::ulid(),
                        'service_batch_grant_id' => $created->batchId,
                        'position' => $position,
                        'user_id' => $targetUserId,
                        'plan_offering_id' => $offering['id'],
                        'request_key_hash' => hash('sha256', 'forged-batch-item:'.$position.':'.$targetUserId),
                        'state' => 'pending',
                        'attempt_count' => 0,
                        'claim_token' => null,
                        'claim_expires_at' => null,
                        'order_source_authorization_id' => null,
                        'order_id' => null,
                        'service_subscription_id' => null,
                        'provisioning_operation_id' => null,
                        'error_code' => null,
                        'correlation_id' => $context->correlationId,
                        'created_at' => now('UTC'),
                        'updated_at' => now('UTC'),
                    ]);
                    self::fail('Committed batch item family must reject injected children.');
                } catch (QueryException $exception) {
                    self::assertStringContainsString('Service batch grant item insert authority is invalid.', $exception->getMessage());
                }
            }
        } finally {
            $this->clearBatchAuthorityWithCapability();
        }
        self::assertSame(1, DB::table('service_batch_grant_items')->where('service_batch_grant_id', $created->batchId)->count());
        self::assertSame(0, DB::table('order_source_authorizations')->count());
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('service_subscriptions')->count());

        $wrongReasonContext = new ServiceOperationalContext(
            $context->requestKey,
            $context->correlationId,
            'service_operational_other',
            $context->reason,
            $context->actorAdministratorId,
        );
        try {
            $service->resume($created->batchPublicId, $wrongReasonContext);
            self::fail('Batch resume must preserve the accepted reason-code identity.');
        } catch (DomainException $exception) {
            self::assertSame('Service batch grant request identity conflicts with existing evidence.', $exception->getMessage());
        }
        self::assertSame(0, DB::table('order_source_authorizations')->count());
    }

    public function test_batch_cancellation_refuses_expired_started_work_and_parent_shortcuts(): void
    {
        $offering = $this->activeBenefitOffering('batch-cancel-fence');
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $service = $this->app->make(ServiceBatchGrantService::class);

        $startedContext = $this->context('batch-cancel-started', $ownerId);
        $started = $service->create($startedContext, [[
            'user_id' => $userId,
            'plan_offering_id' => $offering['id'],
        ]]);
        $startedItemId = (int) DB::table('service_batch_grant_items')
            ->where('service_batch_grant_id', $started->batchId)
            ->value('id');
        $this->setBatchAuthorityWithCapability();
        try {
            DB::table('service_batch_grant_items')->where('id', $startedItemId)->update([
                'state' => 'processing',
                'attempt_count' => 1,
                'claim_token' => (string) Str::ulid(),
                'claim_expires_at' => now('UTC')->subMinute(),
                'updated_at' => now('UTC'),
            ]);
        } finally {
            $this->clearBatchAuthorityWithCapability();
        }

        try {
            $service->cancel($started->batchPublicId, $startedContext);
            self::fail('Batch cancellation must refuse expired work that has already started.');
        } catch (DomainException $exception) {
            self::assertSame(
                'Service batch grant has started unfinished work and must be resumed or reconciled before cancellation.',
                $exception->getMessage(),
            );
        }
        self::assertSame('active', DB::table('service_batch_grants')->where('id', $started->batchId)->value('state'));
        self::assertSame('processing', DB::table('service_batch_grant_items')->where('id', $startedItemId)->value('state'));

        $this->setBatchAuthorityWithCapability();
        try {
            try {
                DB::table('service_batch_grant_items')->where('id', $startedItemId)->update([
                    'state' => 'cancelled',
                    'claim_token' => null,
                    'claim_expires_at' => null,
                    'error_code' => null,
                    'updated_at' => now('UTC'),
                ]);
                self::fail('DB guard must reject cancellation of a started batch item.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service batch grant item update authority is invalid.', $exception->getMessage());
            }
        } finally {
            $this->clearBatchAuthorityWithCapability();
        }

        $untouchedContext = $this->context('batch-cancel-untouched', $ownerId);
        $untouched = $service->create($untouchedContext, [[
            'user_id' => $userId,
            'plan_offering_id' => $offering['id'],
        ]]);
        $this->setBatchAuthorityWithCapability();
        try {
            try {
                DB::table('service_batch_grants')->where('id', $untouched->batchId)->update([
                    'state' => 'cancelled',
                    'updated_at' => now('UTC'),
                ]);
                self::fail('DB guard must reject cancelling a parent before its unfinished items are cancelled.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service batch grant update authority is invalid.', $exception->getMessage());
            }
        } finally {
            $this->clearBatchAuthorityWithCapability();
        }

        $cancelled = $service->cancel($untouched->batchPublicId, $untouchedContext);
        self::assertSame('cancelled', $cancelled->state);
        self::assertSame('cancelled', DB::table('service_batch_grant_items')
            ->where('service_batch_grant_id', $untouched->batchId)
            ->value('state'));
    }

    public function test_batch_result_guard_rejects_cross_batch_same_subject_result_forgery_even_with_internal_session_flag(): void
    {
        $offering = $this->activeBenefitOffering('batch-result-forgery');
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $service = $this->app->make(ServiceBatchGrantService::class);
        $sharedCorrelationId = 'svc-op-'.substr(hash('sha256', 'correlation:batch-result-shared'), 0, 32);
        $firstContext = new ServiceOperationalContext(
            'service-operational-batch-result-source',
            $sharedCorrelationId,
            'service_operational_test',
            'Service operational authority test reason.',
            $ownerId,
        );
        $first = $service->create($firstContext, [[
            'user_id' => $userId,
            'plan_offering_id' => $offering['id'],
        ]]);
        $service->resume($first->batchPublicId, $firstContext);
        $sourceItem = DB::table('service_batch_grant_items')->where('service_batch_grant_id', $first->batchId)->first();
        self::assertNotNull($sourceItem);
        self::assertSame('succeeded', $sourceItem->state);

        $secondContext = new ServiceOperationalContext(
            'service-operational-batch-result-target',
            $sharedCorrelationId,
            'service_operational_test',
            'Service operational authority test reason.',
            $ownerId,
        );
        $second = $service->create($secondContext, [[
            'user_id' => $userId,
            'plan_offering_id' => $offering['id'],
        ]]);
        $targetItem = DB::table('service_batch_grant_items')->where('service_batch_grant_id', $second->batchId)->first();
        self::assertNotNull($targetItem);
        $claimToken = (string) Str::ulid();

        $this->setBatchAuthorityWithCapability();
        try {
            DB::table('service_batch_grant_items')->where('id', (int) $targetItem->id)->update([
                'state' => 'processing',
                'attempt_count' => 1,
                'claim_token' => $claimToken,
                'claim_expires_at' => now('UTC')->addMinutes(5),
                'updated_at' => now('UTC'),
            ]);
            try {
                DB::table('service_batch_grant_items')->where('id', (int) $targetItem->id)->update([
                    'state' => 'succeeded',
                    'claim_token' => null,
                    'claim_expires_at' => null,
                    'order_source_authorization_id' => $sourceItem->order_source_authorization_id,
                    'order_id' => $sourceItem->order_id,
                    'service_subscription_id' => $sourceItem->service_subscription_id,
                    'provisioning_operation_id' => $sourceItem->provisioning_operation_id,
                    'updated_at' => now('UTC'),
                ]);
                self::fail('Batch item result guard must reject authority copied from another batch item.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service batch grant item update authority is invalid.', $exception->getMessage());
            }
        } finally {
            $this->clearBatchAuthorityWithCapability();
        }

        $target = DB::table('service_batch_grant_items')->where('id', (int) $targetItem->id)->first();
        self::assertNotNull($target);
        self::assertSame('processing', $target->state);
        self::assertNull($target->order_source_authorization_id);
        self::assertNull($target->order_id);
        self::assertSame(1, DB::table('orders')->count());
        self::assertSame(1, DB::table('service_subscriptions')->count());
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
            'code' => 'svc-op-'.substr(hash('sha256', $suffix), 0, 20),
            'name_fa' => 'Service operational test profile',
            'name_en' => 'Service operational test profile',
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
    private function attachedService(array $fixture, string $remoteId, string $username, string $contextSuffix): ServiceImportReceipt
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

    private function setBatchAuthorityWithCapability(): void
    {
        DB::statement('SET @app_service_operational_capability = ?', [
            $this->app->make(ServiceOperationalDatabaseCapability::class)->value(),
        ]);
        DB::statement("SET @app_service_batch_authority = 'service_batch_grant_v1'");
    }

    private function clearBatchAuthorityWithCapability(): void
    {
        DB::statement('SET @app_service_batch_authority = NULL');
        DB::statement('SET @app_service_operational_capability = NULL');
    }

    private function setEvidenceAuthorityWithCapability(): void
    {
        DB::statement('SET @app_service_operational_capability = ?', [
            $this->app->make(ServiceOperationalDatabaseCapability::class)->value(),
        ]);
        DB::statement("SET @app_service_operational_evidence_authority = 'service_operational_evidence_v1'");
    }

    private function clearEvidenceAuthorityWithCapability(): void
    {
        DB::statement('SET @app_service_operational_evidence_authority = NULL');
        DB::statement('SET @app_service_operational_capability = NULL');
    }

    private function setServiceAuthorityWithCapability(string $authority, int $evidenceId, string $requestHash, string $correlationId): void
    {
        DB::statement('SET @app_service_operational_capability = ?', [
            $this->app->make(ServiceOperationalDatabaseCapability::class)->value(),
        ]);
        DB::statement('SET @app_service_operational_authority = ?', [$authority]);
        DB::statement('SET @app_service_operational_evidence_id = ?', [$evidenceId]);
        DB::statement('SET @app_service_operational_request_hash = ?', [$requestHash]);
        DB::statement('SET @app_service_operational_correlation_id = ?', [$correlationId]);
    }

    private function clearServiceAuthorityWithCapability(): void
    {
        DB::statement('SET @app_service_operational_authority = NULL');
        DB::statement('SET @app_service_operational_evidence_id = NULL');
        DB::statement('SET @app_service_operational_request_hash = NULL');
        DB::statement('SET @app_service_operational_correlation_id = NULL');
        DB::statement('SET @app_service_operational_capability = NULL');
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

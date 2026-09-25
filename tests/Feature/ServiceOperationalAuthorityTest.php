<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\AdministratorAccessService;
use App\Modules\AccessControl\Domain\PermissionEffect;
use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\PlanOfferingRoutePolicyService;
use App\Modules\Catalog\Application\PlanOfferingService;
use App\Modules\Catalog\Domain\OfferingOperationCode;
use App\Modules\Catalog\Domain\OfferingOperationPolicy;
use App\Modules\Catalog\Domain\OfferingProtocolAssignment;
use App\Modules\Catalog\Domain\PlanOfferingAudience;
use App\Modules\Catalog\Domain\PlanOfferingDefinition;
use App\Modules\Catalog\Domain\PlanOfferingProtocolSelectionMode;
use App\Modules\Catalog\Domain\PlanOfferingRouteDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRoutePolicyDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRouteType;
use App\Modules\Catalog\Domain\PlanOfferingServerSelectionMode;
use App\Modules\Catalog\Domain\PlanOfferingServiceMode;
use App\Modules\Catalog\Domain\PlanOfferingTagMatchMode;
use App\Modules\Orders\Application\PaidServiceMutationOrderOutboxPublisher;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteReceipt;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Application\ServiceReconfigurationQuoteContext;
use App\Modules\Orders\Domain\QuoteAction;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseSettlementReceipt;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Provisioning\Application\ProvisioningPanelAdapterResolver;
use App\Modules\Provisioning\Application\ServiceBatchGrantService;
use App\Modules\Provisioning\Application\ServiceImportReceipt;
use App\Modules\Provisioning\Application\ServiceImportService;
use App\Modules\Provisioning\Application\ServiceMutationExecutor;
use App\Modules\Provisioning\Application\ServiceOperationalAuthorityGuard;
use App\Modules\Provisioning\Application\ServiceOperationalContext;
use App\Modules\Provisioning\Application\ServiceOperationalDatabaseCapability;
use App\Modules\Provisioning\Application\ServiceOwnershipTransferService;
use App\Modules\Provisioning\Application\ServicePurchaseMutationQueueService;
use App\Modules\Provisioning\Application\ServiceReconfigurationNoChargeQueueService;
use App\Modules\Provisioning\Application\ServiceReconfigurationPreviewService;
use App\Modules\Provisioning\Application\ServiceRepairService;
use App\Modules\Provisioning\Application\ServiceSynchronizationService;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceProjection;
use App\Modules\Telegram\Application\TelegramOwnedServiceSearchResult;
use App\Shared\Domain\Money;
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

    public function test_reconfiguration_preview_is_owner_bound_policy_priced_route_reserved_and_replay_safe(): void
    {
        $fixture = $this->reconfigurationFixture('preview');
        $attached = $this->attachedService($fixture, 'remote-reconfiguration-preview', 'reconfiguration-preview-user', 'reconfiguration-preview-import');
        self::assertIsString($attached->serviceSubscriptionPublicId);
        $servicePublicId = $attached->serviceSubscriptionPublicId;
        $serviceId = (int) DB::table('service_subscriptions')->where('public_id', $servicePublicId)->value('id');
        self::assertNull(DB::table('service_subscriptions')->where('id', $serviceId)->value('route_selection_id'));

        $service = $this->app->make(ServiceReconfigurationPreviewService::class);
        $requestKey = 'service.reconfiguration.preview.000001';
        $correlationId = 'service-reconfiguration-preview-correlation';
        $first = $service->previewForSelf(
            $fixture['user_id'],
            $servicePublicId,
            $fixture['offering_code'],
            $fixture['server_code'],
            $fixture['profile_code'],
            $requestKey,
            $correlationId,
        );
        self::assertFalse($first->replayed);
        self::assertSame($servicePublicId, $first->servicePublicId);
        self::assertSame($fixture['offering_code'], $first->sourceOfferingCode);
        self::assertSame($fixture['offering_code'], $first->targetOfferingCode);
        self::assertFalse($first->changesPlan);
        self::assertFalse($first->changesTarget);
        self::assertTrue($first->changesProtocol);
        self::assertSame(0, $first->priceDifferenceIrr);
        self::assertSame(50_000, $first->operationFeeIrr);
        self::assertSame(50_000, $first->totalPriceIrr);
        self::assertTrue($first->discountEligible);
        self::assertFalse($first->isFree());

        $replay = $service->previewForSelf(
            $fixture['user_id'],
            $servicePublicId,
            $fixture['offering_code'],
            $fixture['server_code'],
            $fixture['profile_code'],
            $requestKey,
            $correlationId,
        );
        self::assertTrue($replay->replayed);
        self::assertSame($first->previewPublicId, $replay->previewPublicId);
        self::assertSame(1, DB::table('service_reconfiguration_previews')->where('service_subscription_id', $serviceId)->count());

        $quoteKey = 'service.reconfiguration.quote.000001';
        $quote = $this->app->make(QuoteService::class)->create(
            $quoteKey,
            $fixture['user_id'],
            $fixture['offering_id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                (new \DateTimeImmutable($first->expiresAt))->modify('-1 minute'),
            ),
            'service-reconfiguration-quote-correlation',
            null,
            null,
            new ServiceReconfigurationQuoteContext($first->previewPublicId),
        );
        self::assertFalse($quote->replayed);
        self::assertSame(QuoteAction::Reconfigure, $quote->action);
        self::assertSame(50_000, $quote->basePriceIrr);
        self::assertSame(50_000, $quote->finalPriceIrr);
        self::assertNull($quote->servicePackage);
        self::assertNotNull($quote->serviceReconfiguration);
        self::assertSame($first->previewPublicId, $quote->serviceReconfiguration->previewPublicId);
        self::assertSame($servicePublicId, $quote->serviceReconfiguration->serviceSubscriptionPublicId);
        self::assertSame(50_000, $quote->serviceReconfiguration->operationFeeIrr);
        self::assertTrue($quote->serviceReconfiguration->changesProtocol);

        $quoteReplay = $this->app->make(QuoteService::class)->create(
            $quoteKey,
            $fixture['user_id'],
            $fixture['offering_id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                (new \DateTimeImmutable($first->expiresAt))->modify('-1 minute'),
            ),
            'service-reconfiguration-quote-correlation',
            null,
            null,
            new ServiceReconfigurationQuoteContext($first->previewPublicId),
        );
        self::assertTrue($quoteReplay->replayed);
        self::assertSame($quote->quotePublicId, $quoteReplay->quotePublicId);

        try {
            $this->app->make(QuoteService::class)->create(
                'service.reconfiguration.quote.000002',
                $fixture['user_id'],
                $fixture['offering_id'],
                new QuotePricingInput(
                    QuoteOverrideSource::None,
                    null,
                    null,
                    null,
                    0,
                    (new \DateTimeImmutable($first->expiresAt))->modify('-2 minutes'),
                ),
                'service-reconfiguration-quote-second',
                null,
                null,
                new ServiceReconfigurationQuoteContext($first->previewPublicId),
            );
            self::fail('One Service reconfiguration preview must not bind to multiple Quotes.');
        } catch (QueryException) {
            // Expected unique preview-to-Quote binding.
        }

        $stored = DB::table('service_reconfiguration_previews')
            ->where('public_id', $first->previewPublicId)
            ->first(['target_route_selection_id', 'target_capacity_reservation_id', 'state', 'request_key_hash', 'payload_hash']);
        self::assertNotNull($stored);
        self::assertSame('previewed', $stored->state);
        self::assertSame(hash('sha256', $requestKey), $stored->request_key_hash);
        self::assertSame(64, strlen((string) $stored->payload_hash));
        self::assertSame('held', DB::table('panel_capacity_reservations')->where('id', (int) $stored->target_capacity_reservation_id)->value('state'));
        self::assertSame($fixture['offering_id'], (int) DB::table('plan_offering_route_selections')
            ->where('id', (int) $stored->target_route_selection_id)->value('plan_offering_id'));

        $otherUser = $this->benefitUser();
        try {
            $service->previewForSelf(
                $otherUser,
                $servicePublicId,
                $fixture['offering_code'],
                $fixture['server_code'],
                $fixture['profile_code'],
                'service.reconfiguration.preview.cross-owner',
                'service-reconfiguration-cross-owner',
            );
            self::fail('Cross-owner Service reconfiguration preview must fail closed.');
        } catch (DomainException) {
            // Expected owner fence.
        }

        try {
            DB::table('service_reconfiguration_previews')->where('public_id', $first->previewPublicId)->update([
                'total_price_irr' => 1,
            ]);
            self::fail('Service reconfiguration preview must be immutable outside its authority.');
        } catch (QueryException) {
            // Expected immutable evidence guard.
        }
    }

    public function test_zero_cost_service_reconfiguration_queues_without_fake_financial_authority(): void
    {
        $fixture = $this->reconfigurationFixture('no-charge-effect', 0, 0);
        $attached = $this->attachedService(
            $fixture,
            'remote-reconfiguration-no-charge',
            'reconfiguration-no-charge-user',
            'reconfiguration-no-charge-import',
        );
        self::assertIsString($attached->serviceSubscriptionPublicId);
        $servicePublicId = $attached->serviceSubscriptionPublicId;
        $before = DB::table('service_subscriptions')->where('public_id', $servicePublicId)->first([
            'id', 'route_selection_id', 'service_target_id', 'remote_service_id',
            'remote_identity_generation', 'lifecycle_version', 'mutation_generation',
        ]);
        self::assertNotNull($before);
        self::assertNull($before->route_selection_id);

        $preview = $this->app->make(ServiceReconfigurationPreviewService::class)->previewForSelf(
            $fixture['user_id'],
            $servicePublicId,
            $fixture['offering_code'],
            $fixture['server_code'],
            $fixture['alternate_profile_code'],
            'service.reconfiguration.no-charge.preview.000001',
            'service-reconfiguration-no-charge-preview',
        );
        self::assertFalse($preview->changesPlan);
        self::assertFalse($preview->changesTarget);
        self::assertTrue($preview->changesProtocol);
        self::assertSame(0, $preview->priceDifferenceIrr);
        self::assertSame(0, $preview->operationFeeIrr);
        self::assertSame(0, $preview->totalPriceIrr);

        $financialCounts = [
            'quotes' => DB::table('quotes')->count(),
            'payment_intents' => DB::table('payment_intents')->count(),
            'purchase_settlements' => DB::table('purchase_settlements')->count(),
            'orders' => DB::table('orders')->count(),
        ];

        $queue = $this->app->make(ServiceReconfigurationNoChargeQueueService::class);
        $queued = $queue->queueForSelf(
            $fixture['user_id'],
            $preview->previewPublicId,
            'service.reconfiguration.no-charge.queue.000001',
            'service-reconfiguration-no-charge-queue',
        );
        $replay = $queue->queueForSelf(
            $fixture['user_id'],
            $preview->previewPublicId,
            'service.reconfiguration.no-charge.queue.000001',
            'service-reconfiguration-no-charge-queue',
        );

        self::assertSame(ServiceMutationType::Reconfigure, $queued->type);
        self::assertSame(ProvisioningState::Queued, $queued->state);
        self::assertFalse($queued->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame($queued->operationPublicId, $replay->operationPublicId);
        foreach ($financialCounts as $table => $count) {
            self::assertSame($count, DB::table($table)->count(), $table.' must not gain fake financial authority.');
        }

        $operationId = (int) DB::table('provisioning_operations')
            ->where('public_id', $queued->operationPublicId)
            ->value('id');
        $authority = DB::table('service_reconfiguration_authorities')
            ->where('provisioning_operation_id', $operationId)
            ->first([
                'authorization_mode', 'source_quote_id', 'purchase_order_id', 'purchase_order_item_id',
                'purchase_settlement_id', 'payment_intent_id', 'target_route_selection_id',
                'target_service_target_id', 'target_capacity_reservation_id',
            ]);
        self::assertNotNull($authority);
        self::assertSame('no_charge', $authority->authorization_mode);
        self::assertNull($authority->source_quote_id);
        self::assertNull($authority->purchase_order_id);
        self::assertNull($authority->purchase_order_item_id);
        self::assertNull($authority->purchase_settlement_id);
        self::assertNull($authority->payment_intent_id);
        self::assertSame((int) $before->mutation_generation + 1, (int) DB::table('service_subscriptions')
            ->where('id', (int) $before->id)->value('mutation_generation'));
        self::assertSame('held', DB::table('panel_capacity_reservations')
            ->where('id', (int) $authority->target_capacity_reservation_id)->value('state'));

        $otherUser = $this->benefitUser();
        try {
            $queue->queueForSelf(
                $otherUser,
                $preview->previewPublicId,
                'service.reconfiguration.no-charge.cross-owner',
                'service-reconfiguration-no-charge-cross-owner',
            );
            self::fail('Zero-cost Service reconfiguration must remain owner-bound.');
        } catch (DomainException) {
            // Expected owner fence.
        }

        $result = $this->app->make(ServiceMutationExecutor::class)->execute($queued->operationPublicId);
        self::assertSame(ProvisioningState::Succeeded, $result->state);
        self::assertSame([0], $fixture['adapter']->reconfigurationTransactionLevels);
        self::assertCount(1, $fixture['adapter']->reconfigurationRequests);
        self::assertSame($fixture['target_id'], $fixture['adapter']->reconfigurationRequests[0]->validatedAttributes['target_service_target_id']);

        $after = DB::table('service_subscriptions')->where('id', (int) $before->id)->first([
            'route_selection_id', 'service_target_id', 'remote_service_id',
            'remote_identity_generation', 'lifecycle_version', 'mutation_generation',
        ]);
        self::assertNotNull($after);
        self::assertSame((int) $authority->target_route_selection_id, (int) $after->route_selection_id);
        self::assertSame((int) $authority->target_service_target_id, (int) $after->service_target_id);
        self::assertSame($before->remote_service_id, $after->remote_service_id);
        self::assertSame((int) $before->remote_identity_generation + 1, (int) $after->remote_identity_generation);
        self::assertSame((int) $before->lifecycle_version + 1, (int) $after->lifecycle_version);
        self::assertSame((int) $before->mutation_generation + 1, (int) $after->mutation_generation);
        self::assertSame('committed', DB::table('panel_capacity_reservations')
            ->where('id', (int) $authority->target_capacity_reservation_id)->value('state'));
        self::assertSame(1, DB::table('service_delivery_attempts')
            ->where('service_subscription_id', (int) $before->id)
            ->where('purpose', 'resend')
            ->count());

        $terminalReplay = $this->app->make(ServiceMutationExecutor::class)->execute($queued->operationPublicId);
        self::assertTrue($terminalReplay->replayed);
        self::assertCount(1, $fixture['adapter']->reconfigurationRequests, 'Terminal zero-cost replay must never call the provider twice.');

        try {
            DB::table('service_reconfiguration_authorities')
                ->where('provisioning_operation_id', $operationId)
                ->update(['authorization_mode' => 'paid_purchase']);
            self::fail('Zero-cost authorization mode must be immutable.');
        } catch (QueryException) {
            self::assertSame('no_charge', DB::table('service_reconfiguration_authorities')
                ->where('provisioning_operation_id', $operationId)->value('authorization_mode'));
        }
    }

    public function test_paid_service_reconfiguration_uses_canonical_purchase_effect_capacity_and_delivery_authorities(): void
    {
        $fixture = $this->reconfigurationFixture('paid-effect');
        $attached = $this->attachedService(
            $fixture,
            'remote-reconfiguration-paid',
            'reconfiguration-paid-user',
            'reconfiguration-paid-import',
        );
        self::assertIsString($attached->serviceSubscriptionPublicId);
        $servicePublicId = $attached->serviceSubscriptionPublicId;
        $before = DB::table('service_subscriptions')->where('public_id', $servicePublicId)->first([
            'id', 'order_item_id', 'route_selection_id', 'service_target_id', 'remote_service_id',
            'remote_identity_generation', 'lifecycle_version', 'mutation_generation',
        ]);
        self::assertNotNull($before);
        self::assertNull($before->route_selection_id, 'Imported Service begins without a capacity-backed current route.');
        $historicalOfferingId = (int) DB::table('order_items')->where('id', (int) $before->order_item_id)->value('plan_offering_id');

        $preview = $this->app->make(ServiceReconfigurationPreviewService::class)->previewForSelf(
            $fixture['user_id'],
            $servicePublicId,
            $fixture['offering_code'],
            $fixture['server_code'],
            $fixture['profile_code'],
            'service.reconfiguration.paid.preview.000001',
            'service-reconfiguration-paid-preview',
        );
        $quote = $this->app->make(QuoteService::class)->create(
            'service.reconfiguration.paid.quote.000001',
            $fixture['user_id'],
            $fixture['offering_id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                (new \DateTimeImmutable($preview->expiresAt))->modify('-1 minute'),
            ),
            'service-reconfiguration-paid-quote',
            null,
            null,
            new ServiceReconfigurationQuoteContext($preview->previewPublicId),
        );
        self::assertSame(QuoteAction::Reconfigure, $quote->action);
        $settlement = $this->captureReconfigurationQuote($quote, $fixture['owner_id'], 'paid-effect');
        $paidOrder = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            'service-reconfiguration-paid-order',
        );
        self::assertSame(1, DB::table('outbox_messages')
            ->where('event_type', PaidServiceMutationOrderOutboxPublisher::OUTBOX_EVENT_TYPE)
            ->where('aggregate_type', PaidServiceMutationOrderOutboxPublisher::OUTBOX_AGGREGATE_TYPE)
            ->where('aggregate_id', $paidOrder->orderPublicId)
            ->count());

        $queueService = $this->app->make(ServicePurchaseMutationQueueService::class);
        $queued = $queueService->queueFromSettlement(
            $settlement->settlementPublicId,
            'service.reconfiguration.paid.queue.000001',
            'service-reconfiguration-paid-queue',
        );
        $queueReplay = $queueService->queueFromSettlement(
            $settlement->settlementPublicId,
            'service.reconfiguration.paid.queue.000001',
            'service-reconfiguration-paid-queue',
        );
        self::assertSame(ServiceMutationType::Reconfigure, $queued->type);
        self::assertSame(ProvisioningState::Queued, $queued->state);
        self::assertFalse($queued->replayed);
        self::assertTrue($queueReplay->replayed);
        self::assertSame($queued->operationPublicId, $queueReplay->operationPublicId);
        self::assertSame(0, DB::table('service_paid_mutation_authorities')
            ->where('service_subscription_id', (int) $before->id)->count());
        self::assertSame(1, DB::table('service_reconfiguration_authorities')
            ->where('service_subscription_id', (int) $before->id)->count());
        self::assertSame('paid_purchase', DB::table('service_reconfiguration_authorities')
            ->where('service_subscription_id', (int) $before->id)->value('authorization_mode'));
        self::assertSame((int) $before->mutation_generation + 1, (int) DB::table('service_subscriptions')
            ->where('id', (int) $before->id)->value('mutation_generation'));

        $authorityBefore = DB::table('service_reconfiguration_authorities as authority_row')
            ->join('panel_capacity_reservations as reservation', 'reservation.id', '=', 'authority_row.target_capacity_reservation_id')
            ->where('authority_row.service_subscription_id', (int) $before->id)
            ->first([
                'authority_row.target_route_selection_id', 'authority_row.target_service_target_id',
                'authority_row.target_capacity_reservation_id', 'reservation.state as reservation_state',
            ]);
        self::assertNotNull($authorityBefore);
        self::assertSame('held', $authorityBefore->reservation_state);

        $result = $this->app->make(ServiceMutationExecutor::class)->execute($queued->operationPublicId);
        self::assertSame(ProvisioningState::Succeeded, $result->state);
        self::assertSame(ServiceMutationType::Reconfigure, $result->type);
        self::assertSame([0], $fixture['adapter']->reconfigurationTransactionLevels);
        self::assertCount(1, $fixture['adapter']->reconfigurationRequests);
        self::assertSame($fixture['target_id'], $fixture['adapter']->reconfigurationRequests[0]->validatedAttributes['target_service_target_id']);

        $after = DB::table('service_subscriptions')->where('id', (int) $before->id)->first([
            'route_selection_id', 'service_target_id', 'remote_service_id', 'remote_identity_generation',
            'lifecycle_version', 'mutation_generation',
        ]);
        self::assertNotNull($after);
        self::assertSame((int) $authorityBefore->target_route_selection_id, (int) $after->route_selection_id);
        self::assertSame((int) $authorityBefore->target_service_target_id, (int) $after->service_target_id);
        self::assertSame('remote-reconfiguration-paid', $after->remote_service_id);
        self::assertSame((int) $before->remote_identity_generation + 1, (int) $after->remote_identity_generation);
        self::assertSame((int) $before->lifecycle_version + 1, (int) $after->lifecycle_version);
        self::assertSame((int) $before->mutation_generation + 1, (int) $after->mutation_generation);
        self::assertSame('committed', DB::table('panel_capacity_reservations')
            ->where('id', (int) $authorityBefore->target_capacity_reservation_id)->value('state'));
        self::assertSame($historicalOfferingId, (int) DB::table('order_items')
            ->where('id', (int) $before->order_item_id)->value('plan_offering_id'));
        self::assertNotNull(DB::table('service_reconfiguration_authorities')
            ->where('service_subscription_id', (int) $before->id)->value('result_recorded_at'));
        self::assertSame(64, strlen((string) DB::table('service_reconfiguration_authorities')
            ->where('service_subscription_id', (int) $before->id)->value('remote_result_snapshot_hash')));
        self::assertSame(1, DB::table('service_delivery_attempts')
            ->where('service_subscription_id', (int) $before->id)
            ->where('purpose', 'resend')
            ->count());

        $executionReplay = $this->app->make(ServiceMutationExecutor::class)->execute($queued->operationPublicId);
        self::assertTrue($executionReplay->replayed);
        self::assertSame(1, count($fixture['adapter']->reconfigurationRequests), 'Terminal replay must never call the provider twice.');

        $firstReservationId = (int) $authorityBefore->target_capacity_reservation_id;
        $capacityId = (int) DB::table('panel_capacity_reservations')
            ->where('id', $firstReservationId)
            ->value('panel_target_capacity_id');
        self::assertSame(1, (int) DB::table('panel_target_capacities')->where('id', $capacityId)->value('committed_units'));

        $secondPreview = $this->app->make(ServiceReconfigurationPreviewService::class)->previewForSelf(
            $fixture['user_id'],
            $servicePublicId,
            $fixture['offering_code'],
            $fixture['server_code'],
            $fixture['alternate_profile_code'],
            'service.reconfiguration.paid.preview.000002',
            'service-reconfiguration-paid-preview-two',
        );
        $secondQuote = $this->app->make(QuoteService::class)->create(
            'service.reconfiguration.paid.quote.000002',
            $fixture['user_id'],
            $fixture['offering_id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                (new \DateTimeImmutable($secondPreview->expiresAt))->modify('-1 minute'),
            ),
            'service-reconfiguration-paid-quote-two',
            null,
            null,
            new ServiceReconfigurationQuoteContext($secondPreview->previewPublicId),
        );
        $secondSettlement = $this->captureReconfigurationQuote($secondQuote, $fixture['owner_id'], 'paid-effect-two');
        $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $secondSettlement->settlementPublicId,
            'service-reconfiguration-paid-order-two',
        );
        $secondQueued = $queueService->queueFromSettlement(
            $secondSettlement->settlementPublicId,
            'service.reconfiguration.paid.queue.000002',
            'service-reconfiguration-paid-queue-two',
        );
        $secondAuthority = DB::table('service_reconfiguration_authorities')
            ->where('provisioning_operation_id', DB::table('provisioning_operations')
                ->where('public_id', $secondQueued->operationPublicId)->value('id'))
            ->first(['target_capacity_reservation_id', 'source_route_selection_id']);
        self::assertNotNull($secondAuthority);
        self::assertSame((int) $after->route_selection_id, (int) $secondAuthority->source_route_selection_id);
        self::assertSame('held', DB::table('panel_capacity_reservations')
            ->where('id', (int) $secondAuthority->target_capacity_reservation_id)->value('state'));
        self::assertSame(1, (int) DB::table('panel_target_capacities')->where('id', $capacityId)->value('held_units'));
        self::assertSame(1, (int) DB::table('panel_target_capacities')->where('id', $capacityId)->value('committed_units'));

        $secondResult = $this->app->make(ServiceMutationExecutor::class)->execute($secondQueued->operationPublicId);
        self::assertSame(ProvisioningState::Succeeded, $secondResult->state);
        self::assertSame('released', DB::table('panel_capacity_reservations')->where('id', $firstReservationId)->value('state'));
        self::assertSame('committed', DB::table('panel_capacity_reservations')
            ->where('id', (int) $secondAuthority->target_capacity_reservation_id)->value('state'));
        self::assertSame(0, (int) DB::table('panel_target_capacities')->where('id', $capacityId)->value('held_units'));
        self::assertSame(1, (int) DB::table('panel_target_capacities')->where('id', $capacityId)->value('committed_units'));
        self::assertSame(2, count($fixture['adapter']->reconfigurationRequests));
        self::assertSame([0, 0], $fixture['adapter']->reconfigurationTransactionLevels);
        self::assertSame(2, DB::table('service_delivery_attempts')
            ->where('service_subscription_id', (int) $before->id)
            ->where('purpose', 'resend')
            ->count());
        self::assertSame($historicalOfferingId, (int) DB::table('order_items')
            ->where('id', (int) $before->order_item_id)->value('plan_offering_id'));

        $planPreview = $this->app->make(ServiceReconfigurationPreviewService::class)->previewForSelf(
            $fixture['user_id'],
            $servicePublicId,
            $fixture['alternate_offering_code'],
            $fixture['server_code'],
            $fixture['alternate_profile_code'],
            'service.reconfiguration.paid.preview.000003',
            'service-reconfiguration-paid-preview-plan',
        );
        self::assertTrue($planPreview->changesPlan);
        self::assertFalse($planPreview->changesTarget);
        self::assertFalse($planPreview->changesProtocol);
        self::assertSame(200_000, $planPreview->priceDifferenceIrr);
        self::assertSame(25_000, $planPreview->operationFeeIrr);
        self::assertSame(225_000, $planPreview->totalPriceIrr);
        $planQuote = $this->app->make(QuoteService::class)->create(
            'service.reconfiguration.paid.quote.000003',
            $fixture['user_id'],
            $fixture['alternate_offering_id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                (new \DateTimeImmutable($planPreview->expiresAt))->modify('-1 minute'),
            ),
            'service-reconfiguration-paid-quote-plan',
            null,
            null,
            new ServiceReconfigurationQuoteContext($planPreview->previewPublicId),
        );
        self::assertSame(225_000, $planQuote->finalPriceIrr);
        $planSettlement = $this->captureReconfigurationQuote($planQuote, $fixture['owner_id'], 'paid-effect-plan');
        $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $planSettlement->settlementPublicId,
            'service-reconfiguration-paid-order-plan',
        );
        $planQueued = $queueService->queueFromSettlement(
            $planSettlement->settlementPublicId,
            'service.reconfiguration.paid.queue.000003',
            'service-reconfiguration-paid-queue-plan',
        );
        $planAuthority = DB::table('service_reconfiguration_authorities')
            ->where('provisioning_operation_id', DB::table('provisioning_operations')
                ->where('public_id', $planQueued->operationPublicId)->value('id'))
            ->first(['target_plan_offering_id', 'target_plan_offering_code', 'target_plan_offering_version', 'target_route_selection_id', 'target_capacity_reservation_id']);
        self::assertNotNull($planAuthority);
        self::assertSame($fixture['alternate_offering_id'], (int) $planAuthority->target_plan_offering_id);
        self::assertSame($fixture['alternate_offering_code'], $planAuthority->target_plan_offering_code);

        $planResult = $this->app->make(ServiceMutationExecutor::class)->execute($planQueued->operationPublicId);
        self::assertSame(ProvisioningState::Succeeded, $planResult->state);
        self::assertSame($fixture['alternate_offering_code'], $fixture['adapter']->reconfigurationRequests[2]->targetPlanOfferingCode);
        self::assertSame($fixture['alternate_offering_id'], $fixture['adapter']->reconfigurationRequests[2]->validatedAttributes['target_plan_offering_id']);
        $currentRouteOfferingId = (int) DB::table('plan_offering_route_selections')
            ->where('id', (int) DB::table('service_subscriptions')->where('id', (int) $before->id)->value('route_selection_id'))
            ->value('plan_offering_id');
        self::assertSame($fixture['alternate_offering_id'], $currentRouteOfferingId);
        self::assertSame($historicalOfferingId, (int) DB::table('order_items')
            ->where('id', (int) $before->order_item_id)->value('plan_offering_id'), 'Plan change must never rewrite original commercial history.');
        self::assertSame('released', DB::table('panel_capacity_reservations')
            ->where('id', (int) $secondAuthority->target_capacity_reservation_id)->value('state'));
        self::assertSame('committed', DB::table('panel_capacity_reservations')
            ->where('id', (int) $planAuthority->target_capacity_reservation_id)->value('state'));
        self::assertSame(0, (int) DB::table('panel_target_capacities')->where('id', $capacityId)->value('held_units'));
        self::assertSame(1, (int) DB::table('panel_target_capacities')->where('id', $capacityId)->value('committed_units'));
        self::assertSame(3, DB::table('service_delivery_attempts')
            ->where('service_subscription_id', (int) $before->id)
            ->where('purpose', 'resend')
            ->count());

        try {
            DB::table('service_subscriptions')->where('id', (int) $before->id)->update([
                'route_selection_id' => null,
            ]);
            self::fail('Direct Service configuration rewrite must fail closed after reconfiguration.');
        } catch (QueryException) {
            self::assertSame((int) $planAuthority->target_route_selection_id, (int) DB::table('service_subscriptions')
                ->where('id', (int) $before->id)->value('route_selection_id'));
        }
    }

    public function test_paid_service_reconfiguration_provider_exception_is_uncertain_and_never_blind_retried(): void
    {
        $fixture = $this->reconfigurationFixture('uncertain-effect');
        $attached = $this->attachedService(
            $fixture,
            'remote-reconfiguration-uncertain',
            'reconfiguration-uncertain-user',
            'reconfiguration-uncertain-import',
        );
        self::assertIsString($attached->serviceSubscriptionPublicId);
        $servicePublicId = $attached->serviceSubscriptionPublicId;
        $before = DB::table('service_subscriptions')->where('public_id', $servicePublicId)->first([
            'id', 'route_selection_id', 'service_target_id', 'remote_service_id',
            'remote_identity_generation', 'lifecycle_version', 'mutation_generation',
        ]);
        self::assertNotNull($before);
        self::assertNull($before->route_selection_id);

        $preview = $this->app->make(ServiceReconfigurationPreviewService::class)->previewForSelf(
            $fixture['user_id'],
            $servicePublicId,
            $fixture['offering_code'],
            $fixture['server_code'],
            $fixture['profile_code'],
            'service.reconfiguration.uncertain.preview.000001',
            'service-reconfiguration-uncertain-preview',
        );
        $quote = $this->app->make(QuoteService::class)->create(
            'service.reconfiguration.uncertain.quote.000001',
            $fixture['user_id'],
            $fixture['offering_id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                (new \DateTimeImmutable($preview->expiresAt))->modify('-1 minute'),
            ),
            'service-reconfiguration-uncertain-quote',
            null,
            null,
            new ServiceReconfigurationQuoteContext($preview->previewPublicId),
        );
        $settlement = $this->captureReconfigurationQuote($quote, $fixture['owner_id'], 'uncertain-effect');
        $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            'service-reconfiguration-uncertain-order',
        );
        $queued = $this->app->make(ServicePurchaseMutationQueueService::class)->queueFromSettlement(
            $settlement->settlementPublicId,
            'service.reconfiguration.uncertain.queue.000001',
            'service-reconfiguration-uncertain-queue',
        );
        $authority = DB::table('service_reconfiguration_authorities')
            ->where('provisioning_operation_id', DB::table('provisioning_operations')
                ->where('public_id', $queued->operationPublicId)->value('id'))
            ->first(['target_capacity_reservation_id']);
        self::assertNotNull($authority);
        $fixture['adapter']->reconfigurationThrows = true;

        $uncertain = $this->app->make(ServiceMutationExecutor::class)->execute($queued->operationPublicId);
        self::assertSame(ProvisioningState::UncertainRemoteResult, $uncertain->state);
        self::assertSame([0], $fixture['adapter']->reconfigurationTransactionLevels);
        self::assertCount(1, $fixture['adapter']->reconfigurationRequests);
        $after = DB::table('service_subscriptions')->where('id', (int) $before->id)->first([
            'route_selection_id', 'service_target_id', 'remote_service_id',
            'remote_identity_generation', 'lifecycle_version', 'mutation_generation',
        ]);
        self::assertNotNull($after);
        self::assertNull($after->route_selection_id);
        self::assertSame((int) $before->service_target_id, (int) $after->service_target_id);
        self::assertSame($before->remote_service_id, $after->remote_service_id);
        self::assertSame((int) $before->remote_identity_generation, (int) $after->remote_identity_generation);
        self::assertSame((int) $before->lifecycle_version, (int) $after->lifecycle_version);
        self::assertSame((int) $before->mutation_generation + 1, (int) $after->mutation_generation);
        self::assertSame('held', DB::table('panel_capacity_reservations')
            ->where('id', (int) $authority->target_capacity_reservation_id)->value('state'));
        self::assertNull(DB::table('service_reconfiguration_authorities')
            ->where('service_subscription_id', (int) $before->id)->value('result_recorded_at'));
        self::assertSame(0, DB::table('service_delivery_attempts')
            ->where('service_subscription_id', (int) $before->id)->count());

        try {
            $this->app->make(ServiceMutationExecutor::class)->execute($queued->operationPublicId);
            self::fail('Uncertain Service reconfiguration must require reconciliation before another provider attempt.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('requires reconciliation', $exception->getMessage());
        }
        self::assertCount(1, $fixture['adapter']->reconfigurationRequests, 'Uncertain effect must never blind-retry the provider.');
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

    /** @requirement SVC-001 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function test_telegram_owned_service_projection_searches_only_current_owner_and_uses_safe_cached_sync_fallback(): void
    {
        $fixture = $this->operationalFixture('telegram-owned-projection');
        $attached = $this->attachedService(
            $fixture,
            'remote-telegram-owned',
            'telegram-owned-user',
            'telegram-owned-import',
        );
        self::assertIsString($attached->serviceSubscriptionPublicId);
        self::assertIsString($attached->orderPublicId);
        $servicePublicId = $attached->serviceSubscriptionPublicId;
        $orderPublicId = $attached->orderPublicId;
        $projection = $this->app->make(TelegramOwnedServiceProjection::class);

        $emptyUserId = $this->benefitUser();
        $empty = $projection->pageForSelf($emptyUserId, $emptyUserId, 1, 6);
        self::assertSame([], $empty->items);
        self::assertSame(0, $empty->totalItems);
        self::assertSame(1, $empty->totalPages);

        $syncRunsBeforeRead = DB::table('service_sync_runs')->count();
        $page = $projection->pageForSelf($fixture['user_id'], $fixture['user_id'], 1, 6);
        self::assertSame(1, $page->totalItems);
        self::assertCount(1, $page->items);
        $item = $page->items[0];
        self::assertSame($servicePublicId, $item->publicId);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $item->selectionToken);
        self::assertNotSame($servicePublicId, $item->selectionToken);

        foreach ([strtolower($servicePublicId), strtolower($orderPublicId), 'telegram-owned-user'] as $term) {
            $match = $projection->searchForSelf($fixture['user_id'], $fixture['user_id'], $term);
            self::assertSame(TelegramOwnedServiceSearchResult::MATCHED, $match->status);
            self::assertSame($item->selectionToken, $match->selectionToken);
        }
        self::assertSame(
            TelegramOwnedServiceSearchResult::NOT_FOUND,
            $projection->searchForSelf($fixture['user_id'], $fixture['user_id'], 'TELEGRAM-OWNED-USER')->status,
            'Username matching must be binary/exact rather than inheriting a case-insensitive database collation.',
        );
        self::assertSame(
            TelegramOwnedServiceSearchResult::NOT_FOUND,
            $projection->searchForSelf($fixture['user_id'], $fixture['user_id'], ' telegram-owned-user ')->status,
            'Username matching must not silently trim or otherwise broaden the exact private search term.',
        );
        foreach ([$servicePublicId, $orderPublicId, 'telegram-owned-user'] as $otherActorTerm) {
            self::assertSame(
                TelegramOwnedServiceSearchResult::NOT_FOUND,
                $projection->searchForSelf($emptyUserId, $emptyUserId, $otherActorTerm)->status,
                'Another actor must not learn that an exact identifier or username exists.',
            );
        }
        self::assertSame($syncRunsBeforeRead, DB::table('service_sync_runs')->count(), 'Search/read projection must not trigger synchronization.');

        $noSync = $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $item->selectionToken);
        self::assertSame('none', $noSync->syncState);
        self::assertNull($noSync->remoteDisposition);
        self::assertNull($noSync->remoteStatus);

        try {
            $projection->pageForSelf($emptyUserId, $fixture['user_id'], 1, 6);
            self::fail('Owned Service projection must reject a different subject user.');
        } catch (AuthorizationException) {
            // Expected self-only boundary.
        }

        $fixture['adapter']->seed(new RemoteServiceSnapshot(
            'remote-telegram-owned',
            'telegram-owned-user',
            PanelServiceStatus::Active,
            10_000,
            2_500,
            new \DateTimeImmutable('2026-10-01T04:00:00+00:00'),
            hash('sha256', 'canonical:remote-telegram-owned:telegram-owned-user:current'),
            hash('sha256', 'equivalence:remote-telegram-owned:telegram-owned-user'),
        ));
        $sync = $this->app->make(ServiceSynchronizationService::class)->syncOne($servicePublicId);
        self::assertSame(1, $sync->processed);
        self::assertSame(0, $sync->failures);
        $current = $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $item->selectionToken);
        self::assertSame('current', $current->syncState);
        self::assertSame('present', $current->remoteDisposition);
        self::assertSame('active', $current->remoteStatus);
        self::assertSame(10_000, $current->dataLimitBytes);
        self::assertSame(2_500, $current->usedBytes);
        self::assertSame(7_500, $current->remainingBytes());
        $confirmedObservedAt = $current->observedAt;
        self::assertNotNull($confirmedObservedAt);

        $fixture['adapter']->lookupUnavailable = true;
        $unavailableSync = $this->app->make(ServiceSynchronizationService::class)->syncOne($servicePublicId);
        self::assertSame(1, $unavailableSync->processed);
        self::assertSame(1, $unavailableSync->failures);
        $cached = $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $item->selectionToken);
        self::assertSame('cached', $cached->syncState);
        self::assertSame('unavailable', $cached->remoteDisposition);
        self::assertSame('active', $cached->remoteStatus);
        self::assertSame(10_000, $cached->dataLimitBytes);
        self::assertSame(2_500, $cached->usedBytes);
        self::assertSame(7_500, $cached->remainingBytes());
        self::assertSame($confirmedObservedAt, $cached->observedAt, 'Cached facts must retain the prior confirmed observation time.');

        $fixture['adapter']->lookupUnavailable = false;
        $fixture['adapter']->remove('remote-telegram-owned');
        $missingSync = $this->app->make(ServiceSynchronizationService::class)->syncOne($servicePublicId);
        self::assertSame(1, $missingSync->processed);
        $missing = $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $item->selectionToken);
        self::assertSame('current', $missing->syncState);
        self::assertSame('missing', $missing->remoteDisposition);
        self::assertNull($missing->remoteStatus);
        self::assertNull($missing->dataLimitBytes);
        self::assertNull($missing->usedBytes);
        self::assertNull($missing->expiresAt);

        $fixture['adapter']->lookupUnavailable = true;
        $afterMissingUnavailableSync = $this->app->make(ServiceSynchronizationService::class)->syncOne($servicePublicId);
        self::assertSame(1, $afterMissingUnavailableSync->failures);
        $afterMissingUnavailable = $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $item->selectionToken);
        self::assertSame('current', $afterMissingUnavailable->syncState);
        self::assertSame('unavailable', $afterMissingUnavailable->remoteDisposition);
        self::assertNull($afterMissingUnavailable->remoteStatus, 'A later unavailable observation must not revive facts invalidated by a missing disposition.');
        self::assertNull($afterMissingUnavailable->dataLimitBytes);
        self::assertNull($afterMissingUnavailable->usedBytes);
        $fixture['adapter']->lookupUnavailable = false;

        $fixture['adapter']->forcedLookupSnapshot = new RemoteServiceSnapshot(
            'wrong-remote-identity',
            'telegram-owned-user',
            PanelServiceStatus::Active,
            99_999,
            88_888,
            new \DateTimeImmutable('2026-11-01T04:00:00+00:00'),
            hash('sha256', 'canonical:wrong-remote-identity'),
            hash('sha256', 'equivalence:wrong-remote-identity'),
        );
        $mismatchSync = $this->app->make(ServiceSynchronizationService::class)->syncOne($servicePublicId);
        self::assertSame(1, $mismatchSync->processed);
        $mismatch = $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $item->selectionToken);
        self::assertSame('current', $mismatch->syncState);
        self::assertSame('identity_mismatch', $mismatch->remoteDisposition);
        self::assertNull($mismatch->remoteStatus);
        self::assertNull($mismatch->dataLimitBytes);
        self::assertNull($mismatch->usedBytes);
        self::assertNull($mismatch->expiresAt);
        $fixture['adapter']->forcedLookupSnapshot = null;

        $fixture['adapter']->lookupUnavailable = true;
        $afterMismatchUnavailableSync = $this->app->make(ServiceSynchronizationService::class)->syncOne($servicePublicId);
        self::assertSame(1, $afterMismatchUnavailableSync->failures);
        $afterMismatchUnavailable = $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $item->selectionToken);
        self::assertSame('current', $afterMismatchUnavailable->syncState);
        self::assertSame('unavailable', $afterMismatchUnavailable->remoteDisposition);
        self::assertNull($afterMismatchUnavailable->remoteStatus, 'A later unavailable observation must not revive facts invalidated by identity mismatch.');
        self::assertNull($afterMismatchUnavailable->dataLimitBytes);
        self::assertNull($afterMismatchUnavailable->usedBytes);
        $fixture['adapter']->lookupUnavailable = false;

        $newOwnerUserId = $this->benefitUser();
        $transfer = $this->app->make(ServiceOwnershipTransferService::class)->transfer(
            $servicePublicId,
            $newOwnerUserId,
            $this->context('telegram-owned-transfer', $fixture['owner_id']),
        );
        self::assertSame($newOwnerUserId, $transfer->toUserId);
        self::assertSame(TelegramOwnedServiceSearchResult::NOT_FOUND, $projection->searchForSelf($fixture['user_id'], $fixture['user_id'], $servicePublicId)->status);
        $newOwnerMatch = $projection->searchForSelf($newOwnerUserId, $newOwnerUserId, $servicePublicId);
        self::assertSame(TelegramOwnedServiceSearchResult::MATCHED, $newOwnerMatch->status);
        self::assertNotSame($item->selectionToken, $newOwnerMatch->selectionToken);
        self::assertNotNull($newOwnerMatch->selectionToken);
        $stale = $projection->detailForSelf($newOwnerUserId, $newOwnerUserId, $newOwnerMatch->selectionToken);
        self::assertSame('stale', $stale->syncState);
        self::assertNull($stale->remoteDisposition);
        self::assertNull($stale->remoteStatus);

        $noCacheAttached = $this->attachedService($fixture, 'remote-no-cache', 'no-cache-user', 'telegram-no-cache-import');
        self::assertIsString($noCacheAttached->serviceSubscriptionPublicId);
        $noCacheMatch = $projection->searchForSelf($fixture['user_id'], $fixture['user_id'], $noCacheAttached->serviceSubscriptionPublicId);
        self::assertSame(TelegramOwnedServiceSearchResult::MATCHED, $noCacheMatch->status);
        self::assertNotNull($noCacheMatch->selectionToken);
        $fixture['adapter']->lookupUnavailable = true;
        $noCacheSync = $this->app->make(ServiceSynchronizationService::class)->syncOne($noCacheAttached->serviceSubscriptionPublicId);
        self::assertSame(1, $noCacheSync->failures);
        $noCache = $projection->detailForSelf($fixture['user_id'], $fixture['user_id'], $noCacheMatch->selectionToken);
        self::assertSame('current', $noCache->syncState);
        self::assertSame('unavailable', $noCache->remoteDisposition);
        self::assertNull($noCache->remoteStatus);
        self::assertNull($noCache->dataLimitBytes);
        self::assertNull($noCache->usedBytes);
        $fixture['adapter']->lookupUnavailable = false;

        $this->attachedService($fixture, 'remote-ambiguous-one', 'shared-search-user', 'telegram-ambiguous-one');
        $this->attachedService($fixture, 'remote-ambiguous-two', 'shared-search-user', 'telegram-ambiguous-two');
        $ambiguous = $projection->searchForSelf($fixture['user_id'], $fixture['user_id'], 'shared-search-user');
        self::assertSame(TelegramOwnedServiceSearchResult::AMBIGUOUS, $ambiguous->status);
        self::assertNull($ambiguous->selectionToken);
    }

    private function captureReconfigurationQuote(QuoteReceipt $quote, int $administratorId, string $suffix): PurchaseSettlementReceipt
    {
        $methodCode = 'svc_reconfigure_'.$suffix;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'service.reconfiguration.method.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            false,
            1,
            'Service reconfiguration test payment method.',
            'service-reconfiguration-method-'.$suffix,
        );
        $eligibility->recordHealth(
            'service.reconfiguration.health.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            $now->modify('+10 minutes'),
            'Healthy Service reconfiguration test payment method.',
            'service-reconfiguration-health-'.$suffix,
        );
        $decision = $eligibility->evaluate(
            'service.reconfiguration.eligibility.'.$suffix,
            $quote->userId,
            $quote->quotePublicId,
        );
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'service.reconfiguration.intent.'.$suffix,
            $quote->userId,
            $quote->quotePublicId,
            $decision->publicId,
            $methodCode,
            'service-reconfiguration-intent-'.$suffix,
        );
        DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->update([
            'state' => 'submitted',
            'updated_at' => now('UTC'),
        ]);

        return $this->app->make(PurchaseSettlementService::class)->capture(
            $intent->intentPublicId,
            $methodCode,
            new VerifiedPaymentEvent(
                'evt-service-reconfiguration-'.$suffix,
                hash('sha256', 'service-reconfiguration-provider-event:'.$suffix),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Settled,
                    'txn-service-reconfiguration-'.$suffix,
                    'evt-service-reconfiguration-'.$suffix,
                    Money::irr($intent->amount->amount()),
                    $now,
                    $now,
                    hash('sha256', 'service-reconfiguration-provider-evidence:'.$suffix),
                    ['provider_reference' => 'txn-service-reconfiguration-'.$suffix],
                ),
            ),
            'service-reconfiguration-settlement-'.$suffix,
        );
    }

    /** @return array{owner_id:int,user_id:int,offering_id:int,alternate_offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter,offering_code:string,alternate_offering_code:string,server_code:string,profile_code:string,alternate_profile_code:string} */
    private function reconfigurationFixture(string $suffix, int $changeProtocolFeeIrr = 50_000, int $changePlanFeeIrr = 25_000): array
    {
        $seed = $this->benefitOffering('service-reconfiguration-seed-'.$suffix, 'panel.example.com');
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $seedOffering = DB::table('plan_offerings')->where('id', $seed['id'])->first([
            'product_id', 'sales_server_id', 'panel_service_target_id',
        ]);
        self::assertNotNull($seedOffering);
        $targetId = (int) $seedOffering->panel_service_target_id;
        $serverId = (int) $seedOffering->sales_server_id;
        $profileId = (int) DB::table('plan_offering_protocol_profiles')
            ->where('plan_offering_id', $seed['id'])
            ->where('is_default', true)
            ->value('panel_protocol_profile_id');
        $tagId = (int) DB::table('plan_offering_tags')->where('plan_offering_id', $seed['id'])->value('customer_tag_id');
        self::assertGreaterThan(0, $profileId);
        self::assertGreaterThan(0, $tagId);
        $now = now('UTC');
        $alternateProfileId = (int) DB::table('panel_protocol_profiles')->insertGetId([
            'code' => 'svc-reconfig-alt-'.substr(hash('sha256', $suffix), 0, 16),
            'name_fa' => 'Service reconfiguration alternate profile',
            'name_en' => 'Service reconfiguration alternate profile',
            'protocol_family' => 'vless',
            'transport' => 'grpc',
            'security_layer' => 'tls',
            'host' => 'panel.example.com',
            'sni' => 'panel.example.com',
            'path' => null,
            'port' => 443,
            'flow' => null,
            'state' => 'active',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('panel_target_protocol_profiles')->insert([
            'panel_service_target_id' => $targetId,
            'panel_protocol_profile_id' => $alternateProfileId,
            'customer_selectable' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $offeringCode = 'svc-reconfig-'.substr(hash('sha256', $suffix), 0, 18);
        $definition = new PlanOfferingDefinition(
            $offeringCode,
            (int) $seedOffering->product_id,
            null,
            $serverId,
            $targetId,
            new PlanOfferingServiceMode('shared', 'Shared', 'Shared'),
            PlanOfferingAudience::Both,
            PlanOfferingServerSelectionMode::Customer,
            PlanOfferingProtocolSelectionMode::Customer,
            PlanOfferingTagMatchMode::All,
            1_000_000,
            30,
            null,
            2,
            0,
            1,
            1,
            true,
            false,
            false,
            false,
            ['normal'],
            [$tagId],
            [
                new OfferingProtocolAssignment($profileId, true, true),
                new OfferingProtocolAssignment($alternateProfileId, true, false),
            ],
            ['create_service', 'fetch_status'],
            [
                new OfferingOperationPolicy(
                    OfferingOperationCode::ChangeProtocol,
                    true,
                    true,
                    $changeProtocolFeeIrr,
                    true,
                    'reconfigure_service',
                ),
                new OfferingOperationPolicy(
                    OfferingOperationCode::ChangePlan,
                    true,
                    true,
                    $changePlanFeeIrr,
                    true,
                    'reconfigure_service',
                ),
            ],
            [],
        );
        $catalog = $this->app->make(PlanOfferingService::class);
        $created = $catalog->create(
            $definition,
            new CatalogChangeContext(
                'service-reconfiguration-offering-'.substr(hash('sha256', $suffix), 0, 24),
                'service-reconfiguration-offering-correlation-'.substr(hash('sha256', $suffix), 0, 16),
                'service_reconfiguration_test',
                'Create a Service reconfiguration test offering.',
                $ownerId,
            ),
        );
        $offeringId = $created->targetId;
        $alternateOfferingCode = $offeringCode.'-plus';
        $alternateDefinition = new PlanOfferingDefinition(
            $alternateOfferingCode,
            (int) $seedOffering->product_id,
            null,
            $serverId,
            $targetId,
            new PlanOfferingServiceMode('shared', 'Shared', 'Shared'),
            PlanOfferingAudience::Both,
            PlanOfferingServerSelectionMode::Customer,
            PlanOfferingProtocolSelectionMode::Customer,
            PlanOfferingTagMatchMode::All,
            1_200_000,
            30,
            null,
            2,
            0,
            1,
            1,
            true,
            false,
            false,
            false,
            ['normal'],
            [$tagId],
            [
                new OfferingProtocolAssignment($profileId, true, true),
                new OfferingProtocolAssignment($alternateProfileId, true, false),
            ],
            ['create_service', 'fetch_status'],
            [
                new OfferingOperationPolicy(
                    OfferingOperationCode::ChangeProtocol,
                    true,
                    true,
                    $changeProtocolFeeIrr,
                    true,
                    'reconfigure_service',
                ),
                new OfferingOperationPolicy(
                    OfferingOperationCode::ChangePlan,
                    true,
                    true,
                    $changePlanFeeIrr,
                    true,
                    'reconfigure_service',
                ),
            ],
            [],
        );
        $alternateCreated = $catalog->create(
            $alternateDefinition,
            new CatalogChangeContext(
                'service-reconfiguration-alt-offering-'.substr(hash('sha256', $suffix), 0, 20),
                'service-reconfiguration-alt-correlation-'.substr(hash('sha256', $suffix), 0, 18),
                'service_reconfiguration_test',
                'Create an alternate Service reconfiguration Plan Offering.',
                $ownerId,
            ),
        );
        $alternateOfferingId = $alternateCreated->targetId;
        $this->app->make(PlanOfferingRoutePolicyService::class)->create(
            $offeringId,
            new PlanOfferingRoutePolicyDefinition([
                new PlanOfferingRouteDefinition(
                    $serverId,
                    $targetId,
                    PlanOfferingRouteType::Primary,
                    0,
                    true,
                    null,
                    null,
                ),
            ]),
            new CatalogChangeContext(
                'service-reconfiguration-route-'.substr(hash('sha256', $suffix), 0, 24),
                'service-reconfiguration-route-correlation-'.substr(hash('sha256', $suffix), 0, 16),
                'service_reconfiguration_test',
                'Create a Service reconfiguration route policy.',
                $ownerId,
            ),
        );
        $this->app->make(PlanOfferingRoutePolicyService::class)->create(
            $alternateOfferingId,
            new PlanOfferingRoutePolicyDefinition([
                new PlanOfferingRouteDefinition(
                    $serverId,
                    $targetId,
                    PlanOfferingRouteType::Primary,
                    0,
                    true,
                    null,
                    null,
                ),
            ]),
            new CatalogChangeContext(
                'service-reconfiguration-alt-route-'.substr(hash('sha256', $suffix), 0, 20),
                'service-reconfiguration-alt-route-correlation-'.substr(hash('sha256', $suffix), 0, 14),
                'service_reconfiguration_test',
                'Create an alternate Service reconfiguration route policy.',
                $ownerId,
            ),
        );

        $connectionId = (int) DB::table('panel_service_targets')->where('id', $targetId)->value('panel_connection_id');
        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'service-reconfiguration-test'], JSON_THROW_ON_ERROR)),
            'base_url' => 'https://panel.example.com',
            'state' => 'active',
            'last_test_status' => 'success',
            'last_panel_version' => 'reconfiguration-test-1.0.0',
            'last_capabilities_hash' => hash('sha256', 'service-reconfiguration-capabilities:'.$suffix),
            'last_tested_at' => $now,
            'updated_at' => $now,
        ]);
        $connectionVersion = (int) DB::table('panel_connections')->where('id', $connectionId)->value('version');
        $evidenceHash = hash('sha256', 'service-reconfiguration-evidence:'.$suffix);
        if (! DB::table('panel_target_capabilities')
            ->where('panel_service_target_id', $targetId)
            ->where('capability_code', 'reconfigure_service')
            ->exists()) {
            DB::table('panel_target_capabilities')->insert([
                'panel_service_target_id' => $targetId,
                'capability_code' => 'reconfigure_service',
                'verification_status' => 'declared',
                'evidence_hash' => null,
                'verified_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('panel_target_capabilities')->where('panel_service_target_id', $targetId)->update([
            'verification_status' => 'verified',
            'evidence_hash' => $evidenceHash,
            'verified_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('panel_service_targets')->where('id', $targetId)->update([
            'state' => 'active',
            'capability_status' => 'verified',
            'capability_evidence_hash' => $evidenceHash,
            'capability_verified_at' => $now,
            'verified_connection_version' => $connectionVersion,
            'updated_at' => $now,
        ]);
        DB::table('sales_servers')->where('id', $serverId)->update([
            'state' => 'active',
            'visibility' => 'listed',
            'updated_at' => $now,
        ]);
        if (! DB::table('panel_target_capacities')->where('panel_service_target_id', $targetId)->exists()) {
            DB::table('panel_target_capacities')->insert([
                'panel_service_target_id' => $targetId,
                'hard_limit' => 10,
                'held_units' => 0,
                'committed_units' => 0,
                'state' => 'enabled',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        if (! DB::table('customer_tag_assignments')->where('user_id', $userId)->where('tag_id', $tagId)->exists()) {
            DB::table('customer_tag_assignments')->insert([
                'user_id' => $userId,
                'tag_id' => $tagId,
                'assigned_by_administrator_id' => $ownerId,
                'assigned_at' => $now,
                'removed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $version = (int) DB::table('plan_offerings')->where('id', $offeringId)->value('version');
        $catalog->activate(
            $offeringId,
            $version,
            new CatalogChangeContext(
                'service-reconfiguration-activate-'.substr(hash('sha256', $suffix), 0, 24),
                'service-reconfiguration-activate-correlation-'.substr(hash('sha256', $suffix), 0, 16),
                'service_reconfiguration_test',
                'Activate the verified Service reconfiguration test offering.',
                $ownerId,
            ),
        );
        $alternateVersion = (int) DB::table('plan_offerings')->where('id', $alternateOfferingId)->value('version');
        $catalog->activate(
            $alternateOfferingId,
            $alternateVersion,
            new CatalogChangeContext(
                'service-reconfiguration-alt-activate-'.substr(hash('sha256', $suffix), 0, 20),
                'service-reconfiguration-alt-activate-corr-'.substr(hash('sha256', $suffix), 0, 18),
                'service_reconfiguration_test',
                'Activate the verified alternate Service reconfiguration Plan Offering.',
                $ownerId,
            ),
        );

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
        $this->app->forgetInstance(ServiceReconfigurationPreviewService::class);

        return [
            'owner_id' => $ownerId,
            'user_id' => $userId,
            'offering_id' => $offeringId,
            'alternate_offering_id' => $alternateOfferingId,
            'target_id' => $targetId,
            'adapter' => $adapter,
            'offering_code' => $offeringCode,
            'alternate_offering_code' => $alternateOfferingCode,
            'server_code' => (string) DB::table('sales_servers')->where('id', $serverId)->value('code'),
            'profile_code' => (string) DB::table('panel_protocol_profiles')->where('id', $profileId)->value('code'),
            'alternate_profile_code' => (string) DB::table('panel_protocol_profiles')->where('id', $alternateProfileId)->value('code'),
        ];
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
        self::assertStringContainsString('operational_capability_count', $body);
        self::assertStringContainsString('service_operational_authority_capability', $body);
        self::assertStringContainsString('initial_remote_effect_v1', $body);
        self::assertStringContainsString('service_mutation_queue_v1', $body);
        self::assertStringContainsString('service_mutation_effect_v1', $body);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\PlanOfferingService;
use App\Modules\Catalog\Domain\OfferingOperationCode;
use App\Modules\Catalog\Domain\OfferingOperationPolicy;
use App\Modules\Catalog\Domain\OfferingPackageDefinition;
use App\Modules\Catalog\Domain\OfferingPackageType;
use App\Modules\Catalog\Domain\PlanOfferingDefinition;
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
use RuntimeException;
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

    public function test_projection_uses_current_immutable_policy_package_and_verified_capability_truth(): void
    {
        $projection = $this->app->make(TelegramOwnedServiceProjection::class);

        $missingPackage = $this->operationalFixture(
            'missing-renewal-package',
            withRenewalPackage: false,
            verifiedCapabilities: ['update_expiry'],
        );
        $this->attachedService($missingPackage, 'remote-missing-package', 'missing-package-user');
        self::assertSame(
            [],
            $projection->detailForSelf(
                $missingPackage['user_id'],
                $missingPackage['user_id'],
                $this->selectionToken($projection, $missingPackage['user_id']),
            )->allowedActions,
            'A verified intrinsic capability must not bypass the missing renewal package.',
        );

        $disabledPolicy = $this->operationalFixture(
            'disabled-renew-policy',
            renewCustomerEnabled: false,
            withRenewalPackage: true,
            verifiedCapabilities: ['update_expiry'],
        );
        $this->attachedService($disabledPolicy, 'remote-disabled-policy', 'disabled-policy-user');
        self::assertSame(
            [],
            $projection->detailForSelf(
                $disabledPolicy['user_id'],
                $disabledPolicy['user_id'],
                $this->selectionToken($projection, $disabledPolicy['user_id']),
            )->allowedActions,
            'Administrator availability must not substitute for customer_enabled.',
        );

        $capabilityFixture = $this->operationalFixture(
            'runtime-capability-truth',
            withRenewalPackage: true,
        );
        $this->attachedService($capabilityFixture, 'remote-capability-truth', 'capability-truth-user');
        $selectionToken = $this->selectionToken($projection, $capabilityFixture['user_id']);

        self::assertSame(
            [],
            $projection->detailForSelf(
                $capabilityFixture['user_id'],
                $capabilityFixture['user_id'],
                $selectionToken,
            )->allowedActions,
            'A renewal package and customer policy are insufficient without update_expiry.',
        );

        $this->insertCapability($capabilityFixture['target_id'], 'update_expiry', 'declared');
        self::assertSame(
            [],
            $projection->detailForSelf(
                $capabilityFixture['user_id'],
                $capabilityFixture['user_id'],
                $selectionToken,
            )->allowedActions,
            'Declared capability evidence must fail closed.',
        );

        $this->setCapabilityVerified($capabilityFixture['target_id'], 'update_expiry');
        self::assertSame(
            [TelegramOwnedServiceAction::Renew],
            $projection->detailForSelf(
                $capabilityFixture['user_id'],
                $capabilityFixture['user_id'],
                $selectionToken,
            )->allowedActions,
            'The next detail read must use current verified capability truth rather than cached availability.',
        );
    }

    public function test_projection_exposes_only_supported_actions_and_is_owner_bound_and_effect_free(): void
    {
        $fixture = $this->operationalFixture(
            'supported-actions',
            withRenewalPackage: true,
            withAddDataPolicy: true,
            withResetUsagePolicy: true,
            withChangePlanPolicy: true,
            verifiedCapabilities: ['update_expiry', 'add_data_allowance', 'reset_usage'],
        );
        $this->attachedService($fixture, 'remote-supported-actions', 'supported-actions-user');

        $projection = $this->app->make(TelegramOwnedServiceProjection::class);
        $selectionToken = $this->selectionToken($projection, $fixture['user_id']);
        $effectsBeforeRead = $this->effectCounts();

        self::assertSame(
            [
                TelegramOwnedServiceAction::Renew,
                TelegramOwnedServiceAction::AddData,
                TelegramOwnedServiceAction::ResetUsage,
            ],
            $projection->detailForSelf(
                $fixture['user_id'],
                $fixture['user_id'],
                $selectionToken,
            )->allowedActions,
            'Only supported customer actions backed by package and capability truth may be exposed; change_plan remains out of scope.',
        );

        $otherUserId = $this->benefitUser();
        try {
            $projection->detailForSelf($otherUserId, $otherUserId, $selectionToken);
            self::fail('Another actor must not resolve the owner-bound selection token or action availability.');
        } catch (AuthorizationException) {
            // Expected owner-only boundary.
        }

        self::assertSame($effectsBeforeRead, $this->effectCounts(), 'Allowed-action projection reads must create no sync, provisioning, or Outbox effects.');
    }

    /**
     * @param  list<string>  $verifiedCapabilities
     * @return array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter}
     */
    private function operationalFixture(
        string $suffix,
        bool $renewCustomerEnabled = true,
        bool $withRenewalPackage = false,
        bool $withAddDataPolicy = false,
        bool $withResetUsagePolicy = false,
        bool $withChangePlanPolicy = false,
        array $verifiedCapabilities = [],
    ): array {
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $dependencies = $this->usageOfferingDependencies('service-operational-'.$suffix);
        $code = 'svc-action-'.substr(hash('sha256', $suffix), 0, 16);
        $baseDefinition = $this->usageOfferingDefinition($dependencies, 1_000_000, true, $code);

        $operations = [
            new OfferingOperationPolicy(
                OfferingOperationCode::Renew,
                $renewCustomerEnabled,
                true,
                0,
                true,
                'create_service',
            ),
        ];
        if ($withAddDataPolicy) {
            $operations[] = new OfferingOperationPolicy(
                OfferingOperationCode::AddData,
                true,
                true,
                0,
                true,
                null,
            );
        }
        if ($withResetUsagePolicy) {
            $operations[] = new OfferingOperationPolicy(
                OfferingOperationCode::ResetUsage,
                true,
                true,
                0,
                false,
                null,
            );
        }
        if ($withChangePlanPolicy) {
            $operations[] = new OfferingOperationPolicy(
                OfferingOperationCode::ChangePlan,
                true,
                true,
                0,
                false,
                null,
            );
        }

        $packages = $baseDefinition->packages;
        if ($withRenewalPackage) {
            $packages[] = new OfferingPackageDefinition(
                'renew-'.$code,
                OfferingPackageType::Renewal,
                'تمدید تست',
                'Test renewal',
                100_000,
                30,
                null,
                true,
                10,
            );
        }

        $definition = new PlanOfferingDefinition(
            $baseDefinition->code,
            $baseDefinition->productId,
            $baseDefinition->variantId,
            $baseDefinition->salesServerId,
            $baseDefinition->serviceTargetId,
            $baseDefinition->serviceMode,
            $baseDefinition->audience,
            $baseDefinition->serverSelectionMode,
            $baseDefinition->protocolSelectionMode,
            $baseDefinition->tagMatchMode,
            $baseDefinition->basePriceIrr,
            $baseDefinition->durationDays,
            $baseDefinition->dataAllowanceBytes,
            $baseDefinition->deviceLimit,
            $baseDefinition->sortOrder,
            $baseDefinition->minPurchaseQuantity,
            $baseDefinition->maxPurchaseQuantity,
            $baseDefinition->discountEligible,
            $baseDefinition->autoRenewAllowed,
            $baseDefinition->customPlanAllowed,
            $baseDefinition->trialAllowed,
            $baseDefinition->tierCodes,
            $baseDefinition->tagIds,
            $baseDefinition->protocols,
            $baseDefinition->requiredCapabilities,
            $operations,
            $packages,
        );

        $created = $this->app->make(PlanOfferingService::class)->create(
            $definition,
            new CatalogChangeContext(
                'svc-action-create-'.substr(hash('sha256', $suffix), 0, 32),
                'svc-action-create-correlation-'.substr(hash('sha256', $suffix), 0, 20),
                'telegram_service_action_test',
                'Create immutable Service action projection fixture.',
                $ownerId,
            ),
        );
        $offeringId = $created->targetId;
        $targetId = $dependencies['target_id'];
        $connectionId = (int) DB::table('panel_service_targets')->where('id', $targetId)->value('panel_connection_id');
        $now = now('UTC');
        $capabilitiesHash = hash('sha256', 'svc-action-capabilities:'.$suffix);
        $evidenceHash = hash('sha256', 'svc-action-evidence:'.$suffix);

        foreach (array_values(array_unique($verifiedCapabilities)) as $capability) {
            $this->insertCapability($targetId, $capability, 'declared');
        }
        DB::table('panel_target_capabilities')
            ->where('panel_service_target_id', $targetId)
            ->update([
                'verification_status' => 'verified',
                'evidence_hash' => $evidenceHash,
                'verified_at' => $now,
                'updated_at' => $now,
            ]);
        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'service-operational-test'], JSON_THROW_ON_ERROR)),
            'base_url' => 'https://panel.example.com',
            'state' => 'active',
            'last_test_status' => 'success',
            'last_panel_version' => 'service-action-test-1.0.0',
            'last_capabilities_hash' => $capabilitiesHash,
            'last_tested_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('panel_service_targets')->where('id', $targetId)->update([
            'state' => 'active',
            'capability_status' => 'verified',
            'capability_evidence_hash' => $evidenceHash,
            'capability_verified_at' => $now,
            'verified_connection_version' => 1,
            'updated_at' => $now,
        ]);
        DB::table('sales_servers')->where('id', $dependencies['server_id'])->update([
            'state' => 'active',
            'visibility' => 'listed',
            'updated_at' => $now,
        ]);

        $version = (int) DB::table('plan_offerings')->where('id', $offeringId)->value('version');
        $activated = $this->app->make(PlanOfferingService::class)->activate(
            $offeringId,
            $version,
            new CatalogChangeContext(
                'svc-action-activate-'.substr(hash('sha256', $suffix), 0, 32),
                'svc-action-activate-correlation-'.substr(hash('sha256', $suffix), 0, 20),
                'telegram_service_action_test',
                'Activate immutable Service action projection fixture.',
                $ownerId,
            ),
        );
        if (! $activated->changed) {
            throw new RuntimeException('Service action projection fixture offering could not be activated.');
        }

        $profileId = (int) DB::table('panel_protocol_profiles')->insertGetId([
            'code' => 'svc-action-profile-'.substr(hash('sha256', $suffix), 0, 16),
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
            'offering_id' => $offeringId,
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

    private function selectionToken(TelegramOwnedServiceProjection $projection, int $userId): string
    {
        $page = $projection->pageForSelf($userId, $userId, 1, 6);
        self::assertCount(1, $page->items);

        return $page->items[0]->selectionToken;
    }

    /** @return array{sync_runs:int,provisioning_operations:int,outbox_messages:int} */
    private function effectCounts(): array
    {
        return [
            'sync_runs' => DB::table('service_sync_runs')->count(),
            'provisioning_operations' => DB::table('provisioning_operations')->count(),
            'outbox_messages' => DB::table('outbox_messages')->count(),
        ];
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

    private function setCapabilityVerified(int $targetId, string $code): void
    {
        $now = now('UTC');
        $updated = DB::table('panel_target_capabilities')
            ->where('panel_service_target_id', $targetId)
            ->where('capability_code', $code)
            ->update([
                'verification_status' => 'verified',
                'evidence_hash' => hash('sha256', 'evidence:'.$code),
                'verified_at' => $now,
                'updated_at' => $now,
            ]);
        self::assertSame(1, $updated, 'Expected exactly one target capability row to become verified.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\PlanOfferingRoutePolicyService;
use App\Modules\Catalog\Domain\PlanOfferingRouteDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRoutePolicyDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRouteType;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Provisioning\Application\InitialProvisioningExecutor;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Provisioning\Application\ServiceAutoRenewalProcessor;
use App\Modules\Provisioning\Application\ServiceAutoRenewConfigurationReceipt;
use App\Modules\Provisioning\Application\ServiceAutoRenewConfigurationService;
use App\Modules\Provisioning\Application\ServiceMutationExecutor;
use App\Modules\Provisioning\Application\ServiceOperationalDatabaseCapability;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

trait ServiceAutoRenewalRuntimeTestHelpers
{
    private function enableAutoRenew(array $scenario, string $suffix): ServiceAutoRenewConfigurationReceipt
    {
        $receipt = $this->app->make(ServiceAutoRenewConfigurationService::class)->configure(
            'service.auto-renew.config.'.$suffix.'.000001',
            $scenario['user_id'],
            $scenario['service_public_id'],
            'aq-renew-30d',
            true,
            $this->purchaseOrderCorrelation('auto-renew-config-'.$suffix),
        );

        // Direct fixture writes in the runtime verification suite must cross the same
        // app-key-derived database capability as production runtime writes.
        (new ServiceOperationalDatabaseCapability)->apply(DB::connection());

        return $receipt;
    }

    private function enableWalletMethod(string $suffix): void
    {
        $administratorId = $this->ownerAdministrator();
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'service.auto-renew.wallet.method.'.$suffix.'.000001',
            $administratorId,
            'wallet',
            true,
            false,
            1,
            'Auto-renew wallet test method.',
            $this->purchaseOrderCorrelation('auto-renew-wallet-method-'.$suffix),
        );
        $eligibility->recordHealth(
            'service.auto-renew.wallet.health.'.$suffix.'.000001',
            $administratorId,
            'wallet',
            true,
            $this->purchaseOrderClock->value->modify('+10 minutes'),
            'Healthy auto-renew wallet test observation.',
            $this->purchaseOrderCorrelation('auto-renew-wallet-health-'.$suffix),
        );
    }

    private function fundWallet(int $userId, int $amountIrr, string $suffix): int
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
        $walletId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.auto.renew.'.$suffix.'.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
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

        return $walletId;
    }

    /** @return array{service_id:int,service_public_id:string,target_id:int,offering_id:int,user_id:int,adapter:AutoRenewTestPanelAdapter} */
    private function scenario(string $suffix): array
    {
        $settlement = $this->createPurchaseOrderSettlement('auto-renew-'.$suffix);
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('auto-renew-order-'.$suffix),
        );
        $queue = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('auto-renew-queue-'.$suffix),
        );
        $offeringId = (int) DB::table('order_items')->where('order_id', $order->orderId)->value('plan_offering_id');
        $userId = (int) DB::table('orders')->where('id', $order->orderId)->value('user_id');
        $targetId = $this->makeOfferingOperational($offeringId, $userId, $suffix);

        $adapter = new AutoRenewTestPanelAdapter;
        $this->app->instance(
            PanelAdapterRegistry::class,
            new PanelAdapterRegistry(
                [new AutoRenewTestPanelAdapterFactory($adapter)],
                $this->app->make(PanelCredentialPolicy::class),
            ),
        );
        $this->app->forgetInstance(InitialProvisioningExecutor::class);
        $this->app->forgetInstance(ServiceMutationExecutor::class);
        $this->app->forgetInstance(ServiceAutoRenewConfigurationService::class);
        $this->app->forgetInstance(ServiceAutoRenewalProcessor::class);

        $provisioned = $this->app->make(InitialProvisioningExecutor::class)->execute($queue->provisioningOperationPublicId);
        self::assertSame(ProvisioningState::Succeeded, $provisioned->state);
        $adapter->resetCalls();

        $service = DB::table('service_subscriptions')->where('id', $queue->serviceSubscriptionId)->first([
            'id', 'public_id', 'remote_service_id', 'remote_identity_generation', 'lifecycle_state',
        ]);
        self::assertNotNull($service);
        self::assertSame('active', $service->lifecycle_state);
        self::assertNotNull($service->remote_service_id);
        $adapter->setKnownEntitlements(
            (string) $service->remote_service_id,
            20 * 1024 * 1024 * 1024,
            $this->purchaseOrderClock->value->modify('+12 hours'),
        );
        $adapter->resetCalls();

        return [
            'service_id' => (int) $service->id,
            'service_public_id' => (string) $service->public_id,
            'target_id' => $targetId,
            'offering_id' => $offeringId,
            'user_id' => $userId,
            'adapter' => $adapter,
        ];
    }

    private function makeOfferingOperational(int $offeringId, int $userId, string $suffix): int
    {
        $now = $this->purchaseOrderTimestamp();
        $offering = DB::table('plan_offerings')->where('id', $offeringId)->first([
            'sales_server_id', 'panel_service_target_id',
        ]);
        self::assertNotNull($offering);
        $serverId = (int) $offering->sales_server_id;
        $targetId = (int) $offering->panel_service_target_id;
        $connectionId = (int) DB::table('panel_service_targets')->where('id', $targetId)->value('panel_connection_id');
        $connectionVersion = (int) DB::table('panel_connections')->where('id', $connectionId)->value('version') + 1;
        $capabilityHash = hash('sha256', 'auto-renew-test-capabilities-'.$suffix);
        $targetEvidenceHash = hash('sha256', 'auto-renew-test-target-evidence-'.$suffix);

        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'auto-renew-test'], JSON_THROW_ON_ERROR)),
            'state' => 'active',
            'last_test_status' => 'success',
            'last_panel_version' => '1.0.0',
            'last_capabilities_hash' => $capabilityHash,
            'last_tested_at' => $now,
            'version' => $connectionVersion,
            'updated_at' => $now,
        ]);
        DB::table('panel_service_targets')->where('id', $targetId)->update([
            'state' => 'active',
            'capability_status' => 'verified',
            'capability_evidence_hash' => $targetEvidenceHash,
            'capability_verified_at' => $now,
            'verified_connection_version' => $connectionVersion,
            'version' => DB::raw('version + 1'),
            'updated_at' => $now,
        ]);
        DB::table('panel_target_capabilities')->where('panel_service_target_id', $targetId)->update([
            'verification_status' => 'verified',
            'evidence_hash' => $targetEvidenceHash,
            'verified_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('sales_servers')->where('id', $serverId)->update([
            'state' => 'active',
            'visibility' => 'listed',
            'version' => DB::raw('version + 1'),
            'updated_at' => $now,
        ]);
        DB::table('plan_offering_protocol_profiles')->where('plan_offering_id', $offeringId)->update([
            'customer_selectable' => false,
            'updated_at' => $now,
        ]);
        DB::table('plan_offerings')->where('id', $offeringId)->update([
            'server_selection_mode' => 'system_selects',
            'updated_at' => $now,
        ]);

        $tierId = (int) DB::table('customer_tiers')->where('code', 'normal')->value('id');
        if (! DB::table('customer_profiles')->where('user_id', $userId)->exists()) {
            DB::table('customer_profiles')->insert([
                'user_id' => $userId,
                'current_tier_id' => $tierId,
                'tier_locked' => false,
                'tier_lock_reason_code' => null,
                'phone_verification_status' => 'verified',
                'identity_verification_status' => 'verified',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $ownerId = $this->ownerAdministrator();
        foreach (DB::table('plan_offering_tags')->where('plan_offering_id', $offeringId)->pluck('customer_tag_id') as $tagId) {
            DB::table('customer_tag_assignments')->insert([
                'user_id' => $userId,
                'tag_id' => (int) $tagId,
                'assigned_by_administrator_id' => $ownerId,
                'assigned_at' => $now,
                'removed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
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
        $this->app->make(PlanOfferingRoutePolicyService::class)->create(
            $offeringId,
            new PlanOfferingRoutePolicyDefinition([
                new PlanOfferingRouteDefinition($serverId, $targetId, PlanOfferingRouteType::Primary, 0, false, null, null),
            ]),
            $this->catalogContext($ownerId, 'auto-renew-route-'.$suffix),
        );
        DB::table('plan_offerings')->where('id', $offeringId)->update([
            'state' => 'active',
            'visibility' => 'visible',
            'server_selection_mode' => 'system_selects',
            'protocol_selection_mode' => 'system_selects',
            'updated_at' => $now,
        ]);

        return $targetId;
    }
}

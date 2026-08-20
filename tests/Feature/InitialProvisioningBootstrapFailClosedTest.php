<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderReceipt;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-008 QUA-004 */
final class InitialProvisioningBootstrapFailClosedTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_fresh_mariadb_bootstrap_is_intrinsically_fail_closed_before_001162_triggers(): void
    {
        /** @var Migration $bootstrapMigration */
        $bootstrapMigration = require database_path('migrations/2026_08_14_001161_zz_create_intrinsically_fail_closed_provisioning_tables.php');
        /** @var Migration $queueMigration */
        $queueMigration = require database_path('migrations/2026_08_14_001162_create_provisioning_queue_authority.php');
        /** @var Migration $releaseMigration */
        $releaseMigration = require database_path('migrations/2026_08_14_001162_z_release_provisioning_bootstrap_barriers.php');
        /** @var Migration $invalidationMigration */
        $invalidationMigration = require database_path('migrations/2026_08_14_001163_harden_provisioning_financial_invalidation.php');
        /** @var Migration $outboxOrderMigration */
        $outboxOrderMigration = require database_path('migrations/2026_08_14_001164_harden_initial_provisioning_outbox_order_authority.php');
        /** @var Migration $exactAuthorityMigration */
        $exactAuthorityMigration = require database_path('migrations/2026_08_14_001164_z_enforce_exact_provisioning_authority_text.php');
        /** @var Migration $activationMigration */
        $activationMigration = require database_path('migrations/2026_08_14_001165_activate_provisioning_queue_authority.php');
        /** @var Migration $remoteEffectBarrierMigration */
        $remoteEffectBarrierMigration = require database_path('migrations/2026_08_16_000099_stage_initial_provisioning_remote_effect_barrier.php');
        /** @var Migration $remoteEffectMigration */
        $remoteEffectMigration = require database_path('migrations/2026_08_16_000100_enable_initial_provisioning_remote_effect.php');
        /** @var Migration $remoteEffectHardeningMigration */
        $remoteEffectHardeningMigration = require database_path('migrations/2026_08_16_000101_harden_initial_provisioning_remote_effect_transitions.php');
        /** @var Migration $uncertainRecoveryMigration */
        $uncertainRecoveryMigration = require database_path('migrations/2026_08_16_000102_enable_initial_provisioning_uncertain_recovery.php');
        /** @var Migration $verifiedTargetActivationMigration */
        $verifiedTargetActivationMigration = require database_path('migrations/2026_08_16_000103_allow_verified_panel_target_activation.php');
        /** @var Migration $capacityFenceMigration */
        $capacityFenceMigration = require database_path('migrations/2026_08_16_000104_fence_running_provisioning_capacity_release.php');
        /** @var Migration $remoteEffectActivationMigration */
        $remoteEffectActivationMigration = require database_path('migrations/2026_08_16_000105_activate_initial_provisioning_remote_effect.php');
        /** @var Migration $mutationFkSupportMigration */
        $mutationFkSupportMigration = require database_path('migrations/2026_08_17_000299_preserve_provisioning_operation_order_item_fk_index.php');
        /** @var Migration $mutationExactTextMigration */
        $mutationExactTextMigration = require database_path('migrations/2026_08_17_000299_z_expand_service_mutation_exact_text_authority.php');
        /** @var Migration $mutationMigration */
        $mutationMigration = require database_path('migrations/2026_08_17_000300_enable_service_mutation_authority.php');
        /** @var Migration $lifecycleAuditMigration */
        $lifecycleAuditMigration = require database_path('migrations/2026_08_17_000310_enable_service_lifecycle_command_audit_authority.php');
        /** @var Migration $deliveryAttemptMigration */
        $deliveryAttemptMigration = require database_path('migrations/2026_08_18_000100_create_service_delivery_attempt_authority.php');
        /** @var Migration $deliveryEffectMigration */
        $deliveryEffectMigration = require database_path('migrations/2026_08_18_000200_enable_service_delivery_effect_authority.php');
        /** @var Migration $nonPaidInvalidationMigration */
        $nonPaidInvalidationMigration = require database_path('migrations/2026_08_19_000115_extend_provisioning_invalidation_to_non_paid_sources.php');
        /** @var Migration $nonPaidAuthorityMigration */
        $nonPaidAuthorityMigration = require database_path('migrations/2026_08_19_000120_activate_non_paid_order_authority.php');
        /** @var Migration $serviceOperationalMigration */
        $serviceOperationalMigration = require database_path('migrations/2026_08_19_000140_enable_service_operational_authority.php');
        /** @var Migration $servicePackageQuoteMigration */
        $servicePackageQuoteMigration = require database_path('migrations/2026_08_20_000100_enable_service_package_quotes.php');
        /** @var Migration $paidServiceMutationMigration */
        $paidServiceMutationMigration = require database_path('migrations/2026_08_20_000110_enable_paid_service_mutation_authority.php');

        try {
            // Remove newer descendant authorities before replaying the historical provisioning bootstrap chain.
            $paidServiceMutationMigration->down();
            $servicePackageQuoteMigration->down();
            $serviceOperationalMigration->down();
            $deliveryEffectMigration->down();
            $deliveryAttemptMigration->down();
            $lifecycleAuditMigration->down();
            $mutationMigration->down();
            $mutationExactTextMigration->down();
            $mutationFkSupportMigration->down();

            // Reverse the complete remote-effect chain before rolling back its queue-era prerequisites.
            // 000105 first reinstalls the bootstrap barrier so every subsequent rollback boundary is closed.
            $remoteEffectActivationMigration->down();
            $capacityFenceMigration->down();
            $verifiedTargetActivationMigration->down();
            $uncertainRecoveryMigration->down();
            $remoteEffectHardeningMigration->down();
            $remoteEffectMigration->down();
            $remoteEffectBarrierMigration->down();
            $exactAuthorityMigration->down();
            $outboxOrderMigration->down();
            $invalidationMigration->down();
            $releaseMigration->down();
            $queueMigration->down();
            $bootstrapMigration->down();

            $bootstrapMigration->up();

            self::assertSame(0, $this->triggerCount('service_subscriptions_insert_guard'));
            self::assertSame(0, $this->triggerCount('provisioning_operations_insert_guard'));
            self::assertSame(0, $this->triggerCount('provisioning_operation_histories_insert_guard'));
            self::assertSame(1, $this->constraintCount('service_subscriptions', 'service_subscriptions_bootstrap_block_chk'));
            self::assertSame(1, $this->constraintCount('provisioning_operations', 'provisioning_operations_bootstrap_block_chk'));
            self::assertSame(1, $this->constraintCount('provisioning_operation_histories', 'provisioning_operation_histories_bootstrap_block_chk'));

            $order = $this->createPaidOrder('bootstrap-boundary');
            $orderRow = DB::table('orders')->where('id', $order->orderId)->first(['id', 'user_id']);
            self::assertNotNull($orderRow);
            $item = DB::table('order_items')->where('order_id', $order->orderId)->where('line_number', 1)->first(['id', 'public_id']);
            self::assertNotNull($item);
            $timestamp = $this->purchaseOrderTimestamp();

            $serviceValues = [
                'public_id' => (string) Str::ulid(),
                'order_id' => (int) $orderRow->id,
                'order_item_id' => (int) $item->id,
                'user_id' => (int) $orderRow->user_id,
                'creation_correlation_id' => $this->purchaseOrderCorrelation('bootstrap-service'),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            try {
                DB::table('service_subscriptions')->insert($serviceValues);
                self::fail('The Service table must reject inserts at the committed CREATE TABLE boundary before any trigger exists.');
            } catch (QueryException) {
                self::assertSame(0, DB::table('service_subscriptions')->count());
            }

            DB::statement('ALTER TABLE service_subscriptions DROP CONSTRAINT service_subscriptions_bootstrap_block_chk');
            $serviceId = (int) DB::table('service_subscriptions')->insertGetId($serviceValues);
            self::assertGreaterThan(0, $serviceId);

            $operationCorrelation = $this->purchaseOrderCorrelation('bootstrap-operation');
            $operationValues = [
                'public_id' => (string) Str::ulid(),
                'operation_key' => 'initial-provision:'.$item->public_id,
                'operation_type' => 'initial_provision',
                'order_id' => (int) $orderRow->id,
                'order_item_id' => (int) $item->id,
                'service_subscription_id' => $serviceId,
                'user_id' => (int) $orderRow->user_id,
                'state' => 'queued',
                'state_version' => 1,
                'correlation_id' => $operationCorrelation,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            try {
                DB::table('provisioning_operations')->insert($operationValues);
                self::fail('The Operation table must reject inserts at the committed CREATE TABLE boundary before any trigger exists.');
            } catch (QueryException) {
                self::assertSame(0, DB::table('provisioning_operations')->count());
            }

            DB::statement('ALTER TABLE provisioning_operations DROP CONSTRAINT provisioning_operations_bootstrap_block_chk');
            $operationId = (int) DB::table('provisioning_operations')->insertGetId($operationValues);
            self::assertGreaterThan(0, $operationId);

            try {
                DB::table('provisioning_operation_histories')->insert([
                    'provisioning_operation_id' => $operationId,
                    'from_state' => null,
                    'to_state' => 'queued',
                    'from_version' => null,
                    'to_version' => 1,
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'reason_code' => 'initial_provisioning_requested',
                    'correlation_id' => $operationCorrelation,
                    'created_at' => $timestamp,
                ]);
                self::fail('The Operation history table must reject inserts at the committed CREATE TABLE boundary before any trigger exists.');
            } catch (QueryException) {
                self::assertSame(0, DB::table('provisioning_operation_histories')->count());
            }

            DB::table('provisioning_operations')->where('id', $operationId)->delete();
            DB::table('service_subscriptions')->where('id', $serviceId)->delete();
            DB::statement('ALTER TABLE service_subscriptions ADD CONSTRAINT service_subscriptions_bootstrap_block_chk CHECK (0 = 1)');
            DB::statement('ALTER TABLE provisioning_operations ADD CONSTRAINT provisioning_operations_bootstrap_block_chk CHECK (0 = 1)');

            $queueMigration->up();
            self::assertSame(1, $this->triggerCount('service_subscriptions_insert_guard'));
            self::assertSame(1, $this->triggerCount('provisioning_operations_insert_guard'));
            self::assertSame(1, $this->triggerCount('provisioning_operation_histories_insert_guard'));

            $releaseMigration->up();
            self::assertSame(0, $this->constraintCount('service_subscriptions', 'service_subscriptions_bootstrap_block_chk'));
            self::assertSame(0, $this->constraintCount('provisioning_operations', 'provisioning_operations_bootstrap_block_chk'));
            self::assertSame(0, $this->constraintCount('provisioning_operation_histories', 'provisioning_operation_histories_bootstrap_block_chk'));

            $invalidationMigration->up();
            $outboxOrderMigration->up();
            $exactAuthorityMigration->up();
            $activationMigration->up();

            $remoteEffectBarrierMigration->up();
            self::assertSame(1, $this->triggerCount('initial_provisioning_remote_effect_bootstrap_barrier'));
            $remoteEffectMigration->up();
            $remoteEffectHardeningMigration->up();
            $uncertainRecoveryMigration->up();
            $verifiedTargetActivationMigration->up();
            $capacityFenceMigration->up();
            // Every intermediate DDL boundary remains blocked until the final activation migration.
            self::assertSame(1, $this->triggerCount('initial_provisioning_remote_effect_bootstrap_barrier'));
            $remoteEffectActivationMigration->up();
            self::assertSame(0, $this->triggerCount('initial_provisioning_remote_effect_bootstrap_barrier'));
            $mutationFkSupportMigration->up();
            $mutationExactTextMigration->up();
            $mutationMigration->up();
            $lifecycleAuditMigration->up();
            $deliveryAttemptMigration->up();
            $deliveryEffectMigration->up();

            $happyOrder = $this->createPaidOrder('bootstrap-history');
            $correlationId = $this->purchaseOrderCorrelation('bootstrap-history-queue');
            $receipt = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
                $happyOrder->orderPublicId,
                $correlationId,
            );

            $history = DB::table('provisioning_operation_histories')
                ->where('provisioning_operation_id', $receipt->provisioningOperationId)
                ->first([
                    'from_state', 'to_state', 'from_version', 'to_version', 'actor_type', 'actor_id', 'reason_code', 'correlation_id',
                ]);
            self::assertNotNull($history);
            self::assertNull($history->from_state);
            self::assertSame('queued', $history->to_state);
            self::assertNull($history->from_version);
            self::assertSame(1, (int) $history->to_version);
            self::assertSame('system', $history->actor_type);
            self::assertNull($history->actor_id);
            self::assertSame('initial_provisioning_requested', $history->reason_code);
            self::assertSame($correlationId, $history->correlation_id);
            self::assertSame(1, DB::table('provisioning_operation_histories')->where('provisioning_operation_id', $receipt->provisioningOperationId)->count());
        } finally {
            $bootstrapMigration->up();
            $queueMigration->up();
            $releaseMigration->up();
            $invalidationMigration->up();
            $outboxOrderMigration->up();
            $exactAuthorityMigration->up();
            $activationMigration->up();
            $remoteEffectBarrierMigration->up();
            $remoteEffectMigration->up();
            $remoteEffectHardeningMigration->up();
            $uncertainRecoveryMigration->up();
            $verifiedTargetActivationMigration->up();
            $capacityFenceMigration->up();
            $remoteEffectActivationMigration->up();
            $mutationFkSupportMigration->up();
            $mutationExactTextMigration->up();
            $mutationMigration->up();
            $lifecycleAuditMigration->up();
            $deliveryAttemptMigration->up();
            $deliveryEffectMigration->up();
            $nonPaidInvalidationMigration->up();
            $nonPaidAuthorityMigration->up();
            $serviceOperationalMigration->up();
            $servicePackageQuoteMigration->up();
            $paidServiceMutationMigration->up();
        }
    }

    private function createPaidOrder(string $suffix): PurchaseOrderReceipt
    {
        $settlement = $this->createPurchaseOrderSettlement($suffix);

        return $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('order-'.$suffix),
        );
    }

    private function triggerCount(string $trigger): int
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );

        return $row === null ? 0 : (int) $row->aggregate;
    }

    private function constraintCount(string $table, string $constraint): int
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $constraint, 'CHECK'],
        );

        return $row === null ? 0 : (int) $row->aggregate;
    }
}

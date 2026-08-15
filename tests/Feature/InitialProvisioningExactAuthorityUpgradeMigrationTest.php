<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderReceipt;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Domain\OrderState;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Shared\Application\SafeOutboxPayload;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** @requirement BUY-001 PAY-002 PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
final class InitialProvisioningExactAuthorityUpgradeMigrationTest extends TestCase
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

    protected function tearDown(): void
    {
        try {
            if (isset($this->app)) {
                $this->restoreExactUpgradeAfterTest();
                $this->truncateDatabaseTables();
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @return array<string, array{0:string}> */
    public static function durableCutPoints(): array
    {
        return [
            'outbox release fence' => ['outbox_initial_provision_exact_upgrade_fence'],
            'service insert fence' => ['service_subscriptions_exact_upgrade_fence'],
            'operation insert fence' => ['provisioning_operations_exact_upgrade_fence'],
            'service collation' => ['alter table service_subscriptions convert to character set utf8mb4 collate utf8mb4_bin'],
            'operation collation' => ['alter table provisioning_operations convert to character set utf8mb4 collate utf8mb4_bin'],
            'history collation' => ['alter table provisioning_operation_histories convert to character set utf8mb4 collate utf8mb4_bin'],
            'payment exact check' => ['add constraint `payment_intents_provisioning_exact_authority_chk`'],
            'order exact check' => ['add constraint `orders_provisioning_exact_authority_chk`'],
            'current order outbox guard' => ['create or replace trigger orders_provisioning_outbox_envelope_guard'],
            'activation service guard reentry' => ['create or replace trigger service_subscriptions_insert_guard'],
            'operation fence removal' => ['drop trigger if exists provisioning_operations_exact_upgrade_fence'],
            'outbox fence removal' => ['drop trigger if exists outbox_initial_provision_exact_upgrade_fence'],
            'order fence removal' => ['drop trigger if exists orders_provisioning_exact_upgrade_fence'],
        ];
    }

    #[DataProvider('durableCutPoints')]
    public function test_already_active_upgrade_stays_fail_closed_after_each_durable_cut(string $sqlNeedle): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_14_001164_z_enforce_exact_provisioning_authority_text.php');
        $this->prepareLegacyActiveSchema();

        self::assertTrue(
            DB::table('migrations')->where('migration', '2026_08_14_001165_activate_provisioning_queue_authority')->exists(),
            'The regression must model a database where 001165 is already recorded and will not be auto-replayed by Laravel.',
        );

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected, $sqlNeedle): void {
            if ($injected || ! str_contains(strtolower($query->sql), strtolower($sqlNeedle))) {
                return;
            }

            $injected = true;
            throw new RuntimeException('Injected durable exact-authority upgrade cut: '.$sqlNeedle);
        });

        try {
            $migration->up();
            self::fail('The migration fault injector must interrupt after the requested committed DDL statement.');
        } catch (RuntimeException $exception) {
            self::assertTrue($injected, 'The requested committed DDL cut point was not reached.');
            self::assertStringStartsWith('Injected durable exact-authority upgrade cut:', $exception->getMessage());
        }

        $this->assertQueueAttemptFailsWithoutDurableAuthority('partial-'.substr(hash('sha256', $sqlNeedle), 0, 10));

        // Restart the exact same migration object against its persisted partial DDL. The
        // listener is one-shot, so this must converge without relying on migration-table replay.
        $migration->up();
        $this->assertUpgradeFencesAbsent();
        $this->assertExactProvisioningColumnsReady();
    }

    public function test_first_committed_fence_blocks_case_variant_graph_and_restart_refuses_the_anomaly(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_14_001164_z_enforce_exact_provisioning_authority_text.php');
        $this->prepareLegacyActiveSchema();

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if ($injected || ! str_contains(strtolower($query->sql), 'orders_provisioning_exact_upgrade_fence')) {
                return;
            }

            $injected = true;
            throw new RuntimeException('Injected immediately after the first durable upgrade fence.');
        });

        try {
            $migration->up();
            self::fail('The first-fence fault injector must interrupt the migration.');
        } catch (RuntimeException $exception) {
            self::assertTrue($injected);
            self::assertSame('Injected immediately after the first durable upgrade fence.', $exception->getMessage());
        }

        $order = $this->createPaidOrder('first-fence-case-variant');
        $authority = $this->createLegacyCaseVariantAuthority($order, 'first-fence-case-variant');
        self::assertSame('QUEUED', DB::table('provisioning_operations')->where('id', $authority['operation_id'])->value('state'));
        self::assertSame('QUEUED', DB::table('provisioning_operation_histories')->where('provisioning_operation_id', $authority['operation_id'])->value('to_state'));
        self::assertSame('authority_pending', DB::table('outbox_messages')->where('id', $authority['outbox_id'])->value('dispatch_state'));

        try {
            DB::table('orders')->where('id', $order->orderId)->update([
                'state' => OrderState::ProvisioningQueued->value,
                'state_version' => 2,
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::fail('The first durable upgrade fence must block final Order authority even before later fences exist.');
        } catch (QueryException) {
            self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
        }

        try {
            DB::table('outbox_messages')->where('id', $authority['outbox_id'])->update([
                'dispatch_state' => 'pending',
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::fail('A held command must remain unreleasable while its Order is still fenced at paid/v1.');
        } catch (QueryException) {
            self::assertSame('authority_pending', DB::table('outbox_messages')->where('id', $authority['outbox_id'])->value('dispatch_state'));
        }

        try {
            $migration->up();
            self::fail('Restart must refuse to normalize a case-variant authority graph admitted during the earliest cut.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Existing Provisioning Operation authority is not byte-canonical; exact-text upgrade remains fenced.',
                $exception->getMessage(),
            );
        }

        self::assertTrue($this->triggerExists('orders_provisioning_exact_upgrade_fence'));
        self::assertTrue($this->triggerExists('service_subscriptions_exact_upgrade_fence'));
        self::assertTrue($this->triggerExists('provisioning_operations_exact_upgrade_fence'));

        // TRUNCATE is deliberate fault-harness cleanup and does not fire the immutable row
        // triggers. With the anomaly removed, the same pending migration must converge.
        $this->truncateDatabaseTables();
        $migration->up();
        $this->assertUpgradeFencesAbsent();
        $this->assertExactProvisioningColumnsReady();
    }

    public function test_crash_after_final_service_fence_drop_is_already_safe_and_reentrant(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_14_001164_z_enforce_exact_provisioning_authority_text.php');
        $this->prepareLegacyActiveSchema();

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if ($injected || ! str_contains(strtolower($query->sql), 'drop trigger if exists service_subscriptions_exact_upgrade_fence')) {
                return;
            }

            $injected = true;
            throw new RuntimeException('Injected after the final enabling DDL.');
        });

        try {
            $migration->up();
            self::fail('The final-cut injector must interrupt after the Service fence is durably removed.');
        } catch (RuntimeException $exception) {
            self::assertTrue($injected);
            self::assertSame('Injected after the final enabling DDL.', $exception->getMessage());
        }

        $this->assertUpgradeFencesAbsent();
        $this->assertExactProvisioningColumnsReady();

        $order = $this->createPaidOrder('final-cut-safe');
        $receipt = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('final-cut-safe-queue'),
        );
        self::assertSame(OrderState::ProvisioningQueued, $receipt->orderState);
        self::assertSame('pending', DB::table('outbox_messages')->where('id', $receipt->outboxEventId)->value('dispatch_state'));

        // Laravel could fail before recording the migration even though the last DDL committed.
        // Re-running must temporarily fence, reconverge idempotently, and reactivate safely.
        $migration->up();
        $this->assertUpgradeFencesAbsent();
    }

    private function prepareLegacyActiveSchema(): void
    {
        foreach ([
            ['orders', 'orders_provisioning_exact_authority_chk'],
            ['payment_intents', 'payment_intents_provisioning_exact_authority_chk'],
        ] as [$table, $constraint]) {
            if ($this->constraintExists($table, $constraint)) {
                DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
            }
        }

        DB::statement('ALTER TABLE service_subscriptions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        DB::statement('ALTER TABLE provisioning_operations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        DB::statement('ALTER TABLE provisioning_operation_histories CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        foreach ([
            'orders_provisioning_exact_upgrade_fence',
            'outbox_initial_provision_exact_upgrade_fence',
            'service_subscriptions_exact_upgrade_fence',
            'provisioning_operations_exact_upgrade_fence',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$trigger}`");
        }

        self::assertTrue($this->triggerContains('service_subscriptions_insert_guard', 'currently captured authoritative purchase Order Item'));
        self::assertTrue($this->triggerContains('provisioning_operations_insert_guard', 'matching captured purchase authority and Service identity'));
        self::assertTrue($this->triggerContains('orders_update_guard', 'Only paid/v1 to provisioning_queued/v2'));

        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND COLLATION_NAME = 'utf8mb4_unicode_ci'
  AND (
      (TABLE_NAME = 'service_subscriptions'
       AND COLUMN_NAME IN ('public_id', 'creation_correlation_id'))
      OR
      (TABLE_NAME = 'provisioning_operations'
       AND COLUMN_NAME IN ('public_id', 'operation_key', 'operation_type', 'state', 'correlation_id'))
      OR
      (TABLE_NAME = 'provisioning_operation_histories'
       AND COLUMN_NAME IN ('from_state', 'to_state', 'actor_type', 'reason_code', 'correlation_id'))
  )
SQL);
        self::assertNotNull($row);
        self::assertSame(12, (int) $row->aggregate);
    }

    private function assertQueueAttemptFailsWithoutDurableAuthority(string $suffix): void
    {
        $order = $this->createPaidOrder($suffix);

        try {
            $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
                $order->orderPublicId,
                $this->purchaseOrderCorrelation('queue-'.$suffix),
            );
            self::fail('Every persisted pre-activation cut must reject a new queue transaction.');
        } catch (QueryException) {
            // Expected from either the durable upgrade fence or a temporarily re-entered fail-closed guard.
        }

        self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
        self::assertSame(1, (int) DB::table('orders')->where('id', $order->orderId)->value('state_version'));
        self::assertSame(0, DB::table('service_subscriptions')->where('order_id', $order->orderId)->count());
        self::assertSame(0, DB::table('provisioning_operations')->where('order_id', $order->orderId)->count());
        self::assertSame(
            0,
            DB::table('outbox_messages')
                ->where('event_type', 'provisioning.initial.requested')
                ->where('payload', 'like', '%'.$order->orderPublicId.'%')
                ->count(),
        );
    }

    /** @return array{operation_id:int,outbox_id:string} */
    private function createLegacyCaseVariantAuthority(PurchaseOrderReceipt $order, string $suffix): array
    {
        $orderRow = DB::table('orders')->where('id', $order->orderId)->first(['id', 'user_id']);
        self::assertNotNull($orderRow);
        $item = DB::table('order_items')->where('order_id', $order->orderId)->where('line_number', 1)->first(['id', 'public_id']);
        self::assertNotNull($item);

        $timestamp = $this->purchaseOrderTimestamp();
        $correlationId = $this->purchaseOrderCorrelation('legacy-operation-'.$suffix);
        $servicePublicId = (string) Str::ulid();
        $serviceId = (int) DB::table('service_subscriptions')->insertGetId([
            'public_id' => $servicePublicId,
            'order_id' => (int) $orderRow->id,
            'order_item_id' => (int) $item->id,
            'user_id' => (int) $orderRow->user_id,
            'creation_correlation_id' => $correlationId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $operationPublicId = (string) Str::ulid();
        $operationId = (int) DB::table('provisioning_operations')->insertGetId([
            'public_id' => $operationPublicId,
            'operation_key' => 'initial-provision:'.$item->public_id,
            'operation_type' => 'INITIAL_PROVISION',
            'order_id' => (int) $orderRow->id,
            'order_item_id' => (int) $item->id,
            'service_subscription_id' => $serviceId,
            'user_id' => (int) $orderRow->user_id,
            'state' => 'QUEUED',
            'state_version' => 1,
            'correlation_id' => $correlationId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $payload = new SafeOutboxPayload([
            'order_public_id' => $order->orderPublicId,
            'order_item_public_id' => (string) $item->public_id,
            'provisioning_operation_public_id' => $operationPublicId,
            'service_subscription_public_id' => $servicePublicId,
        ]);
        $outboxId = (string) Str::uuid();
        DB::table('outbox_messages')->insert([
            'id' => $outboxId,
            'event_key' => 'provisioning.initial.requested:'.$operationPublicId,
            'event_type' => 'provisioning.initial.requested',
            'aggregate_type' => 'provisioning_operation',
            'aggregate_id' => $operationPublicId,
            'payload' => $payload->json(),
            'payload_hash' => $payload->hash(),
            'correlation_id' => $correlationId,
            'available_at' => $timestamp,
            'processed_at' => null,
            'attempts' => 0,
            'last_error_class' => null,
            'last_error_code' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return ['operation_id' => $operationId, 'outbox_id' => $outboxId];
    }

    private function createPaidOrder(string $suffix): PurchaseOrderReceipt
    {
        $settlement = $this->createPurchaseOrderSettlement($suffix);

        return $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('order-'.$suffix),
        );
    }

    private function restoreExactUpgradeAfterTest(): void
    {
        try {
            /** @var Migration $migration */
            $migration = require database_path('migrations/2026_08_14_001164_z_enforce_exact_provisioning_authority_text.php');
            $migration->up();
        } catch (RuntimeException) {
            // A deliberate anomaly test can leave noncanonical rows. Teardown truncates them;
            // the test itself is responsible for reconverging before returning.
        }
    }

    private function assertExactProvisioningColumnsReady(): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND COLLATION_NAME = 'utf8mb4_bin'
  AND (
      (TABLE_NAME = 'service_subscriptions'
       AND COLUMN_NAME IN ('public_id', 'creation_correlation_id'))
      OR
      (TABLE_NAME = 'provisioning_operations'
       AND COLUMN_NAME IN ('public_id', 'operation_key', 'operation_type', 'state', 'correlation_id'))
      OR
      (TABLE_NAME = 'provisioning_operation_histories'
       AND COLUMN_NAME IN ('from_state', 'to_state', 'actor_type', 'reason_code', 'correlation_id'))
  )
SQL);

        self::assertNotNull($row);
        self::assertSame(12, (int) $row->aggregate);
        self::assertTrue($this->constraintExists('payment_intents', 'payment_intents_provisioning_exact_authority_chk'));
        self::assertTrue($this->constraintExists('orders', 'orders_provisioning_exact_authority_chk'));
        self::assertTrue($this->triggerContains('outbox_initial_provision_envelope_update_guard', 'exact dispatch lifecycle state'));
    }

    private function assertUpgradeFencesAbsent(): void
    {
        foreach ([
            'orders_provisioning_exact_upgrade_fence',
            'outbox_initial_provision_exact_upgrade_fence',
            'service_subscriptions_exact_upgrade_fence',
            'provisioning_operations_exact_upgrade_fence',
        ] as $trigger) {
            self::assertFalse($this->triggerExists($trigger), $trigger.' must be absent after exact authority convergence.');
        }
    }

    private function triggerExists(string $trigger): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function triggerContains(string $trigger, string $needle): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? AND LOCATE(?, ACTION_STATEMENT) > 0',
            [$trigger, $needle],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $constraint, 'CHECK'],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }
}

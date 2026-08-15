<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement BUY-001 PAY-002 PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        if (! $this->exactProvisioningTextAuthorityReady() || ! $this->exactUpstreamAuthorityConstraintsReady()) {
            throw new RuntimeException('Provisioning queue activation requires byte-exact provisioning authority text semantics.');
        }

        $queueAuthority = require __DIR__.'/2026_08_14_001162_create_provisioning_queue_authority.php';
        if (! is_object($queueAuthority) || ! method_exists($queueAuthority, 'up')) {
            throw new RuntimeException('Provisioning queue authority migration cannot be re-entered safely.');
        }
        $queueAuthority->up();

        if (! $this->triggerContains('service_subscriptions_insert_guard', 'currently captured authoritative purchase Order Item')
            || ! $this->triggerContains('provisioning_operations_insert_guard', 'matching captured purchase authority and Service identity')
            || ! $this->triggerContains('orders_update_guard', 'Only paid/v1 to provisioning_queued/v2')) {
            throw new RuntimeException('Provisioning queue activation prerequisites are incomplete; queue authority remains fail-closed.');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('service_subscriptions') && DB::table('service_subscriptions')->exists()) {
            throw new RuntimeException('Cannot deactivate provisioning queue authority while Service Subscriptions exist.');
        }
        if (DB::table('orders')->where('state', 'provisioning_queued')->exists()) {
            throw new RuntimeException('Cannot deactivate provisioning queue authority while queued Orders exist.');
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_insert_guard
BEFORE INSERT ON service_subscriptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription creation is disabled until provisioning authority migration completes.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_insert_guard
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning Operation creation is disabled until provisioning authority migration completes.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_update_guard
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order mutation is not enabled by the current lifecycle authority.';
END
SQL);
    }

    private function exactProvisioningTextAuthorityReady(): bool
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

        return $row !== null && (int) $row->aggregate === 12;
    }

    private function exactUpstreamAuthorityConstraintsReady(): bool
    {
        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND CONSTRAINT_TYPE = 'CHECK'
  AND (
      (TABLE_NAME = 'payment_intents' AND CONSTRAINT_NAME = 'payment_intents_provisioning_exact_authority_chk')
      OR
      (TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'orders_provisioning_exact_authority_chk')
      OR
      (TABLE_NAME = 'outbox_messages' AND CONSTRAINT_NAME = 'outbox_initial_provision_dispatch_exact_chk')
  )
SQL);

        return $row !== null && (int) $row->aggregate === 3;
    }

    private function triggerContains(string $trigger, string $needle): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? AND LOCATE(?, ACTION_STATEMENT) > 0',
            [$trigger, $needle],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }
};

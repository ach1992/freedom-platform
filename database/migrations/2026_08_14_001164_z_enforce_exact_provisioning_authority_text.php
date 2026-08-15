<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement BUY-001 PAY-002 PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        // MariaDB DDL commits per statement. The first durable statement quarantines release
        // of every held initial-provisioning command, including authority that an older
        // case-insensitive deployment already advanced to a queued Order before this upgrade.
        $this->installOutboxReleaseUpgradeFence();
        $this->installOrderTransitionUpgradeFence();
        $this->installServiceInsertUpgradeFence();
        $this->installOperationInsertUpgradeFence();

        // A caller could have raced between the first and later fence statements, or the
        // database could already contain authority admitted by the old CI/PAD SPACE semantics.
        // Never normalize such rows silently: keep the durable fences and require repair instead.
        $this->assertNoNonCanonicalAuthorityRows();

        DB::statement('ALTER TABLE service_subscriptions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
        DB::statement('ALTER TABLE provisioning_operations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
        DB::statement('ALTER TABLE provisioning_operation_histories CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');

        // utf8mb4_bin is case-sensitive but PAD SPACE. These constraints make the authority
        // columns byte-sensitive where ordinary SQL equality is used by the steady-state guards.
        $this->replaceConstraint(
            'service_subscriptions',
            'service_subscriptions_provisioning_exact_text_chk',
            <<<'SQL'
CHECK (
    BINARY `creation_correlation_id` = BINARY RTRIM(`creation_correlation_id`)
)
SQL,
        );

        $this->replaceConstraint(
            'provisioning_operations',
            'provisioning_operations_provisioning_exact_text_chk',
            <<<'SQL'
CHECK (
    BINARY `operation_type` = BINARY 'initial_provision'
    AND BINARY `operation_key` = BINARY RTRIM(`operation_key`)
    AND BINARY `state` IN (
        BINARY 'queued', BINARY 'running', BINARY 'uncertain_remote_result', BINARY 'retry_scheduled',
        BINARY 'succeeded', BINARY 'failed_final', BINARY 'needs_review', BINARY 'compensating', BINARY 'compensated'
    )
    AND BINARY `correlation_id` = BINARY RTRIM(`correlation_id`)
)
SQL,
        );

        $this->replaceConstraint(
            'provisioning_operation_histories',
            'provisioning_operation_histories_provisioning_exact_text_chk',
            <<<'SQL'
CHECK (
    (`from_state` IS NULL OR BINARY `from_state` IN (
        BINARY 'queued', BINARY 'running', BINARY 'uncertain_remote_result', BINARY 'retry_scheduled',
        BINARY 'succeeded', BINARY 'failed_final', BINARY 'needs_review', BINARY 'compensating', BINARY 'compensated'
    ))
    AND BINARY `to_state` IN (
        BINARY 'queued', BINARY 'running', BINARY 'uncertain_remote_result', BINARY 'retry_scheduled',
        BINARY 'succeeded', BINARY 'failed_final', BINARY 'needs_review', BINARY 'compensating', BINARY 'compensated'
    )
    AND BINARY `actor_type` IN (BINARY 'system', BINARY 'customer', BINARY 'agent', BINARY 'administrator')
    AND BINARY `reason_code` = BINARY RTRIM(`reason_code`)
    AND BINARY `correlation_id` = BINARY RTRIM(`correlation_id`)
)
SQL,
        );

        $this->replaceConstraint(
            'payment_intents',
            'payment_intents_provisioning_exact_authority_chk',
            <<<'SQL'
CHECK (
    (`purpose` <> 'purchase' OR BINARY `purpose` = BINARY 'purchase')
    AND (
        `state` NOT IN ('captured', 'refund_pending', 'partially_refunded', 'refunded')
        OR BINARY `state` IN (BINARY 'captured', BINARY 'refund_pending', BINARY 'partially_refunded', BINARY 'refunded')
    )
    AND (`currency` <> 'IRR' OR BINARY `currency` = BINARY 'IRR')
)
SQL,
        );

        $this->replaceConstraint(
            'orders',
            'orders_provisioning_exact_authority_chk',
            <<<'SQL'
CHECK (
    (`source_type` <> 'purchase' OR BINARY `source_type` = BINARY 'purchase')
    AND (
        `state` NOT IN ('paid', 'provisioning_queued')
        OR BINARY `state` IN (BINARY 'paid', BINARY 'provisioning_queued')
    )
    AND (`currency` <> 'IRR' OR BINARY `currency` = BINARY 'IRR')
)
SQL,
        );

        // Reinstall both the exact Outbox guard and its Order envelope companion even when
        // their original migrations are already recorded. The separate upgrade fences remain
        // present throughout this re-entry, so partial nested DDL cannot reopen authority.
        $outboxOrder = require __DIR__.'/2026_08_14_001164_harden_initial_provisioning_outbox_order_authority.php';
        if (! is_object($outboxOrder) || ! method_exists($outboxOrder, 'up')) {
            throw new RuntimeException('Exact provisioning Outbox and Order guards cannot be re-entered safely.');
        }
        $outboxOrder->up();

        // Explicitly re-enter final activation. Laravel will not re-run an already-recorded
        // 001165 merely because its source changed, so the convergence migration owns this.
        $activation = require __DIR__.'/2026_08_14_001165_activate_provisioning_queue_authority.php';
        if (! is_object($activation) || ! method_exists($activation, 'up')) {
            throw new RuntimeException('Provisioning queue activation cannot be re-entered safely after exact-text convergence.');
        }
        $activation->up();

        $this->assertExactAuthorityReady();

        // Everything below is enabling DDL. Keep the Service fence until the final statement:
        // no new queue transaction can begin durably before every exact guard is verified.
        $this->dropUpgradeFences();
    }

    public function down(): void
    {
        // Rollback must not recreate the prior active authority. Quarantine held command
        // release at the first durable cut, then deactivate the already-recorded 001165
        // authority before removing only the additive exact checks. Binary collations stay.
        $this->installOutboxReleaseUpgradeFence();
        $this->installOrderTransitionUpgradeFence();
        $this->installServiceInsertUpgradeFence();
        $this->installOperationInsertUpgradeFence();

        $activation = require __DIR__.'/2026_08_14_001165_activate_provisioning_queue_authority.php';
        if (! is_object($activation) || ! method_exists($activation, 'down')) {
            throw new RuntimeException('Provisioning queue activation cannot be deactivated safely during exact-text rollback.');
        }
        $activation->down();

        foreach ([
            ['orders', 'orders_provisioning_exact_authority_chk'],
            ['payment_intents', 'payment_intents_provisioning_exact_authority_chk'],
            ['provisioning_operation_histories', 'provisioning_operation_histories_provisioning_exact_text_chk'],
            ['provisioning_operations', 'provisioning_operations_provisioning_exact_text_chk'],
            ['service_subscriptions', 'service_subscriptions_provisioning_exact_text_chk'],
        ] as [$table, $constraint]) {
            if ($this->constraintExists($table, $constraint)) {
                DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
            }
        }

        // Base Service/Operation/Order guards are now explicitly fail-closed, so these temporary
        // fences can be removed without leaving trigger dependencies that break deeper rollback.
        $this->dropUpgradeFences();
    }

    private function installOrderTransitionUpgradeFence(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_provisioning_exact_upgrade_fence
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    IF NEW.state = 'provisioning_queued' AND NEW.state_version = 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning queue transition is fenced during exact-text authority upgrade.';
    END IF;
END
SQL);
    }

    private function installOutboxReleaseUpgradeFence(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_initial_provision_exact_upgrade_fence
BEFORE UPDATE ON outbox_messages
FOR EACH ROW
BEGIN
    IF LOWER(OLD.event_type) = 'provisioning.initial.requested'
       AND OLD.dispatch_state = 'authority_pending'
       AND NOT (OLD.dispatch_state <=> NEW.dispatch_state) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox release is fenced during exact-text authority upgrade.';
    END IF;
END
SQL);
    }

    private function installServiceInsertUpgradeFence(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_exact_upgrade_fence
BEFORE INSERT ON service_subscriptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription creation is fenced during exact-text authority upgrade.';
END
SQL);
    }

    private function installOperationInsertUpgradeFence(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_exact_upgrade_fence
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning Operation creation is fenced during exact-text authority upgrade.';
END
SQL);
    }

    private function dropUpgradeFences(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_operations_exact_upgrade_fence');
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_initial_provision_exact_upgrade_fence');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_provisioning_exact_upgrade_fence');
        DB::unprepared('DROP TRIGGER IF EXISTS service_subscriptions_exact_upgrade_fence');
    }

    private function assertNoNonCanonicalAuthorityRows(): void
    {
        $this->assertZeroCount(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM payment_intents
WHERE (`purpose` = 'purchase' AND HEX(`purpose`) <> HEX('purchase'))
   OR (`state` IN ('captured', 'refund_pending', 'partially_refunded', 'refunded')
       AND HEX(`state`) NOT IN (HEX('captured'), HEX('refund_pending'), HEX('partially_refunded'), HEX('refunded')))
   OR (`currency` = 'IRR' AND HEX(`currency`) <> HEX('IRR'))
SQL, 'Existing PaymentIntent provisioning authority is not byte-canonical; exact-text upgrade remains fenced.');

        $this->assertZeroCount(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM orders
WHERE (`source_type` = 'purchase' AND HEX(`source_type`) <> HEX('purchase'))
   OR (`state` IN ('paid', 'provisioning_queued')
       AND HEX(`state`) NOT IN (HEX('paid'), HEX('provisioning_queued')))
   OR (`currency` = 'IRR' AND HEX(`currency`) <> HEX('IRR'))
SQL, 'Existing Order provisioning authority is not byte-canonical; exact-text upgrade remains fenced.');

        $this->assertZeroCount(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM service_subscriptions
WHERE HEX(`creation_correlation_id`) <> HEX(RTRIM(`creation_correlation_id`))
SQL, 'Existing Service Subscription provisioning authority is not byte-canonical; exact-text upgrade remains fenced.');

        $this->assertZeroCount(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM provisioning_operations operation_row
INNER JOIN order_items item_row ON item_row.id = operation_row.order_item_id
WHERE HEX(operation_row.operation_type) <> HEX('initial_provision')
   OR HEX(operation_row.operation_key) <> HEX(CONCAT('initial-provision:', item_row.public_id))
   OR HEX(operation_row.state) NOT IN (
       HEX('queued'), HEX('running'), HEX('uncertain_remote_result'), HEX('retry_scheduled'),
       HEX('succeeded'), HEX('failed_final'), HEX('needs_review'), HEX('compensating'), HEX('compensated')
   )
   OR HEX(operation_row.correlation_id) <> HEX(RTRIM(operation_row.correlation_id))
SQL, 'Existing Provisioning Operation authority is not byte-canonical; exact-text upgrade remains fenced.');

        $this->assertZeroCount(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM provisioning_operation_histories
WHERE (`from_state` IS NOT NULL AND HEX(`from_state`) NOT IN (
          HEX('queued'), HEX('running'), HEX('uncertain_remote_result'), HEX('retry_scheduled'),
          HEX('succeeded'), HEX('failed_final'), HEX('needs_review'), HEX('compensating'), HEX('compensated')
      ))
   OR HEX(`to_state`) NOT IN (
          HEX('queued'), HEX('running'), HEX('uncertain_remote_result'), HEX('retry_scheduled'),
          HEX('succeeded'), HEX('failed_final'), HEX('needs_review'), HEX('compensating'), HEX('compensated')
      )
   OR HEX(`actor_type`) NOT IN (HEX('system'), HEX('customer'), HEX('agent'), HEX('administrator'))
   OR HEX(`reason_code`) <> HEX(RTRIM(`reason_code`))
   OR HEX(`correlation_id`) <> HEX(RTRIM(`correlation_id`))
SQL, 'Existing Provisioning Operation history is not byte-canonical; exact-text upgrade remains fenced.');

        $this->assertZeroCount(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM outbox_messages
WHERE LOWER(event_type) = 'provisioning.initial.requested'
  AND (
      HEX(event_type) <> HEX('provisioning.initial.requested')
      OR HEX(dispatch_state) NOT IN (
          HEX('authority_pending'), HEX('pending'), HEX('leased'), HEX('retry'), HEX('processed'), HEX('review_required')
      )
  )
SQL, 'Existing initial provisioning Outbox authority is not byte-canonical; exact-text upgrade remains fenced.');
    }

    private function replaceConstraint(string $table, string $constraint, string $definition): void
    {
        if ($this->constraintExists($table, $constraint)) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` {$definition}");
    }

    private function assertExactAuthorityReady(): void
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

        if ($row === null || (int) $row->aggregate !== 12
            || ! $this->constraintExists('payment_intents', 'payment_intents_provisioning_exact_authority_chk')
            || ! $this->constraintExists('orders', 'orders_provisioning_exact_authority_chk')
            || ! $this->constraintExists('service_subscriptions', 'service_subscriptions_provisioning_exact_text_chk')
            || ! $this->constraintExists('provisioning_operations', 'provisioning_operations_provisioning_exact_text_chk')
            || ! $this->constraintExists('provisioning_operation_histories', 'provisioning_operation_histories_provisioning_exact_text_chk')
            || ! $this->triggerContains('service_subscriptions_insert_guard', 'currently captured authoritative purchase Order Item')
            || ! $this->triggerContains('provisioning_operations_insert_guard', 'matching captured purchase authority and Service identity')
            || ! $this->triggerContains('orders_update_guard', 'Only paid/v1 to provisioning_queued/v2')
            || ! $this->triggerContains('orders_provisioning_outbox_envelope_guard', 'one exact canonical safe Outbox command')
            || ! $this->triggerContains('outbox_initial_provision_envelope_update_guard', 'exact dispatch lifecycle state')) {
            throw new RuntimeException('Exact provisioning authority upgrade did not converge; queue remains fenced.');
        }

        $this->assertNoNonCanonicalAuthorityRows();
    }

    private function assertZeroCount(string $sql, string $message): void
    {
        $row = DB::selectOne($sql);
        if ($row === null || (int) $row->aggregate !== 0) {
            throw new RuntimeException($message);
        }
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $constraint, 'CHECK'],
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
};

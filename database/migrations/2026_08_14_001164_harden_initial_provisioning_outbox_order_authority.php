<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        /** @var Migration $outboxEnvelopeMigration */
        $outboxEnvelopeMigration = require database_path('migrations/2026_08_14_001161_z_harden_initial_provisioning_outbox_envelope.php');
        $outboxEnvelopeMigration->up();

        // This trigger is created last: 001162 uses its presence as the completion marker
        // before enabling queue authority on the 001165 re-entry.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_provisioning_outbox_envelope_guard
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    DECLARE valid_outbox_count INT DEFAULT 0;

    IF OLD.state = 'paid'
       AND OLD.state_version = 1
       AND NEW.state = 'provisioning_queued'
       AND NEW.state_version = 2 THEN
        SELECT COUNT(*) INTO valid_outbox_count
        FROM order_items item_row
        INNER JOIN service_subscriptions service_row
            ON service_row.order_id = OLD.id
           AND service_row.order_item_id = item_row.id
           AND service_row.user_id = OLD.user_id
        INNER JOIN provisioning_operations operation_row
            ON operation_row.order_id = OLD.id
           AND operation_row.order_item_id = item_row.id
           AND operation_row.service_subscription_id = service_row.id
           AND operation_row.user_id = OLD.user_id
           AND operation_row.operation_type = 'initial_provision'
           AND operation_row.operation_key = CONCAT('initial-provision:', item_row.public_id)
           AND operation_row.state = 'queued'
           AND operation_row.state_version = 1
        INNER JOIN outbox_messages outbox_row
            ON HEX(outbox_row.event_key) = HEX(CONCAT('provisioning.initial.requested:', operation_row.public_id))
           AND HEX(outbox_row.event_type) = HEX('provisioning.initial.requested')
           AND HEX(outbox_row.aggregate_type) = HEX('provisioning_operation')
           AND HEX(outbox_row.aggregate_id) = HEX(operation_row.public_id)
           AND HEX(outbox_row.correlation_id) = HEX(operation_row.correlation_id)
           AND outbox_row.dispatch_state = 'authority_pending'
        WHERE item_row.order_id = OLD.id
          AND item_row.line_number = 1
          AND HEX(CAST(outbox_row.payload AS CHAR)) = HEX(CONCAT(
                '{"order_item_public_id":"', item_row.public_id,
                '","order_public_id":"', OLD.public_id,
                '","provisioning_operation_public_id":"', operation_row.public_id,
                '","service_subscription_public_id":"', service_row.public_id,
                '"}'
          ))
          AND HEX(outbox_row.payload_hash) = HEX(LOWER(SHA2(CAST(outbox_row.payload AS CHAR), 256)));

        IF valid_outbox_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order provisioning queue transition requires one exact canonical safe Outbox command.';
        END IF;
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS orders_provisioning_outbox_envelope_guard');
    }
};

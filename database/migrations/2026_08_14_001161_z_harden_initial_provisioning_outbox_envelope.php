<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_initial_provision_envelope_insert_guard
BEFORE INSERT ON outbox_messages
FOR EACH ROW
BEGIN
    IF NEW.event_type = 'provisioning.initial.requested' THEN
        IF COALESCE(JSON_TYPE(NEW.payload), '') <> 'OBJECT'
           OR COALESCE(JSON_LENGTH(NEW.payload), -1) <> 4
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.payload, '$.order_item_public_id')), '') <> 'STRING'
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.payload, '$.order_public_id')), '') <> 'STRING'
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id')), '') <> 'STRING'
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.payload, '$.service_subscription_public_id')), '') <> 'STRING'
           OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_item_public_id')), '') NOT REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
           OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_public_id')), '') NOT REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
           OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id')), '') NOT REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
           OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_subscription_public_id')), '') NOT REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
           OR HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_item_public_id')), ''))
                <> HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_item_public_id')), '')))
           OR HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_public_id')), ''))
                <> HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_public_id')), '')))
           OR HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id')), ''))
                <> HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id')), '')))
           OR HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_subscription_public_id')), ''))
                <> HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_subscription_public_id')), '')))
           OR HEX(CAST(NEW.payload AS CHAR)) <> HEX(CONCAT(
                '{"order_item_public_id":"', JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_item_public_id')),
                '","order_public_id":"', JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_public_id')),
                '","provisioning_operation_public_id":"', JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id')),
                '","service_subscription_public_id":"', JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_subscription_public_id')),
                '"}'
           ))
           OR HEX(NEW.payload_hash) <> HEX(LOWER(SHA2(CAST(NEW.payload AS CHAR), 256)))
           OR HEX(NEW.event_key) <> HEX(CONCAT(
                'provisioning.initial.requested:',
                JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id'))
           ))
           OR HEX(NEW.aggregate_type) <> HEX('provisioning_operation')
           OR HEX(NEW.aggregate_id) <> HEX(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id'))) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox command must use the exact canonical safe envelope.';
        END IF;
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_initial_provision_envelope_insert_guard');
    }
};

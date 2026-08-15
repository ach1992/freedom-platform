<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        if ($this->constraintExists()) {
            return;
        }

        DB::statement(<<<'SQL'
ALTER TABLE outbox_messages
ADD CONSTRAINT outbox_initial_provision_envelope_chk CHECK (
    `event_type` <> 'provisioning.initial.requested'
    OR (
        COALESCE(JSON_TYPE(`payload`), '') = 'OBJECT'
        AND COALESCE(JSON_LENGTH(`payload`), -1) = 4
        AND COALESCE(JSON_TYPE(JSON_EXTRACT(`payload`, '$.order_item_public_id')), '') = 'STRING'
        AND COALESCE(JSON_TYPE(JSON_EXTRACT(`payload`, '$.order_public_id')), '') = 'STRING'
        AND COALESCE(JSON_TYPE(JSON_EXTRACT(`payload`, '$.provisioning_operation_public_id')), '') = 'STRING'
        AND COALESCE(JSON_TYPE(JSON_EXTRACT(`payload`, '$.service_subscription_public_id')), '') = 'STRING'
        AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.order_item_public_id')), '') REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
        AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.order_public_id')), '') REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
        AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.provisioning_operation_public_id')), '') REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
        AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.service_subscription_public_id')), '') REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
        AND HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.order_item_public_id')), ''))
            = HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.order_item_public_id')), '')))
        AND HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.order_public_id')), ''))
            = HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.order_public_id')), '')))
        AND HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.provisioning_operation_public_id')), ''))
            = HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.provisioning_operation_public_id')), '')))
        AND HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.service_subscription_public_id')), ''))
            = HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.service_subscription_public_id')), '')))
        AND HEX(CAST(`payload` AS CHAR)) = HEX(CONCAT(
            '{"order_item_public_id":"', JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.order_item_public_id')),
            '","order_public_id":"', JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.order_public_id')),
            '","provisioning_operation_public_id":"', JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.provisioning_operation_public_id')),
            '","service_subscription_public_id":"', JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.service_subscription_public_id')),
            '"}'
        ))
        AND HEX(`payload_hash`) = HEX(LOWER(SHA2(CAST(`payload` AS CHAR), 256)))
        AND HEX(`event_key`) = HEX(CONCAT(
            'provisioning.initial.requested:',
            JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.provisioning_operation_public_id'))
        ))
        AND HEX(`aggregate_type`) = HEX('provisioning_operation')
        AND HEX(`aggregate_id`) = HEX(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.provisioning_operation_public_id')))
    )
)
SQL);
    }

    public function down(): void
    {
        if ($this->constraintExists()) {
            DB::statement('ALTER TABLE outbox_messages DROP CONSTRAINT outbox_initial_provision_envelope_chk');
        }
    }

    private function constraintExists(): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['outbox_messages', 'outbox_initial_provision_envelope_chk'],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }
};

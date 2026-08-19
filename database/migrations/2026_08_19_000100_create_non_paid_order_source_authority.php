<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement BUY-001 BUY-002 ADM-002 ACL-001 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        if (! Schema::hasTable('order_source_authorizations')) {
            Schema::create('order_source_authorizations', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique();
                $table->string('source_type', 32);
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('plan_offering_id')->constrained('plan_offerings')->restrictOnDelete();

                $table->foreignId('trial_reservation_id')->nullable()->unique()->constrained('trial_reservations')->restrictOnDelete();
                $table->string('trial_reservation_command_key', 128)->nullable()->unique();
                $table->foreignId('benefit_entitlement_id')->nullable()->unique()->constrained('benefit_code_free_service_entitlements')->restrictOnDelete();
                $table->ulid('benefit_entitlement_public_id')->nullable()->unique();

                $table->string('authorization_key', 128)->unique();
                $table->char('request_payload_hash', 64);
                $table->json('configuration_snapshot');
                $table->char('configuration_snapshot_hash', 64);

                $table->string('actor_type', 16);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('reason_code', 64);
                $table->string('correlation_id', 64);
                $table->dateTime('created_at', 6);

                $table->index(['user_id', 'created_at'], 'order_source_auth_user_created_idx');
                $table->index(['source_type', 'created_at'], 'order_source_auth_type_created_idx');
            });
        } else {
            $this->assertTableFoundation();
        }

        $this->replaceConstraint('order_source_auth_type_chk', "CHECK (`source_type` IN ('trial','benefit_code','admin_grant'))");
        $this->replaceConstraint('order_source_auth_actor_chk', "CHECK (`actor_type` IN ('system','administrator') AND ((`source_type` = 'admin_grant' AND `actor_type` = 'administrator' AND `actor_id` IS NOT NULL) OR (`source_type` <> 'admin_grant' AND `actor_type` = 'system' AND `actor_id` IS NULL)))");
        $this->replaceConstraint('order_source_auth_upstream_shape_chk', "CHECK ((`source_type` = 'trial' AND `trial_reservation_id` IS NOT NULL AND `trial_reservation_command_key` IS NOT NULL AND `benefit_entitlement_id` IS NULL AND `benefit_entitlement_public_id` IS NULL) OR (`source_type` = 'benefit_code' AND `trial_reservation_id` IS NULL AND `trial_reservation_command_key` IS NULL AND `benefit_entitlement_id` IS NOT NULL AND `benefit_entitlement_public_id` IS NOT NULL) OR (`source_type` = 'admin_grant' AND `trial_reservation_id` IS NULL AND `trial_reservation_command_key` IS NULL AND `benefit_entitlement_id` IS NULL AND `benefit_entitlement_public_id` IS NULL))");
        $this->replaceConstraint('order_source_auth_hash_chk', "CHECK (`request_payload_hash` REGEXP '^[0-9a-f]{64}$' AND `configuration_snapshot_hash` REGEXP '^[0-9a-f]{64}$')");
        $this->replaceConstraint('order_source_auth_snapshot_chk', "CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 32 AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");
        $this->replaceConstraint('order_source_auth_reason_chk', 'CHECK (CHAR_LENGTH(TRIM(`reason_code`)) BETWEEN 3 AND 64 AND CHAR_LENGTH(TRIM(`correlation_id`)) BETWEEN 8 AND 64)');

        $this->createInsertGuard();
        $this->createImmutabilityGuards();
        $this->assertAuthorityReady();
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_source_authorizations')) {
            return;
        }
        if (DB::table('order_source_authorizations')->exists()) {
            throw new RuntimeException('Cannot roll back non-paid Order source authority while authorization records exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS order_source_authorizations_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS order_source_authorizations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS order_source_authorizations_insert_guard');
        Schema::dropIfExists('order_source_authorizations');
    }

    private function assertTableFoundation(): void
    {
        foreach ([
            ['id', 'bigint', false, null, true],
            ['public_id', 'char', false, 26, false],
            ['source_type', 'varchar', false, 32, false],
            ['user_id', 'bigint', false, null, true],
            ['plan_offering_id', 'bigint', false, null, true],
            ['trial_reservation_id', 'bigint', true, null, true],
            ['trial_reservation_command_key', 'varchar', true, 128, false],
            ['benefit_entitlement_id', 'bigint', true, null, true],
            ['benefit_entitlement_public_id', 'char', true, 26, false],
            ['authorization_key', 'varchar', false, 128, false],
            ['request_payload_hash', 'char', false, 64, false],
            ['configuration_snapshot_hash', 'char', false, 64, false],
            ['actor_type', 'varchar', false, 16, false],
            ['actor_id', 'bigint', true, null, true],
            ['reason_code', 'varchar', false, 64, false],
            ['correlation_id', 'varchar', false, 64, false],
            ['created_at', 'datetime', false, null, false],
        ] as [$column, $type, $nullable, $length, $unsigned]) {
            $this->assertColumnShape($column, $type, $nullable, $length, $unsigned);
        }
        if (! Schema::hasColumn('order_source_authorizations', 'configuration_snapshot')) {
            throw new RuntimeException('Existing Order source authorization table has an incomplete configuration snapshot column.');
        }

        foreach ([
            ['PRIMARY', ['id'], true],
            ['order_source_authorizations_public_id_unique', ['public_id'], true],
            ['order_source_authorizations_trial_reservation_id_unique', ['trial_reservation_id'], true],
            ['order_source_authorizations_trial_reservation_command_key_unique', ['trial_reservation_command_key'], true],
            ['order_source_authorizations_benefit_entitlement_id_unique', ['benefit_entitlement_id'], true],
            ['order_source_authorizations_benefit_entitlement_public_id_unique', ['benefit_entitlement_public_id'], true],
            ['order_source_authorizations_authorization_key_unique', ['authorization_key'], true],
            ['order_source_auth_user_created_idx', ['user_id', 'created_at'], false],
            ['order_source_auth_type_created_idx', ['source_type', 'created_at'], false],
        ] as [$index, $columns, $unique]) {
            $this->assertIndexShape($index, $columns, $unique);
        }

        foreach ([
            ['order_source_authorizations_user_id_foreign', 'user_id', 'users', 'id'],
            ['order_source_authorizations_plan_offering_id_foreign', 'plan_offering_id', 'plan_offerings', 'id'],
            ['order_source_authorizations_trial_reservation_id_foreign', 'trial_reservation_id', 'trial_reservations', 'id'],
            ['order_source_authorizations_benefit_entitlement_id_foreign', 'benefit_entitlement_id', 'benefit_code_free_service_entitlements', 'id'],
        ] as [$constraint, $column, $referencedTable, $referencedColumn]) {
            $this->assertForeignKeyShape($constraint, $column, $referencedTable, $referencedColumn);
        }
    }

    private function replaceConstraint(string $constraint, string $definition): void
    {
        if ($this->constraintExists($constraint)) {
            DB::statement("ALTER TABLE `order_source_authorizations` DROP CONSTRAINT `{$constraint}`");
        }

        DB::statement("ALTER TABLE `order_source_authorizations` ADD CONSTRAINT `{$constraint}` {$definition}");
    }

    private function assertAuthorityReady(): void
    {
        $this->assertTableFoundation();
        foreach ([
            'order_source_auth_type_chk',
            'order_source_auth_actor_chk',
            'order_source_auth_upstream_shape_chk',
            'order_source_auth_hash_chk',
            'order_source_auth_snapshot_chk',
            'order_source_auth_reason_chk',
        ] as $constraint) {
            if (! $this->constraintExists($constraint)) {
                throw new RuntimeException('Order source authorization constraints did not converge: '.$constraint);
            }
        }
        foreach ([
            ['order_source_authorizations_insert_guard', 'Unsupported non-paid Order authorization source.'],
            ['order_source_authorizations_update_guard', 'Order source authorizations are immutable.'],
            ['order_source_authorizations_delete_guard', 'Order source authorizations are non-deletable.'],
        ] as [$trigger, $needle]) {
            if (! $this->triggerContains($trigger, $needle)) {
                throw new RuntimeException('Order source authorization trigger authority did not converge: '.$trigger);
            }
        }
    }

    private function assertColumnShape(string $column, string $dataType, bool $nullable, ?int $length, bool $unsigned): void
    {
        $row = DB::selectOne(
            'SELECT DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, CHARACTER_MAXIMUM_LENGTH AS character_length FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['order_source_authorizations', $column],
        );
        if ($row === null
            || strtolower((string) $row->data_type) !== $dataType
            || ((string) $row->is_nullable === 'YES') !== $nullable
            || ($length !== null && (int) $row->character_length !== $length)
            || ($unsigned && ! str_contains(strtolower((string) $row->column_type), 'unsigned'))) {
            throw new RuntimeException('Existing Order source authorization table has incompatible column shape: '.$column);
        }
    }

    /** @param list<string> $columns */
    private function assertIndexShape(string $index, array $columns, bool $unique): void
    {
        /** @var list<object{column_name:string,non_unique:int|string}> $rows */
        $rows = DB::select(
            'SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            ['order_source_authorizations', $index],
        );
        $actual = array_map(static fn ($row): string => (string) $row->column_name, $rows);
        $isUnique = $rows !== [] && (int) $rows[0]->non_unique === 0;
        if ($actual !== $columns || $isUnique !== $unique) {
            throw new RuntimeException('Existing Order source authorization table has incompatible index shape: '.$index);
        }
    }

    private function assertForeignKeyShape(string $constraint, string $column, string $referencedTable, string $referencedColumn): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT k.COLUMN_NAME AS column_name,
       k.REFERENCED_TABLE_NAME AS referenced_table,
       k.REFERENCED_COLUMN_NAME AS referenced_column,
       r.DELETE_RULE AS delete_rule
FROM information_schema.KEY_COLUMN_USAGE k
INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r
    ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
   AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
   AND r.TABLE_NAME = k.TABLE_NAME
WHERE k.CONSTRAINT_SCHEMA = DATABASE()
  AND k.TABLE_NAME = 'order_source_authorizations'
  AND k.CONSTRAINT_NAME = ?
SQL, [$constraint]);
        if ($row === null
            || (string) $row->column_name !== $column
            || (string) $row->referenced_table !== $referencedTable
            || (string) $row->referenced_column !== $referencedColumn
            || (string) $row->delete_rule !== 'RESTRICT') {
            throw new RuntimeException('Existing Order source authorization table has incompatible foreign-key shape: '.$constraint);
        }
    }

    private function constraintExists(string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            ['order_source_authorizations', $constraint, 'CHECK'],
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

    private function createInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER order_source_authorizations_insert_guard
BEFORE INSERT ON order_source_authorizations
FOR EACH ROW
BEGIN
    DECLARE valid_upstream_count INT DEFAULT 0;
    DECLARE valid_subject_count INT DEFAULT 0;
    DECLARE administrator_is_owner INT DEFAULT 0;
    DECLARE grant_permission_id BIGINT DEFAULT NULL;
    DECLARE explicit_deny_count INT DEFAULT 0;
    DECLARE explicit_allow_count INT DEFAULT 0;
    DECLARE role_grant_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_subject_count
    FROM users user_row
    WHERE user_row.id = NEW.user_id
      AND user_row.account_status = 'active'
      AND user_row.account_type IN ('customer', 'agent');

    IF valid_subject_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order source authorization requires one active customer or agent subject.';
    END IF;

    IF LOWER(SHA2(NEW.configuration_snapshot, 256)) <> NEW.configuration_snapshot_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order source authorization snapshot hash is invalid.';
    END IF;

    IF NEW.source_type = 'trial' THEN
        SELECT COUNT(*) INTO valid_upstream_count
        FROM trial_reservations reservation_row
        WHERE reservation_row.id = NEW.trial_reservation_id
          AND reservation_row.command_key = NEW.trial_reservation_command_key
          AND reservation_row.user_id = NEW.user_id
          AND reservation_row.plan_offering_id = NEW.plan_offering_id
          AND reservation_row.state = 'committed'
          AND JSON_LENGTH(NEW.configuration_snapshot) = 10
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.data_bytes')) AS UNSIGNED) = reservation_row.data_bytes
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.delivery_template_key')) = reservation_row.delivery_template_key_snapshot
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.duration_days')) AS UNSIGNED) = reservation_row.duration_days
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.eligibility_snapshot_hash')) = LOWER(reservation_row.eligibility_snapshot_hash)
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.fallback_used')) = IF(reservation_row.fallback_used_snapshot = 1, 'true', 'false')
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.plan_offering_route_selection_id')) AS UNSIGNED) = reservation_row.plan_offering_route_selection_id
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.policy_configuration_hash')) = LOWER(reservation_row.policy_configuration_hash)
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.trial_policy_id')) AS UNSIGNED) = reservation_row.trial_policy_id
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.trial_policy_version')) AS UNSIGNED) = reservation_row.trial_policy_version
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.trial_reservation_version')) AS UNSIGNED) = reservation_row.version;

        IF valid_upstream_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial Order authorization requires one exact committed Trial reservation snapshot.';
        END IF;
    ELSEIF NEW.source_type = 'benefit_code' THEN
        SELECT COUNT(*) INTO valid_upstream_count
        FROM benefit_code_free_service_entitlements entitlement_row
        WHERE entitlement_row.id = NEW.benefit_entitlement_id
          AND entitlement_row.public_id = NEW.benefit_entitlement_public_id
          AND entitlement_row.user_id = NEW.user_id
          AND entitlement_row.plan_offering_id = NEW.plan_offering_id
          AND entitlement_row.configuration_hash = NEW.configuration_snapshot_hash
          AND entitlement_row.configuration_snapshot = NEW.configuration_snapshot;

        IF valid_upstream_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit-code Order authorization requires one exact free-service entitlement snapshot.';
        END IF;
    ELSEIF NEW.source_type = 'admin_grant' THEN
        SELECT COUNT(*), COALESCE(MAX(administrator_row.is_owner), 0)
          INTO valid_upstream_count, administrator_is_owner
        FROM administrators administrator_row
        WHERE administrator_row.id = NEW.actor_id
          AND administrator_row.status = 'active';

        IF valid_upstream_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Administrator grant requires one active administrator actor.';
        END IF;

        SELECT COUNT(*) INTO valid_upstream_count
        FROM plan_offerings offering_row
        WHERE offering_row.id = NEW.plan_offering_id
          AND offering_row.state = 'active'
          AND JSON_LENGTH(NEW.configuration_snapshot) = 10
          AND (
              (offering_row.data_allowance_bytes IS NULL AND JSON_EXTRACT(NEW.configuration_snapshot, '$.data_allowance_bytes') = 'null')
              OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.data_allowance_bytes')) AS UNSIGNED) = offering_row.data_allowance_bytes
          )
          AND (
              (offering_row.device_limit IS NULL AND JSON_EXTRACT(NEW.configuration_snapshot, '$.device_limit') = 'null')
              OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.device_limit')) AS UNSIGNED) = offering_row.device_limit
          )
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.duration_days')) AS UNSIGNED) = offering_row.duration_days
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.offering_code')) = offering_row.code
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.offering_version')) AS UNSIGNED) = offering_row.version
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.protocol_selection_mode')) = offering_row.protocol_selection_mode
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.sales_server_id')) AS UNSIGNED) = offering_row.sales_server_id
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.server_selection_mode')) = offering_row.server_selection_mode
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.service_mode_code')) = offering_row.service_mode_code
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.service_target_id')) AS UNSIGNED) = offering_row.panel_service_target_id;

        IF valid_upstream_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Administrator grant requires one exact active Plan Offering snapshot.';
        END IF;

        IF administrator_is_owner = 0 THEN
            SELECT MAX(permission_row.id) INTO grant_permission_id
            FROM permissions permission_row
            WHERE permission_row.code = 'services.grant_single';

            IF grant_permission_id IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Administrator grant permission is not registered.';
            END IF;

            SELECT COUNT(*) INTO explicit_deny_count
            FROM administrator_permission_overrides override_row
            WHERE override_row.administrator_id = NEW.actor_id
              AND override_row.permission_id = grant_permission_id
              AND override_row.effect = 'deny';

            IF explicit_deny_count > 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Administrator grant is denied by an explicit permission override.';
            END IF;

            SELECT COUNT(*) INTO explicit_allow_count
            FROM administrator_permission_overrides override_row
            WHERE override_row.administrator_id = NEW.actor_id
              AND override_row.permission_id = grant_permission_id
              AND override_row.effect = 'allow';

            SELECT COUNT(*) INTO role_grant_count
            FROM administrator_role_assignments assignment_row
            INNER JOIN roles role_row ON role_row.id = assignment_row.role_id
            INNER JOIN role_permissions role_permission_row ON role_permission_row.role_id = role_row.id
            WHERE assignment_row.administrator_id = NEW.actor_id
              AND assignment_row.revoked_at IS NULL
              AND role_row.is_active = 1
              AND role_permission_row.permission_id = grant_permission_id;

            IF explicit_allow_count = 0 AND role_grant_count = 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Administrator grant requires services.grant_single permission.';
            END IF;
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported non-paid Order authorization source.';
    END IF;
END
SQL);
    }

    private function createImmutabilityGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER order_source_authorizations_update_guard
BEFORE UPDATE ON order_source_authorizations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order source authorizations are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER order_source_authorizations_delete_guard
BEFORE DELETE ON order_source_authorizations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order source authorizations are non-deletable.';
END
SQL);
    }
};

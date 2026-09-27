<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureInteractionRateAuthorizationColumn();

        if (! Schema::hasTable('telegram_delivery_retry_directives')) {
            DB::statement(<<<'SQL'
CREATE TABLE telegram_delivery_retry_directives (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_public_id CHAR(26) NOT NULL,
    provider_attempt SMALLINT UNSIGNED NOT NULL,
    retry_after_seconds INT UNSIGNED NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    retry_not_before DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY telegram_delivery_retry_directives_operation_attempt_unique (operation_public_id, provider_attempt),
    KEY telegram_delivery_retry_directives_due_idx (retry_not_before),
    CONSTRAINT telegram_delivery_retry_directives_public_chk CHECK (
        operation_public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
        AND BINARY operation_public_id = BINARY UPPER(operation_public_id)
    ),
    CONSTRAINT telegram_delivery_retry_directives_attempt_chk CHECK (provider_attempt BETWEEN 1 AND 100),
    CONSTRAINT telegram_delivery_retry_directives_delay_chk CHECK (retry_after_seconds BETWEEN 1 AND 86400),
    CONSTRAINT telegram_delivery_retry_directives_due_chk CHECK (retry_not_before > observed_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
SQL);
        } elseif (! $this->tableMatchesExpected()) {
            throw new RuntimeException('Telegram retry directive table exists with an unexpected shape.');
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_delivery_retry_directives_insert_guard
BEFORE INSERT ON telegram_delivery_retry_directives
FOR EACH ROW
BEGIN
    DECLARE matching_operation_count INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1
        FROM telegram_delivery_authority_capability capability_row
        WHERE capability_row.id = 1
          AND capability_row.schema_version = 1
          AND capability_row.activated_at IS NOT NULL
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_telegram_delivery_capability, ''), 256)
    )
       OR COALESCE(@app_telegram_delivery_effect_authority, '') <> 'telegram_delivery_effect_v1'
       OR BINARY NEW.operation_public_id <> BINARY COALESCE(@app_telegram_delivery_effect_public_id, '')
       OR COALESCE(@app_telegram_delivery_effect_expected_version, 0) < 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram provider retry evidence insert authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO matching_operation_count
    FROM telegram_delivery_operations operation_row
    WHERE BINARY operation_row.public_id = BINARY NEW.operation_public_id
      AND operation_row.state = 'sending'
      AND operation_row.state_version = @app_telegram_delivery_effect_expected_version
      AND operation_row.provider_attempts = NEW.provider_attempt;

    IF matching_operation_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram provider retry evidence requires the exact sending operation attempt.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_delivery_retry_directives_update_guard
BEFORE UPDATE ON telegram_delivery_retry_directives
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram provider retry evidence is append-only.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_delivery_retry_directives_delete_guard
BEFORE DELETE ON telegram_delivery_retry_directives
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram provider retry evidence is non-deletable.';
END
SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('telegram_delivery_retry_directives')
            && DB::table('telegram_delivery_retry_directives')->exists()) {
            throw new RuntimeException('Telegram provider retry evidence exists; rollback is refused.');
        }
        if (Schema::hasColumn('processed_telegram_updates', 'interaction_rate_authorized_at')
            && DB::table('processed_telegram_updates')->whereNotNull('interaction_rate_authorized_at')->exists()) {
            throw new RuntimeException('Telegram interaction rate authorization evidence exists; rollback is refused.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_retry_directives_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_retry_directives_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_retry_directives_insert_guard');
        Schema::dropIfExists('telegram_delivery_retry_directives');

        if (Schema::hasColumn('processed_telegram_updates', 'interaction_rate_authorized_at')) {
            DB::statement('ALTER TABLE processed_telegram_updates DROP COLUMN interaction_rate_authorized_at');
        }
    }

    private function ensureInteractionRateAuthorizationColumn(): void
    {
        if (! Schema::hasColumn('processed_telegram_updates', 'interaction_rate_authorized_at')) {
            DB::statement(<<<'SQL'
ALTER TABLE processed_telegram_updates
    ADD COLUMN interaction_rate_authorized_at DATETIME(6) NULL AFTER request_ip_hash
SQL);

            return;
        }

        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Telegram interaction rate authorization requires MariaDB/MySQL.');
        }

        $column = $connection->table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', 'processed_telegram_updates')
            ->where('COLUMN_NAME', 'interaction_rate_authorized_at')
            ->first(['DATA_TYPE', 'DATETIME_PRECISION', 'IS_NULLABLE']);
        if ($column === null
            || strtolower((string) $column->DATA_TYPE) !== 'datetime'
            || (int) $column->DATETIME_PRECISION !== 6
            || (string) $column->IS_NULLABLE !== 'YES') {
            throw new RuntimeException('Telegram interaction rate authorization column exists with an unexpected shape.');
        }
    }

    private function tableMatchesExpected(): bool
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        $database = $connection->getDatabaseName();
        $table = $connection->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'telegram_delivery_retry_directives')
            ->first(['ENGINE', 'TABLE_COLLATION']);
        if ($table === null
            || strtoupper((string) $table->ENGINE) !== 'INNODB'
            || strtolower((string) $table->TABLE_COLLATION) !== 'utf8mb4_bin') {
            return false;
        }

        $columns = $connection->table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'telegram_delivery_retry_directives')
            ->orderBy('ORDINAL_POSITION')
            ->get(['COLUMN_NAME', 'DATA_TYPE', 'COLUMN_TYPE', 'IS_NULLABLE', 'EXTRA'])
            ->map(static fn (object $row): array => [
                (string) $row->COLUMN_NAME,
                strtolower((string) $row->DATA_TYPE),
                strtolower((string) $row->COLUMN_TYPE),
                (string) $row->IS_NULLABLE,
                strtolower((string) $row->EXTRA),
            ])
            ->all();

        if (count($columns) !== 7
            || $columns[0][0] !== 'id'
            || $columns[0][1] !== 'bigint'
            || ! str_contains($columns[0][2], 'unsigned')
            || $columns[0][3] !== 'NO'
            || $columns[0][4] !== 'auto_increment'
            || $columns[1] !== ['operation_public_id', 'char', 'char(26)', 'NO', '']
            || $columns[2][0] !== 'provider_attempt'
            || $columns[2][1] !== 'smallint'
            || ! str_contains($columns[2][2], 'unsigned')
            || $columns[2][3] !== 'NO'
            || $columns[3][0] !== 'retry_after_seconds'
            || $columns[3][1] !== 'int'
            || ! str_contains($columns[3][2], 'unsigned')
            || $columns[3][3] !== 'NO'
            || $columns[4] !== ['observed_at', 'datetime', 'datetime(6)', 'NO', '']
            || $columns[5] !== ['retry_not_before', 'datetime', 'datetime(6)', 'NO', '']
            || $columns[6] !== ['created_at', 'datetime', 'datetime(6)', 'NO', '']) {
            return false;
        }

        $indexes = $connection->table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'telegram_delivery_retry_directives')
            ->orderBy('INDEX_NAME')
            ->orderBy('SEQ_IN_INDEX')
            ->get(['INDEX_NAME', 'NON_UNIQUE', 'SEQ_IN_INDEX', 'COLUMN_NAME'])
            ->map(static fn (object $row): array => [
                (string) $row->INDEX_NAME,
                (int) $row->NON_UNIQUE,
                (int) $row->SEQ_IN_INDEX,
                (string) $row->COLUMN_NAME,
            ])
            ->all();

        if ($indexes !== [
            ['PRIMARY', 0, 1, 'id'],
            ['telegram_delivery_retry_directives_due_idx', 1, 1, 'retry_not_before'],
            ['telegram_delivery_retry_directives_operation_attempt_unique', 0, 1, 'operation_public_id'],
            ['telegram_delivery_retry_directives_operation_attempt_unique', 0, 2, 'provider_attempt'],
        ]) {
            return false;
        }

        $checks = $connection->table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'telegram_delivery_retry_directives')
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->orderBy('CONSTRAINT_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();

        return $checks === [
            'telegram_delivery_retry_directives_attempt_chk',
            'telegram_delivery_retry_directives_delay_chk',
            'telegram_delivery_retry_directives_due_chk',
            'telegram_delivery_retry_directives_public_chk',
        ];
    }
};

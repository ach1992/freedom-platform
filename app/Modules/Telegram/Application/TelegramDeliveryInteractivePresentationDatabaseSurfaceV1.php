<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Database\Connection;
use Throwable;

final readonly class TelegramDeliveryInteractivePresentationDatabaseSurfaceV1
{
    public const TABLE = 'telegram_delivery_interactive_presentations';

    public const INSERT_TRIGGER = 'telegram_delivery_interactive_presentations_insert_guard';

    public const UPDATE_TRIGGER = 'telegram_delivery_interactive_presentations_update_guard';

    public const DELETE_TRIGGER = 'telegram_delivery_interactive_presentations_delete_guard';

    /** @var list<list<string|null>> */
    private const EXPECTED_COLUMN_METADATA = [
        ['delivery_operation_public_id', 'char(26)', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
        ['keyboard_snapshot', 'longtext', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
        ['keyboard_snapshot_hash', 'char(64)', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
        ['created_at', 'datetime(6)', 'NO', null, null, null, ''],
    ];

    /** @var array<string, literal-string> */
    private const EXPECTED_CHECKS = [
        'telegram_delivery_interactive_public_chk' => <<<'SQL'
delivery_operation_public_id regexp '^[0-9A-HJKMNP-TV-Z]{26}$'
and cast(delivery_operation_public_id as char charset binary) = cast(ucase(delivery_operation_public_id) as char charset binary)
SQL,
        'telegram_delivery_interactive_snapshot_hash_chk' => <<<'SQL'
keyboard_snapshot_hash regexp '^[0-9a-f]{64}$'
SQL,
        'telegram_delivery_interactive_snapshot_json_chk' => <<<'SQL'
json_valid(keyboard_snapshot) = 1
and json_type(keyboard_snapshot) = 'OBJECT'
and octet_length(keyboard_snapshot) between 2 and 16384
SQL,
    ];

    /** @phpstan-impure */
    public function isReady(Connection $connection): bool
    {
        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        try {
            $databaseName = $connection->getDatabaseName();
            $table = $connection->table('information_schema.TABLES')
                ->where('TABLE_SCHEMA', $databaseName)
                ->where('TABLE_NAME', self::TABLE)
                ->first(['ENGINE', 'TABLE_COLLATION']);
            if ($table === null
                || strtoupper((string) $table->ENGINE) !== 'INNODB'
                || strtolower((string) $table->TABLE_COLLATION) !== 'utf8mb4_bin') {
                return false;
            }

            $columns = $connection->table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', $databaseName)
                ->where('TABLE_NAME', self::TABLE)
                ->orderBy('ORDINAL_POSITION')
                ->get([
                    'COLUMN_NAME',
                    'ORDINAL_POSITION',
                    'COLUMN_TYPE',
                    'IS_NULLABLE',
                    'COLUMN_DEFAULT',
                    'CHARACTER_SET_NAME',
                    'COLLATION_NAME',
                    'EXTRA',
                ]);
            if ($columns->count() !== count(self::EXPECTED_COLUMN_METADATA)) {
                return false;
            }
            foreach ($columns->values() as $index => $column) {
                $contract = self::EXPECTED_COLUMN_METADATA[$index];
                $characterSet = $column->CHARACTER_SET_NAME === null
                    ? null
                    : strtolower((string) $column->CHARACTER_SET_NAME);
                $collation = $column->COLLATION_NAME === null
                    ? null
                    : strtolower((string) $column->COLLATION_NAME);

                if ((string) $column->COLUMN_NAME !== $contract[0]
                    || (int) $column->ORDINAL_POSITION !== $index + 1
                    || strtolower((string) $column->COLUMN_TYPE) !== $contract[1]
                    || (string) $column->IS_NULLABLE !== $contract[2]
                    || $column->COLUMN_DEFAULT !== $contract[3]
                    || $characterSet !== $contract[4]
                    || $collation !== $contract[5]
                    || (string) $column->EXTRA !== $contract[6]) {
                    return false;
                }
            }

            $checks = $connection->table('information_schema.CHECK_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $databaseName)
                ->where('TABLE_NAME', self::TABLE)
                ->orderBy('CONSTRAINT_NAME')
                ->get(['CONSTRAINT_NAME', 'CHECK_CLAUSE']);
            if ($checks->count() !== count(self::EXPECTED_CHECKS)) {
                return false;
            }
            foreach ($checks as $check) {
                $name = (string) $check->CONSTRAINT_NAME;
                $expectedClause = self::EXPECTED_CHECKS[$name] ?? null;
                if ($expectedClause === null
                    || ! hash_equals(
                        $this->normalizeSql($expectedClause),
                        $this->normalizeSql((string) $check->CHECK_CLAUSE),
                    )) {
                    return false;
                }
            }

            if ($connection->table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $databaseName)
                ->where('TABLE_NAME', self::TABLE)
                ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
                ->exists()) {
                return false;
            }
            if ($connection->table('information_schema.KEY_COLUMN_USAGE')
                ->where('REFERENCED_TABLE_SCHEMA', $databaseName)
                ->where('REFERENCED_TABLE_NAME', self::TABLE)
                ->exists()) {
                return false;
            }

            $indexes = $connection->table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', $databaseName)
                ->where('TABLE_NAME', self::TABLE)
                ->orderBy('INDEX_NAME')
                ->orderBy('SEQ_IN_INDEX')
                ->get([
                    'INDEX_NAME',
                    'NON_UNIQUE',
                    'SEQ_IN_INDEX',
                    'COLUMN_NAME',
                    'SUB_PART',
                    'COLLATION',
                    'INDEX_TYPE',
                ]);
            if ($indexes->count() !== 1) {
                return false;
            }
            $primary = $indexes->first();
            if ($primary === null
                || (string) $primary->INDEX_NAME !== 'PRIMARY'
                || (int) $primary->NON_UNIQUE !== 0
                || (int) $primary->SEQ_IN_INDEX !== 1
                || (string) $primary->COLUMN_NAME !== 'delivery_operation_public_id'
                || $primary->SUB_PART !== null
                || (string) $primary->COLLATION !== 'A'
                || strtoupper((string) $primary->INDEX_TYPE) !== 'BTREE') {
                return false;
            }

            $triggerContext = $this->expectedTriggerExecutionContext($connection);
            if ($triggerContext === null) {
                return false;
            }

            $triggers = $connection->table('information_schema.TRIGGERS')
                ->where('TRIGGER_SCHEMA', $databaseName)
                ->where('EVENT_OBJECT_TABLE', self::TABLE)
                ->orderBy('TRIGGER_NAME')
                ->get([
                    'TRIGGER_NAME',
                    'EVENT_MANIPULATION',
                    'ACTION_TIMING',
                    'ACTION_STATEMENT',
                    'ACTION_ORDER',
                    'SQL_MODE',
                    'DEFINER',
                    'CHARACTER_SET_CLIENT',
                    'COLLATION_CONNECTION',
                    'DATABASE_COLLATION',
                ]);
            if ($triggers->count() !== 3) {
                return false;
            }

            $expected = [
                self::DELETE_TRIGGER => ['DELETE', 'BEFORE', self::deleteTriggerBody()],
                self::INSERT_TRIGGER => ['INSERT', 'BEFORE', self::insertTriggerBody()],
                self::UPDATE_TRIGGER => ['UPDATE', 'BEFORE', self::updateTriggerBody()],
            ];
            foreach ($triggers as $trigger) {
                $name = (string) $trigger->TRIGGER_NAME;
                $contract = $expected[$name] ?? null;
                if ($contract === null
                    || (string) $trigger->EVENT_MANIPULATION !== $contract[0]
                    || (string) $trigger->ACTION_TIMING !== $contract[1]
                    || (int) $trigger->ACTION_ORDER !== 1
                    || ! hash_equals(
                        $this->normalizeSql($contract[2]),
                        $this->normalizeSql((string) $trigger->ACTION_STATEMENT),
                    )
                    || $this->normalizeSqlMode($trigger->SQL_MODE ?? '') !== $triggerContext['sql_mode']
                    || (string) ($trigger->DEFINER ?? '') !== $triggerContext['definer']
                    || (string) ($trigger->CHARACTER_SET_CLIENT ?? '') !== $triggerContext['character_set_client']
                    || (string) ($trigger->COLLATION_CONNECTION ?? '') !== $triggerContext['collation_connection']
                    || (string) ($trigger->DATABASE_COLLATION ?? '') !== $triggerContext['database_collation']) {
                    return false;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** @return literal-string */
    public static function insertTriggerBody(): string
    {
        return <<<'SQL'
BEGIN
    DECLARE capability_fence_rows INT DEFAULT 0;
    DECLARE valid_operation_count INT DEFAULT 0;

    SELECT COUNT(*) INTO capability_fence_rows
    FROM telegram_delivery_authority_capability
    WHERE id = 1
    LOCK IN SHARE MODE;

    IF capability_fence_rows <> 1
       OR NOT EXISTS (
        SELECT 1
        FROM telegram_delivery_authority_capability capability_row
        WHERE capability_row.id = 1
          AND capability_row.schema_version = 1
          AND capability_row.activated_at IS NOT NULL
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_telegram_delivery_capability, ''), 256)
    )
       OR COALESCE(@app_telegram_delivery_interactive_authority, '') <> 'telegram_delivery_interactive_queue_v1'
       OR BINARY NEW.delivery_operation_public_id <> BINARY COALESCE(@app_telegram_delivery_interactive_public_id, '')
       OR BINARY NEW.keyboard_snapshot_hash <> BINARY COALESCE(@app_telegram_delivery_interactive_snapshot_hash, '')
       OR BINARY NEW.keyboard_snapshot_hash <> BINARY LOWER(SHA2(NEW.keyboard_snapshot, 256)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interactive presentation creation authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_operation_count
    FROM telegram_delivery_operations operation_row
    INNER JOIN outbox_messages outbox_row ON outbox_row.id = operation_row.outbox_event_id
    WHERE BINARY operation_row.public_id = BINARY NEW.delivery_operation_public_id
      AND operation_row.state = 'prepared'
      AND operation_row.state_version = 1
      AND operation_row.provider_attempts = 0
      AND BINARY outbox_row.event_type = BINARY 'telegram.delivery.requested'
      AND outbox_row.contract_version = 2
      AND BINARY outbox_row.aggregate_type = BINARY 'telegram_delivery_operation'
      AND BINARY outbox_row.aggregate_id = BINARY operation_row.public_id
      AND BINARY outbox_row.correlation_id = BINARY operation_row.correlation_id
      AND outbox_row.dispatch_state = 'authority_pending'
      AND outbox_row.processed_at IS NULL;

    IF valid_operation_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interactive presentation requires one prepared v2 delivery operation.';
    END IF;
END
SQL;
    }

    /** @return literal-string */
    public static function updateTriggerBody(): string
    {
        return <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interactive presentations are immutable.';
END
SQL;
    }

    /** @return literal-string */
    public static function deleteTriggerBody(): string
    {
        return <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interactive presentations are non-deletable.';
END
SQL;
    }

    /**
     * @return null|array{
     *     sql_mode:string,
     *     definer:string,
     *     character_set_client:string,
     *     collation_connection:string,
     *     database_collation:string
     * }
     */
    private function expectedTriggerExecutionContext(Connection $connection): ?array
    {
        $session = $connection->selectOne(<<<'SQL'
SELECT
    @@SESSION.sql_mode AS sql_mode,
    CURRENT_USER() AS definer,
    @@SESSION.character_set_client AS character_set_client,
    @@SESSION.collation_connection AS collation_connection
SQL, [], false);
        $schema = $connection->table('information_schema.SCHEMATA')
            ->where('SCHEMA_NAME', $connection->getDatabaseName())
            ->first(['DEFAULT_COLLATION_NAME']);

        if ($session === null || $schema === null) {
            return null;
        }

        $context = [
            'sql_mode' => $this->normalizeSqlMode($session->sql_mode ?? ''),
            'definer' => (string) ($session->definer ?? ''),
            'character_set_client' => (string) ($session->character_set_client ?? ''),
            'collation_connection' => (string) ($session->collation_connection ?? ''),
            'database_collation' => (string) ($schema->DEFAULT_COLLATION_NAME ?? ''),
        ];

        if ($context['definer'] === ''
            || $context['character_set_client'] === ''
            || $context['collation_connection'] === ''
            || $context['database_collation'] === '') {
            return null;
        }

        return $context;
    }

    private function normalizeSqlMode(mixed $value): string
    {
        $modes = array_values(array_filter(
            array_map(static fn (string $mode): string => trim($mode), explode(',', (string) $value)),
            static fn (string $mode): bool => $mode !== '',
        ));
        sort($modes, SORT_STRING);

        return implode(',', $modes);
    }

    private function normalizeSql(string $sql): string
    {
        $sql = str_replace(["\r\n", "\r", '`'], ["\n", "\n", ''], $sql);
        $normalized = '';
        $inString = false;
        $pendingSpace = false;
        $length = strlen($sql);

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            if ($inString) {
                $normalized .= $character;
                if ($character === "'") {
                    if ($index + 1 < $length && $sql[$index + 1] === "'") {
                        $normalized .= "'";
                        $index++;
                    } else {
                        $backslashes = 0;
                        for ($previous = $index - 1; $previous >= 0 && $sql[$previous] === '\\'; $previous--) {
                            $backslashes++;
                        }
                        if ($backslashes % 2 === 0) {
                            $inString = false;
                        }
                    }
                }

                continue;
            }

            if ($character === "'") {
                if ($pendingSpace && $normalized !== '' && ! str_ends_with($normalized, ' ')) {
                    $normalized .= ' ';
                }
                $pendingSpace = false;
                $normalized .= $character;
                $inString = true;

                continue;
            }

            if (ctype_space($character)) {
                $pendingSpace = true;

                continue;
            }
            if ($pendingSpace && $normalized !== '' && ! str_ends_with($normalized, ' ')) {
                $normalized .= ' ';
            }
            $pendingSpace = false;
            $normalized .= $character;
        }

        return trim($normalized);
    }
}

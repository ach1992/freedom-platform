<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Database\Connection;
use JsonException;
use Throwable;

/**
 * Immutable v1 schema contract shared by migration activation and runtime.
 *
 * The static semantic fingerprint covers the deployment-portable DDL surface
 * from MariaDB metadata. Trigger creation/execution context is attested
 * separately against the current trusted connection because account names and
 * session character settings are environment-specific but still affect trigger
 * execution/security semantics.
 *
 * The migration cannot activate unless both surfaces match, and runtime repeats
 * the same attestation before arming queue/effect authority. Any semantic DDL
 * change or trigger execution-context drift therefore fails closed.
 */
final readonly class TelegramDeliveryDatabaseAuthoritySurfaceV1
{
    private const EXPECTED_SEMANTIC_FINGERPRINT = '19e69a2ea8c1a1d4519d04e978acc06b384dd0ab2345eeb443fdc743bb884cb9';

    /** @var list<string> */
    public const REQUIRED_TRIGGERS = [
        'telegram_delivery_capability_insert_guard',
        'telegram_delivery_capability_update_guard',
        'telegram_delivery_capability_delete_guard',
        'outbox_telegram_delivery_envelope_insert_guard',
        'telegram_delivery_operations_insert_guard',
        'telegram_delivery_operations_update_guard',
        'telegram_delivery_operations_delete_guard',
        'outbox_telegram_delivery_envelope_update_guard',
        'outbox_telegram_delivery_envelope_delete_guard',
    ];

    /** @var list<string> */
    public const REQUIRED_CHECKS = [
        'telegram_delivery_capability_singleton_chk',
        'telegram_delivery_capability_hash_chk',
        'telegram_delivery_capability_schema_version_chk',
        'telegram_delivery_capability_activation_chk',
        'telegram_delivery_operations_public_chk',
        'telegram_delivery_operations_request_hash_chk',
        'telegram_delivery_operations_fingerprint_chk',
        'telegram_delivery_operations_correlation_chk',
        'telegram_delivery_operations_action_chk',
        'telegram_delivery_operations_bot_chk',
        'telegram_delivery_operations_recipient_chk',
        'telegram_delivery_operations_request_shape_chk',
        'telegram_delivery_operations_state_chk',
        'telegram_delivery_operations_state_version_chk',
        'telegram_delivery_operations_attempts_chk',
        'telegram_delivery_operations_result_shape_chk',
    ];

    /** @var list<string> */
    public const REQUIRED_UNIQUE_INDEXES = [
        'telegram_delivery_operations_public_unique',
        'telegram_delivery_operations_request_unique',
        'telegram_delivery_operations_outbox_unique',
    ];

    /** @var list<string> */
    private const AUTHORITY_TABLES = [
        'telegram_delivery_authority_capability',
        'telegram_delivery_operations',
    ];

    /**
     * Every trigger on the shared Outbox must be part of the immutable surface.
     * Foreign triggers execute in the same privileged session while queue
     * authority is armed, so attesting only Telegram-named guards is unsafe.
     *
     * @var list<string>
     */
    private const TRIGGER_SURFACE_TABLES = [
        'outbox_messages',
        'telegram_delivery_authority_capability',
        'telegram_delivery_operations',
    ];

    /** @var list<string> */
    private const CAPABILITY_COLUMNS = [
        'id',
        'capability_hash',
        'schema_version',
        'activated_at',
        'created_at',
    ];

    /** @var array<string, string> */
    private const OUTBOX_TERMINAL_GUARDS = [
        'INSERT' => 'outbox_telegram_delivery_envelope_insert_guard',
        'UPDATE' => 'outbox_telegram_delivery_envelope_update_guard',
        'DELETE' => 'outbox_telegram_delivery_envelope_delete_guard',
    ];

    public static function installationLockName(Connection $connection): string
    {
        return 'telegram-delivery-authority-v1:'.substr(hash('sha256', $connection->getDatabaseName()), 0, 32);
    }

    public function isReady(Connection $connection, string $expectedCapabilityHash): bool
    {
        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        try {
            if (! $this->semanticsMatchExpected($connection)) {
                return false;
            }

            $rows = $connection->table('telegram_delivery_authority_capability')->get([
                'id', 'capability_hash', 'schema_version', 'activated_at',
            ]);
        } catch (Throwable) {
            return false;
        }

        if ($rows->count() !== 1) {
            return false;
        }

        $capability = $rows->first();
        if ($capability === null
            || (int) $capability->id !== 1
            || ! is_string($capability->capability_hash)
            || ! hash_equals($expectedCapabilityHash, $capability->capability_hash)
            || (int) $capability->schema_version !== 1
            || $capability->activated_at === null) {
            return false;
        }

        return true;
    }

    public function semanticsMatchExpected(Connection $connection): bool
    {
        try {
            return hash_equals(self::EXPECTED_SEMANTIC_FINGERPRINT, $this->semanticFingerprint($connection))
                && $this->outboxGuardsAreTerminal($connection)
                && $this->triggerExecutionContextMatchesCurrentConnection($connection);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Fingerprint the complete deployment-portable v1 DDL surface from MariaDB
     * metadata. Trigger creation/execution context is checked separately because
     * its exact account and connection values are environment-specific.
     *
     * @throws JsonException
     */
    public function semanticFingerprint(Connection $connection): string
    {
        if ($connection->getDriverName() !== 'mysql') {
            throw new \RuntimeException('Telegram delivery semantic attestation requires MariaDB/MySQL metadata.');
        }

        $databaseName = $connection->getDatabaseName();

        $tables = $connection->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $databaseName)
            ->whereIn('TABLE_NAME', self::AUTHORITY_TABLES)
            ->orderBy('TABLE_NAME')
            ->get(['TABLE_NAME', 'ENGINE', 'TABLE_COLLATION'])
            ->map(static fn (object $row): array => [
                (string) $row->TABLE_NAME,
                (string) $row->ENGINE,
                (string) $row->TABLE_COLLATION,
            ])
            ->values()
            ->all();

        $columns = $connection->table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $databaseName)
            ->whereIn('TABLE_NAME', self::AUTHORITY_TABLES)
            ->orderBy('TABLE_NAME')
            ->orderBy('ORDINAL_POSITION')
            ->get([
                'TABLE_NAME',
                'COLUMN_NAME',
                'ORDINAL_POSITION',
                'COLUMN_TYPE',
                'IS_NULLABLE',
                'COLUMN_DEFAULT',
                'CHARACTER_SET_NAME',
                'COLLATION_NAME',
                'EXTRA',
            ])
            ->map(fn (object $row): array => [
                (string) $row->TABLE_NAME,
                (string) $row->COLUMN_NAME,
                (int) $row->ORDINAL_POSITION,
                $this->normalizeMetadataSql($row->COLUMN_TYPE),
                (string) $row->IS_NULLABLE,
                $row->COLUMN_DEFAULT === null ? null : $this->normalizeMetadataSql($row->COLUMN_DEFAULT),
                $row->CHARACTER_SET_NAME === null ? null : (string) $row->CHARACTER_SET_NAME,
                $row->COLLATION_NAME === null ? null : (string) $row->COLLATION_NAME,
                $this->normalizeMetadataSql($row->EXTRA),
            ])
            ->values()
            ->all();

        $checks = $connection->table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $databaseName)
            ->whereIn('TABLE_NAME', self::AUTHORITY_TABLES)
            ->orderBy('TABLE_NAME')
            ->orderBy('CONSTRAINT_NAME')
            ->get(['TABLE_NAME', 'CONSTRAINT_NAME', 'CHECK_CLAUSE'])
            ->map(fn (object $row): array => [
                (string) $row->TABLE_NAME,
                (string) $row->CONSTRAINT_NAME,
                $this->normalizeMetadataSql($row->CHECK_CLAUSE),
            ])
            ->values()
            ->all();

        $indexes = $connection->table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $databaseName)
            ->whereIn('TABLE_NAME', self::AUTHORITY_TABLES)
            ->orderBy('TABLE_NAME')
            ->orderBy('INDEX_NAME')
            ->orderBy('SEQ_IN_INDEX')
            ->get([
                'TABLE_NAME',
                'INDEX_NAME',
                'NON_UNIQUE',
                'SEQ_IN_INDEX',
                'COLUMN_NAME',
                'SUB_PART',
                'COLLATION',
                'INDEX_TYPE',
            ])
            ->map(static fn (object $row): array => [
                (string) $row->TABLE_NAME,
                (string) $row->INDEX_NAME,
                (int) $row->NON_UNIQUE,
                (int) $row->SEQ_IN_INDEX,
                $row->COLUMN_NAME === null ? null : (string) $row->COLUMN_NAME,
                $row->SUB_PART === null ? null : (int) $row->SUB_PART,
                $row->COLLATION === null ? null : (string) $row->COLLATION,
                (string) $row->INDEX_TYPE,
            ])
            ->values()
            ->all();

        $triggers = $connection->table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', $databaseName)
            ->whereIn('EVENT_OBJECT_TABLE', self::TRIGGER_SURFACE_TABLES)
            ->orderBy('TRIGGER_NAME')
            ->get([
                'TRIGGER_NAME',
                'EVENT_MANIPULATION',
                'EVENT_OBJECT_TABLE',
                'ACTION_TIMING',
                'ACTION_STATEMENT',
                'ACTION_ORDER',
            ])
            ->map(fn (object $row): array => [
                (string) $row->TRIGGER_NAME,
                (string) $row->EVENT_MANIPULATION,
                (string) $row->EVENT_OBJECT_TABLE,
                (string) $row->ACTION_TIMING,
                $this->normalizeMetadataSql($row->ACTION_STATEMENT),
                (int) $row->ACTION_ORDER,
            ])
            ->values()
            ->all();

        $contract = [
            'tables' => $tables,
            'columns' => $columns,
            'checks' => $checks,
            'indexes' => $indexes,
            'triggers' => $triggers,
        ];

        return hash('sha256', json_encode(
            $contract,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    public function capabilityTableHasCurrentShape(Connection $connection): bool
    {
        return $this->tableHasColumns($connection, 'telegram_delivery_authority_capability', self::CAPABILITY_COLUMNS);
    }

    /** @return list<string> */
    public function presentRequiredTriggers(Connection $connection): array
    {
        /** @var list<string> $triggers */
        $triggers = $connection->table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', $connection->getDatabaseName())
            ->whereIn('TRIGGER_NAME', self::REQUIRED_TRIGGERS)
            ->pluck('TRIGGER_NAME')
            ->map(static fn (mixed $name): string => (string) $name)
            ->values()
            ->all();

        return $triggers;
    }

    public function requiredTriggersPresent(Connection $connection): bool
    {
        return $this->sameNames(self::REQUIRED_TRIGGERS, $this->presentRequiredTriggers($connection));
    }

    public function requiredChecksPresent(Connection $connection): bool
    {
        /** @var list<string> $actual */
        $actual = $connection->table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $connection->getDatabaseName())
            ->whereIn('TABLE_NAME', self::AUTHORITY_TABLES)
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->whereIn('CONSTRAINT_NAME', self::REQUIRED_CHECKS)
            ->pluck('CONSTRAINT_NAME')
            ->map(static fn (mixed $name): string => (string) $name)
            ->values()
            ->all();

        return $this->sameNames(self::REQUIRED_CHECKS, $actual);
    }

    public function requiredUniqueIndexesPresent(Connection $connection): bool
    {
        /** @var list<string> $actual */
        $actual = $connection->table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', 'telegram_delivery_operations')
            ->where('NON_UNIQUE', 0)
            ->whereIn('INDEX_NAME', self::REQUIRED_UNIQUE_INDEXES)
            ->distinct()
            ->pluck('INDEX_NAME')
            ->map(static fn (mixed $name): string => (string) $name)
            ->values()
            ->all();

        return $this->sameNames(self::REQUIRED_UNIQUE_INDEXES, $actual);
    }

    /** @param list<string> $columns */
    private function tableHasColumns(Connection $connection, string $table, array $columns): bool
    {
        /** @var list<string> $actual */
        $actual = $connection->table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->whereIn('COLUMN_NAME', $columns)
            ->pluck('COLUMN_NAME')
            ->map(static fn (mixed $name): string => (string) $name)
            ->values()
            ->all();

        return $this->sameNames($columns, $actual);
    }

    private function outboxGuardsAreTerminal(Connection $connection): bool
    {
        $rows = $connection->table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', $connection->getDatabaseName())
            ->where('EVENT_OBJECT_TABLE', 'outbox_messages')
            ->where('ACTION_TIMING', 'BEFORE')
            ->whereIn('EVENT_MANIPULATION', array_keys(self::OUTBOX_TERMINAL_GUARDS))
            ->get(['TRIGGER_NAME', 'EVENT_MANIPULATION', 'ACTION_ORDER']);

        foreach (self::OUTBOX_TERMINAL_GUARDS as $event => $requiredTrigger) {
            $eventRows = $rows->filter(
                static fn (object $row): bool => (string) $row->EVENT_MANIPULATION === $event,
            );
            if ($eventRows->isEmpty()) {
                return false;
            }

            $required = $eventRows->first(
                static fn (object $row): bool => (string) $row->TRIGGER_NAME === $requiredTrigger,
            );
            if ($required === null) {
                return false;
            }

            if ((int) $required->ACTION_ORDER !== (int) $eventRows->max('ACTION_ORDER')) {
                return false;
            }
        }

        return true;
    }

    private function triggerExecutionContextMatchesCurrentConnection(Connection $connection): bool
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
            return false;
        }

        $expectedSqlMode = $this->normalizeSqlMode($session->sql_mode ?? '');
        $expectedDefiner = (string) ($session->definer ?? '');
        $expectedCharacterSet = (string) ($session->character_set_client ?? '');
        $expectedConnectionCollation = (string) ($session->collation_connection ?? '');
        $expectedDatabaseCollation = (string) ($schema->DEFAULT_COLLATION_NAME ?? '');

        if ($expectedDefiner === ''
            || $expectedCharacterSet === ''
            || $expectedConnectionCollation === ''
            || $expectedDatabaseCollation === '') {
            return false;
        }

        $triggers = $connection->table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', $connection->getDatabaseName())
            ->whereIn('EVENT_OBJECT_TABLE', self::TRIGGER_SURFACE_TABLES)
            ->get([
                'SQL_MODE',
                'DEFINER',
                'CHARACTER_SET_CLIENT',
                'COLLATION_CONNECTION',
                'DATABASE_COLLATION',
            ]);

        if ($triggers->isEmpty()) {
            return false;
        }

        foreach ($triggers as $trigger) {
            if ($this->normalizeSqlMode($trigger->SQL_MODE ?? '') !== $expectedSqlMode
                || (string) ($trigger->DEFINER ?? '') !== $expectedDefiner
                || (string) ($trigger->CHARACTER_SET_CLIENT ?? '') !== $expectedCharacterSet
                || (string) ($trigger->COLLATION_CONNECTION ?? '') !== $expectedConnectionCollation
                || (string) ($trigger->DATABASE_COLLATION ?? '') !== $expectedDatabaseCollation) {
                return false;
            }
        }

        return true;
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

    private function normalizeMetadataSql(mixed $value): string
    {
        $sql = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $normalized = '';
        $inString = false;
        $pendingSpace = false;
        $length = strlen($sql);

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];

            if ($inString) {
                $normalized .= $character;
                if ($character !== "'") {
                    continue;
                }

                if ($index + 1 < $length && $sql[$index + 1] === "'") {
                    $normalized .= "'";
                    $index++;

                    continue;
                }

                $backslashes = 0;
                for ($previous = $index - 1; $previous >= 0 && $sql[$previous] === '\\'; $previous--) {
                    $backslashes++;
                }
                if ($backslashes % 2 === 0) {
                    $inString = false;
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

            if ($character === '`') {
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

        if ($inString) {
            throw new \RuntimeException('Telegram delivery database metadata contains an unterminated SQL string literal.');
        }

        return trim($normalized);
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     */
    private function sameNames(array $expected, array $actual): bool
    {
        sort($expected);
        sort($actual);

        return $actual === $expected;
    }
}

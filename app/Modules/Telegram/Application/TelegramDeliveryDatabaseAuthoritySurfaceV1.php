<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Database\Connection;
use Throwable;

/**
 * Immutable v1 schema contract shared by the migration and runtime capability
 * fence. Future authority revisions must leave this v1 contract intact and
 * introduce a new versioned surface instead of mutating these identifiers.
 */
final readonly class TelegramDeliveryDatabaseAuthoritySurfaceV1
{
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
    private const CAPABILITY_COLUMNS = [
        'id',
        'capability_hash',
        'schema_version',
        'activated_at',
        'created_at',
    ];

    /** @var list<string> */
    private const OPERATION_COLUMNS = [
        'id',
        'public_id',
        'request_key_hash',
        'request_fingerprint',
        'correlation_id',
        'action',
        'bot_id',
        'recipient_chat_id',
        'target_message_id',
        'presentation_text',
        'outbox_event_id',
        'state',
        'state_version',
        'provider_attempts',
        'provider_boundary_started_at',
        'completed_at',
        'telegram_message_id',
        'result_code',
        'retry_after_seconds',
        'created_at',
        'updated_at',
    ];

    public static function installationLockName(Connection $connection): string
    {
        return 'telegram-delivery-authority-v1:'.substr(hash('sha256', $connection->getDatabaseName()), 0, 32);
    }

    public function isReady(Connection $connection, string $expectedCapabilityHash): bool
    {
        if ($connection->getDriverName() !== 'mysql'
            || ! $this->tableHasColumns($connection, 'telegram_delivery_authority_capability', self::CAPABILITY_COLUMNS)
            || ! $this->tableHasColumns($connection, 'telegram_delivery_operations', self::OPERATION_COLUMNS)) {
            return false;
        }

        try {
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

        return $this->requiredTriggersPresent($connection)
            && $this->requiredChecksPresent($connection)
            && $this->requiredUniqueIndexesPresent($connection);
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
            ->whereIn('TABLE_NAME', [
                'telegram_delivery_authority_capability',
                'telegram_delivery_operations',
            ])
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

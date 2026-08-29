<?php

declare(strict_types=1);

use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryForeignKeyMetadataAttestor;
use App\Modules\Telegram\Application\TelegramDeliveryLifecycleDatabaseAuthority;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROLLBACK_REFERENCE_FENCE_COMMENT = 'telegram-delivery-rollback-reference-fence-v1';

    /** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-004 QUA-007 QUA-010 */
    public function up(): void
    {
        if (! Schema::hasTable('outbox_messages')) {
            throw new RuntimeException('Telegram outbound delivery authority requires the common Transactional Outbox.');
        }

        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            if (! Schema::hasTable('telegram_delivery_operations')) {
                $this->createPortableTable();
            }

            return;
        }

        if (! (new TelegramDeliveryForeignKeyMetadataAttestor)->connectionBoundaryMatchesExpected($connection)) {
            throw new RuntimeException('Telegram delivery authority requires MariaDB >=10.11.9 and the exact dedicated metadata-attestation boundary before schema mutation.');
        }

        $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority;
        $lifecycleConnection = $lifecycleAuthority->requireConnection($connection);
        $lifecycleAuthority->principalUsername($lifecycleConnection);

        // GET_LOCK remains only migration-runner serialization. Lifecycle state
        // changes execute through a separate SELECT/UPDATE-only database principal, and
        // the capability trigger authenticates that principal independently.
        $this->withInstallationLock($lifecycleConnection, function () use ($connection, $lifecycleConnection): void {
            if ($this->authorityReady($connection)) {
                return;
            }

            if (Schema::hasTable('telegram_delivery_operations')
                && Schema::hasTable('telegram_delivery_authority_capability')
                && $this->rollbackFenceReady($connection)) {
                if ($this->durableAuthorityExists($connection)) {
                    throw new RuntimeException('Telegram delivery rollback-fenced authority cannot reactivate after durable rows appeared.');
                }

                $this->activateAuthority($connection, $lifecycleConnection);
                if (! $this->authorityReady($connection)) {
                    throw new RuntimeException('Telegram delivery rollback-fenced authority did not reactivate to the exact v1 surface.');
                }

                return;
            }

            $this->resetInterruptedInstallIfSafe($connection);

            // Keep the authority disabled until every table/guard/constraint is installed.
            // The capability row starts at schema_version=0 and every mutation trigger
            // requires schema_version=1, which is activated only as the final step.
            $this->createCapabilityTable($this->capabilityHash());
            $this->installCapabilityGuards();
            $this->createOutboxInsertGuard();
            $this->createTable();
            $this->createOperationInsertGuard();
            $this->createOperationUpdateGuard();
            $this->createOperationDeleteGuard();
            $this->createOutboxUpdateGuard();
            $this->createOutboxDeleteGuard();

            if ($this->durableAuthorityExists()) {
                throw new RuntimeException('Telegram delivery authority cannot activate after durable rows appeared during incomplete installation.');
            }

            $this->activateAuthority($connection, $lifecycleConnection);

            if (! $this->authorityReady($connection)) {
                throw new RuntimeException('Telegram delivery authority installation did not reach the complete activated surface.');
            }
        });
    }

    public function down(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            if ($this->durableAuthorityExists()) {
                throw new RuntimeException('Cannot roll back Telegram outbound delivery authority while durable authority exists.');
            }

            Schema::dropIfExists('telegram_delivery_operations');

            return;
        }

        $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority;
        $lifecycleConnection = $lifecycleAuthority->requireConnection($connection);
        $lifecycleAuthority->principalUsername($lifecycleConnection);

        $this->withInstallationLock($lifecycleConnection, function () use ($connection): void {
            $this->rollbackMysql($connection);
        });
    }

    /**
     * Keep every guard attached to a still-existing authority table until that
     * table itself has been removed. This makes a dependency-sensitive DROP the
     * first operation that can fail after final attestation; an independent DDL
     * race therefore cannot leave a surviving authority table unguarded.
     *
     * The optional callbacks are internal deterministic concurrency-test seams
     * invoked after each final dependency attestation and before the corresponding
     * dependency-sensitive DROP. Production down() never supplies them.
     *
     * @param  null|Closure():void  $afterFinalPreflight
     * @param  null|Closure():void  $afterCapabilityPreflight
     * @param  null|Closure():void  $beforeRuntimeFence
     */
    private function rollbackMysql(
        Connection $connection,
        ?Closure $afterFinalPreflight = null,
        ?Closure $afterCapabilityPreflight = null,
        ?Closure $beforeRuntimeFence = null,
    ): void {
        $lifecycleConnection = (new TelegramDeliveryLifecycleDatabaseAuthority)->requireConnection($connection);
        $hasOperationTable = Schema::hasTable('telegram_delivery_operations');
        $hasCapabilityTable = Schema::hasTable('telegram_delivery_authority_capability');

        if (! $hasOperationTable && ! $hasCapabilityTable) {
            if ($this->durableAuthorityExists($connection)) {
                throw new RuntimeException('Cannot finish Telegram outbound delivery rollback while orphaned durable authority exists.');
            }

            $this->dropOutboxGuards();

            return;
        }

        if ($hasOperationTable && ! $hasCapabilityTable) {
            throw new RuntimeException('Cannot roll back Telegram outbound delivery authority from an unrecognized incomplete authority surface.');
        }

        // The reference-fence DROP path depends on explicit InnoDB table locks.
        // Attest that prerequisite before lifecycle deactivation so an unsupported
        // session/topology leaves the active authority surface untouched.
        $this->assertReferenceFenceLockingPrerequisites($connection);

        if ($hasOperationTable) {
            if ($this->authorityReady($connection)) {
                if ($beforeRuntimeFence !== null) {
                    $beforeRuntimeFence();
                }

                $this->deactivateAuthorityForRollback($connection, $lifecycleConnection);
            }

            if (! $this->rollbackFenceReady($connection)
                && ! $this->operationReferenceFenceCanResume($connection)) {
                throw new RuntimeException('Cannot roll back Telegram outbound delivery authority unless the complete rollback-fenced authority surface is attested before destructive rollback.');
            }

            if ($this->durableAuthorityExists($connection)) {
                throw new RuntimeException('Cannot roll back Telegram outbound delivery authority while durable authority exists.');
            }

            $this->dropAuthorityTableWithReferenceFence(
                $connection,
                'telegram_delivery_operations',
                $afterFinalPreflight,
            );
        }

        if (! $this->rollbackCanResumeAfterOperationDrop($connection)) {
            throw new RuntimeException('Cannot resume Telegram outbound delivery rollback from an unattested partial rollback surface.');
        }

        $this->dropAuthorityTableWithReferenceFence(
            $connection,
            'telegram_delivery_authority_capability',
            $afterCapabilityPreflight,
        );

        // Only shared-table guards remain now. No dependency-sensitive authority
        // table DROP follows these statements, so partial trigger cleanup is both
        // fail-closed and idempotently resumable.
        $this->dropOutboxGuards();
    }

    private function rollbackCanResumeAfterOperationDrop(Connection $connection): bool
    {
        if (Schema::hasTable('telegram_delivery_operations')
            || ! Schema::hasTable('telegram_delivery_authority_capability')) {
            return false;
        }

        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        if (! $surface->capabilityTableHasCurrentShape($connection)) {
            return false;
        }

        $rows = DB::table('telegram_delivery_authority_capability')->get([
            'id', 'capability_hash', 'schema_version', 'activated_at',
        ]);
        if ($rows->count() !== 1) {
            return false;
        }

        $capability = $rows->first();
        if ($capability === null
            || (int) $capability->id !== 1
            || ! is_string($capability->capability_hash)
            || ! hash_equals($this->capabilityHash(), $capability->capability_hash)
            || (int) $capability->schema_version !== 0
            || $capability->activated_at !== null) {
            return false;
        }

        if ($this->durableAuthorityExists($connection)) {
            return false;
        }

        $presentTriggers = $surface->presentRequiredTriggers($connection);
        sort($presentTriggers, SORT_STRING);
        $expectedTriggers = [
            'outbox_telegram_delivery_envelope_insert_guard',
            'outbox_telegram_delivery_envelope_update_guard',
            'outbox_telegram_delivery_envelope_delete_guard',
            'telegram_delivery_capability_insert_guard',
            'telegram_delivery_capability_update_guard',
            'telegram_delivery_capability_delete_guard',
        ];
        sort($expectedTriggers, SORT_STRING);
        if ($presentTriggers !== $expectedTriggers) {
            return false;
        }

        return (new TelegramDeliveryForeignKeyMetadataAttestor)
            ->matchesExpected($connection, ['telegram_delivery_authority_capability']);
    }

    private function authorityReady(Connection $connection): bool
    {
        return (new TelegramDeliveryDatabaseAuthoritySurfaceV1)
            ->isReady($connection, $this->capabilityHash());
    }

    private function rollbackFenceReady(Connection $connection): bool
    {
        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        if (! $surface->semanticsMatchExpected($connection)) {
            return false;
        }

        $rows = $connection->table('telegram_delivery_authority_capability')->get([
            'id', 'capability_hash', 'schema_version', 'activated_at',
        ]);
        if ($rows->count() !== 1) {
            return false;
        }

        $capability = $rows->first();

        return $capability !== null
            && (int) $capability->id === 1
            && is_string($capability->capability_hash)
            && hash_equals($this->capabilityHash(), $capability->capability_hash)
            && (int) $capability->schema_version === 0
            && $capability->activated_at === null;
    }

    private function deactivateAuthorityForRollback(
        Connection $connection,
        Connection $lifecycleConnection,
    ): void {
        if (! $this->authorityReady($connection)) {
            throw new RuntimeException('Cannot establish Telegram delivery rollback fence from an unattested active authority surface.');
        }

        $lifecycleConnection->transaction(function () use ($lifecycleConnection): void {
            $capability = $lifecycleConnection->selectOne(<<<'SQL'
SELECT id, capability_hash, schema_version, activated_at
FROM telegram_delivery_authority_capability
WHERE id = 1
FOR UPDATE
SQL, [], false);

            if ($capability === null
                || (int) ($capability->id ?? 0) !== 1
                || ! is_string($capability->capability_hash ?? null)
                || ! hash_equals($this->capabilityHash(), $capability->capability_hash)
                || (int) ($capability->schema_version ?? -1) !== 1
                || ($capability->activated_at ?? null) === null) {
                throw new RuntimeException('Cannot establish Telegram delivery rollback fence because the active capability row changed.');
            }

            // The same dedicated lifecycle session owns the capability X-lock and
            // performs the terminal locking reads. Already-entered runtime shared
            // fences therefore drain before this point, and later producers block.
            if ($this->durableAuthorityExists($lifecycleConnection, true)) {
                throw new RuntimeException('Cannot roll back Telegram outbound delivery authority while durable authority exists.');
            }

            $armed = false;
            try {
                $lifecycleConnection->statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_lifecycle_authority = 'rollback'
SQL, [$this->capabilityValue()]);
                $armed = true;

                $updated = $lifecycleConnection->table('telegram_delivery_authority_capability')
                    ->where('id', 1)
                    ->where('schema_version', 1)
                    ->whereNotNull('activated_at')
                    ->update([
                        'schema_version' => 0,
                        'activated_at' => null,
                    ]);

                if ($updated !== 1) {
                    throw new RuntimeException('Telegram delivery rollback fence activation was rejected.');
                }
            } finally {
                if ($armed) {
                    $this->clearLifecycleAuthority($lifecycleConnection);
                }
            }
        }, 1);

        if (! $this->rollbackFenceReady($connection)) {
            throw new RuntimeException('Telegram delivery rollback fence did not reach the exact deactivated authority state.');
        }
    }

    private function resetInterruptedInstallIfSafe(Connection $connection): void
    {
        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        $hasOperationTable = Schema::hasTable('telegram_delivery_operations');
        $hasCapabilityTable = Schema::hasTable('telegram_delivery_authority_capability');
        $hasDeliveryTriggers = $surface->presentRequiredTriggers($connection) !== [];
        $hasDurableAuthority = $this->durableAuthorityExists();

        if (! $hasOperationTable && ! $hasCapabilityTable && ! $hasDeliveryTriggers && ! $hasDurableAuthority) {
            return;
        }

        if ($hasDurableAuthority) {
            throw new RuntimeException('Telegram delivery authority migration cannot repair an incomplete authority surface after durable rows exist.');
        }

        if ($hasCapabilityTable) {
            if (! $surface->capabilityTableHasCurrentShape($connection)) {
                $this->resetInactiveTablesPreservingGuards();

                return;
            }

            $rows = DB::table('telegram_delivery_authority_capability')->get([
                'id', 'capability_hash', 'schema_version', 'activated_at',
            ]);

            if (! $rows->isEmpty()) {
                if ($rows->count() !== 1
                    || (int) $rows->first()->id !== 1
                    || ! is_string($rows->first()->capability_hash)
                    || ! hash_equals($this->capabilityHash(), $rows->first()->capability_hash)
                ) {
                    throw new RuntimeException('Telegram delivery database capability does not match the application key or singleton authority.');
                }

                $schemaVersion = (int) $rows->first()->schema_version;
                $activatedAt = $rows->first()->activated_at;
                if ($schemaVersion === 1 || $activatedAt !== null) {
                    throw new RuntimeException('Telegram delivery activated authority surface is incomplete and cannot be silently repaired.');
                }

                if ($schemaVersion !== 0) {
                    throw new RuntimeException('Telegram delivery database capability activation state is malformed.');
                }
            }
        }

        $this->resetInactiveTablesPreservingGuards();
    }

    private function resetInactiveTablesPreservingGuards(): void
    {
        $connection = DB::connection();

        // Interrupted install/rollback cleanup uses the same reference-exclusion
        // barrier as normal down(). A raced incoming FK must fail the atomic index
        // strip before a surviving authority table loses any guard.
        if ($connection->getSchemaBuilder()->hasTable('telegram_delivery_operations')) {
            $this->dropAuthorityTableWithReferenceFence($connection, 'telegram_delivery_operations');
        }
        if ($connection->getSchemaBuilder()->hasTable('telegram_delivery_authority_capability')) {
            $this->dropAuthorityTableWithReferenceFence($connection, 'telegram_delivery_authority_capability');
        }
        $this->dropOutboxGuards();
    }

    private function operationReferenceFenceCanResume(Connection $connection): bool
    {
        if (! $connection->getSchemaBuilder()->hasTable('telegram_delivery_operations')
            || ! $connection->getSchemaBuilder()->hasTable('telegram_delivery_authority_capability')
            || ! $this->authorityTableReferenceFenceReady($connection, 'telegram_delivery_operations')
            || $this->durableAuthorityExists($connection)) {
            return false;
        }

        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        if (! $surface->capabilityTableHasCurrentShape($connection)
            || ! $surface->requiredChecksPresent($connection)) {
            return false;
        }

        $rows = $connection->table('telegram_delivery_authority_capability')->get([
            'id', 'capability_hash', 'schema_version', 'activated_at',
        ]);
        if ($rows->count() !== 1) {
            return false;
        }

        $capability = $rows->first();
        if ($capability === null
            || (int) $capability->id !== 1
            || ! is_string($capability->capability_hash)
            || ! hash_equals($this->capabilityHash(), $capability->capability_hash)
            || (int) $capability->schema_version !== 0
            || $capability->activated_at !== null) {
            return false;
        }

        $presentTriggers = $surface->presentRequiredTriggers($connection);
        sort($presentTriggers, SORT_STRING);
        $expectedTriggers = TelegramDeliveryDatabaseAuthoritySurfaceV1::REQUIRED_TRIGGERS;
        sort($expectedTriggers, SORT_STRING);
        if ($presentTriggers !== $expectedTriggers) {
            return false;
        }

        return (new TelegramDeliveryForeignKeyMetadataAttestor)
            ->matchesExpected($connection, [
                'telegram_delivery_operations',
                'telegram_delivery_authority_capability',
            ]);
    }

    /** @param null|Closure():void $afterFence */
    private function dropAuthorityTableWithReferenceFence(
        Connection $connection,
        string $table,
        ?Closure $afterFence = null,
    ): void {
        if (! in_array($table, [
            'telegram_delivery_operations',
            'telegram_delivery_authority_capability',
        ], true)) {
            throw new RuntimeException('Unsupported Telegram delivery rollback reference-fence table.');
        }
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Telegram delivery rollback reference fence requires no active runtime transaction.');
        }
        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return;
        }

        // Re-attest immediately before the exact session relies on LOCK TABLES.
        // innodb_table_locks is session-dynamic, so the earlier rollback preflight
        // is not sufficient as the sole proof for this destructive boundary.
        $this->assertReferenceFenceLockingPrerequisites($connection);

        $autocommit = $connection->selectOne('SELECT @@SESSION.autocommit AS autocommit', [], false);
        if ($autocommit === null) {
            throw new RuntimeException('Telegram delivery rollback reference fence could not read autocommit state.');
        }
        $restoreAutocommit = (int) ($autocommit->autocommit ?? -1) === 1;
        if (! $restoreAutocommit && (int) ($autocommit->autocommit ?? -1) !== 0) {
            throw new RuntimeException('Telegram delivery rollback reference fence found an invalid autocommit state.');
        }

        $lockSql = match ($table) {
            'telegram_delivery_operations' => 'LOCK TABLES `telegram_delivery_operations` WRITE',
            'telegram_delivery_authority_capability' => 'LOCK TABLES `telegram_delivery_authority_capability` WRITE',
        };
        $dropSql = match ($table) {
            'telegram_delivery_operations' => 'DROP TABLE `telegram_delivery_operations`',
            'telegram_delivery_authority_capability' => 'DROP TABLE `telegram_delivery_authority_capability`',
        };
        $locked = false;
        try {
            if ($restoreAutocommit) {
                $connection->statement('SET autocommit = 0');
            }
            $connection->statement($lockSql);
            $locked = true;

            $this->establishAuthorityTableReferenceFence($connection, $table);
            if (! $this->authorityTableReferenceFenceReady($connection, $table)
                || ! (new TelegramDeliveryForeignKeyMetadataAttestor)
                    ->matchesExpected($connection, [$table])) {
                throw new RuntimeException('Telegram delivery rollback reference fence did not exclude the complete foreign-key dependency surface.');
            }

            if ($afterFence !== null) {
                $afterFence();
            }

            // Re-attest after the adversarial window. The WRITE lock excludes
            // parent ALTER/CREATE INDEX while the no-index state makes a new
            // incoming FK structurally impossible, including with
            // FOREIGN_KEY_CHECKS=0 in the competing session.
            if (! $this->authorityTableReferenceFenceReady($connection, $table)
                || ! (new TelegramDeliveryForeignKeyMetadataAttestor)
                    ->matchesExpected($connection, [$table])) {
                throw new RuntimeException('Telegram delivery rollback reference fence changed before destructive DDL.');
            }

            $connection->statement($dropSql);
        } finally {
            if ($locked) {
                try {
                    $connection->statement('UNLOCK TABLES');
                } catch (Throwable $exception) {
                    $this->disconnect($connection);
                    throw new RuntimeException('Telegram delivery rollback reference-fence lock cleanup failed.', 0, $exception);
                }
            }

            if ($restoreAutocommit) {
                try {
                    $connection->statement('SET autocommit = 1');
                } catch (Throwable $exception) {
                    $this->disconnect($connection);
                    throw new RuntimeException('Telegram delivery rollback reference-fence autocommit restoration failed.', 0, $exception);
                }
            }
        }
    }

    private function assertReferenceFenceLockingPrerequisites(Connection $connection): void
    {
        $locking = $connection->selectOne(
            'SELECT @@SESSION.innodb_table_locks AS innodb_table_locks',
            [],
            false,
        );
        if ($locking === null || (int) ($locking->innodb_table_locks ?? -1) !== 1) {
            throw new RuntimeException(
                'Telegram delivery rollback reference fence requires @@SESSION.innodb_table_locks = 1.',
            );
        }

        $wsrepRows = $connection->select("SHOW GLOBAL VARIABLES LIKE 'wsrep_on'", [], false);
        if (count($wsrepRows) > 1) {
            throw new RuntimeException('Telegram delivery rollback reference fence found ambiguous Galera/wsrep state.');
        }
        if ($wsrepRows !== []) {
            $wsrepOn = strtoupper(trim((string) ($wsrepRows[0]->Value ?? '')));
            if (! in_array($wsrepOn, ['OFF', '0'], true)) {
                if (! in_array($wsrepOn, ['ON', '1'], true)) {
                    throw new RuntimeException('Telegram delivery rollback reference fence found an invalid Galera/wsrep state.');
                }

                throw new RuntimeException(
                    'Telegram delivery rollback reference fence is not supported while Galera/wsrep is enabled.',
                );
            }
        }
    }

    private function establishAuthorityTableReferenceFence(Connection $connection, string $table): void
    {
        if ($this->authorityTableReferenceFenceReady($connection, $table)) {
            return;
        }

        $quotedTable = '`'.str_replace('`', '``', $table).'`';
        $columnRows = $connection->select('SHOW COLUMNS FROM '.$quotedTable, [], false);
        $autoIncrementColumns = [];
        foreach ($columnRows as $row) {
            $field = (string) ($row->Field ?? '');
            $extra = strtolower((string) ($row->Extra ?? ''));
            if (str_contains($extra, 'auto_increment')) {
                $autoIncrementColumns[] = $field;
            }
        }

        if ($table === 'telegram_delivery_operations') {
            if ($autoIncrementColumns !== ['id']) {
                throw new RuntimeException('Telegram delivery rollback reference fence found an unexpected AUTO_INCREMENT surface.');
            }
            $indexRows = $connection->select('SHOW INDEX FROM `telegram_delivery_operations`', [], false);
            $expectedIndexes = [
                'PRIMARY',
                'telegram_delivery_operations_outbox_unique',
                'telegram_delivery_operations_public_unique',
                'telegram_delivery_operations_request_unique',
                'telegram_delivery_operations_state_idx',
            ];
            $alterSql = <<<'SQL'
ALTER TABLE `telegram_delivery_operations`
    MODIFY `id` BIGINT UNSIGNED NOT NULL,
    DROP PRIMARY KEY,
    DROP INDEX `telegram_delivery_operations_outbox_unique`,
    DROP INDEX `telegram_delivery_operations_public_unique`,
    DROP INDEX `telegram_delivery_operations_request_unique`,
    DROP INDEX `telegram_delivery_operations_state_idx`,
    COMMENT='telegram-delivery-rollback-reference-fence-v1'
SQL;
        } else {
            if ($autoIncrementColumns !== []) {
                throw new RuntimeException('Telegram delivery rollback reference fence found an unexpected AUTO_INCREMENT surface.');
            }
            $indexRows = $connection->select('SHOW INDEX FROM `telegram_delivery_authority_capability`', [], false);
            $expectedIndexes = ['PRIMARY'];
            $alterSql = <<<'SQL'
ALTER TABLE `telegram_delivery_authority_capability`
    DROP PRIMARY KEY,
    COMMENT='telegram-delivery-rollback-reference-fence-v1'
SQL;
        }

        $indexNames = [];
        foreach ($indexRows as $row) {
            $name = (string) ($row->Key_name ?? '');
            if ($name === '') {
                throw new RuntimeException('Telegram delivery rollback reference fence found an unnamed index.');
            }
            $indexNames[$name] = true;
        }
        $actualIndexes = array_keys($indexNames);
        sort($actualIndexes, SORT_STRING);
        sort($expectedIndexes, SORT_STRING);
        if ($actualIndexes !== $expectedIndexes) {
            throw new RuntimeException('Telegram delivery rollback reference fence found an unexpected index surface.');
        }

        $connection->statement($alterSql);
    }

    private function authorityTableReferenceFenceReady(Connection $connection, string $table): bool
    {
        $quotedTable = '`'.str_replace('`', '``', $table).'`';
        try {
            $indexes = $connection->select('SHOW INDEX FROM '.$quotedTable, [], false);
            if ($indexes !== []) {
                return false;
            }

            $columns = $connection->select('SHOW COLUMNS FROM '.$quotedTable, [], false);
            foreach ($columns as $column) {
                if (str_contains(strtolower((string) ($column->Extra ?? '')), 'auto_increment')) {
                    return false;
                }
            }

            $status = $connection->selectOne(<<<'SQL'
SELECT TABLE_COMMENT AS table_comment
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
SQL, [$connection->getDatabaseName(), $table], false);
            if ($status === null
                || (string) ($status->table_comment ?? '') !== self::ROLLBACK_REFERENCE_FENCE_COMMENT) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function durableAuthorityExists(?Connection $connection = null, bool $locking = false): bool
    {
        $connection ??= DB::connection();
        $schema = $connection->getSchemaBuilder();

        if ($locking && $connection->transactionLevel() < 1) {
            throw new RuntimeException('Telegram delivery durable-authority locking read requires a transaction.');
        }

        if ($schema->hasTable('telegram_delivery_operations')) {
            $operationQuery = $connection->table('telegram_delivery_operations')
                ->select('id')
                ->limit(1);
            if ($locking) {
                $operationQuery->lockForUpdate();
            }
            if ($operationQuery->first() !== null) {
                return true;
            }
        }

        $outboxQuery = $connection->table('outbox_messages')
            ->select('id')
            ->whereRaw('LOWER(event_type) = ?', ['telegram.delivery.requested'])
            ->limit(1);
        if ($locking) {
            $outboxQuery->lockForUpdate();
        }

        return $outboxQuery->first() !== null;
    }

    /**
     * @template T
     *
     * @param  Closure():T  $operation
     * @return T
     */
    private function withInstallationLock(Connection $connection, Closure $operation): mixed
    {
        $lockName = TelegramDeliveryDatabaseAuthoritySurfaceV1::installationLockName($connection);
        $acquired = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName], false);
        if ($acquired === null || (int) ($acquired->acquired ?? 0) !== 1) {
            throw new RuntimeException('Telegram delivery authority installation lock is already held by another migration runner.');
        }

        // The advisory lock is owned by this exact MariaDB session. Laravel's
        // normal lost-connection handling reconnects and retries queries, which
        // would silently continue this DDL state machine on a new session after
        // the server had already released GET_LOCK. Disable that behavior until
        // RELEASE_LOCK completes so any session loss aborts the installation.
        $connection->setReconnector(static function (Connection $connection): never {
            throw new RuntimeException('Telegram delivery authority installation database session was lost while the installation lock was held.');
        });

        try {
            try {
                return $operation();
            } finally {
                try {
                    $released = $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName], false);
                } catch (Throwable $exception) {
                    $this->disconnect($connection);
                    throw new RuntimeException('Telegram delivery authority installation lock cleanup failed.', 0, $exception);
                }

                if ($released === null || (int) ($released->released ?? 0) !== 1) {
                    $this->disconnect($connection);
                    throw new RuntimeException('Telegram delivery authority installation lock cleanup failed.');
                }
            }
        } finally {
            $this->restoreDefaultReconnector($connection);
        }
    }

    private function restoreDefaultReconnector(Connection $connection): void
    {
        $database = app(DatabaseManager::class);

        // Mirror Laravel DatabaseManager's default per-connection reconnector.
        // Restore it only after the advisory-lock critical section is over.
        $connection->setReconnector(static function (Connection $connection) use ($database): void {
            $name = $connection->getNameWithReadWriteType();
            if (! is_string($name) || $name === '') {
                throw new RuntimeException('Telegram delivery database connection name is unavailable for reconnect.');
            }

            $reconnected = $database->reconnect($name);
            if (! $reconnected instanceof Connection) {
                throw new RuntimeException('Telegram delivery database connection could not be restored.');
            }

            $connection->setPdo($reconnected->getRawPdo());
        });
    }

    private function installCapabilityGuards(): void
    {
        DB::unprepared("CREATE OR REPLACE TRIGGER telegram_delivery_capability_insert_guard BEFORE INSERT ON telegram_delivery_authority_capability FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery database capability is immutable.'; END");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_delivery_capability_update_guard
BEFORE UPDATE ON telegram_delivery_authority_capability
FOR EACH ROW
BEGIN
    IF OLD.id <> 1
       OR NEW.id <> OLD.id
       OR BINARY NEW.capability_hash <> BINARY OLD.capability_hash
       OR NOT (NEW.created_at <=> OLD.created_at)
       OR BINARY OLD.capability_hash <> BINARY SHA2(COALESCE(@app_telegram_delivery_capability, ''), 256) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery database capability identity is immutable.';
    END IF;

    IF COALESCE(SUBSTRING_INDEX(USER(), '@', 1), '') <> 'telegram_lifecycle'
       OR COALESCE(IS_USED_LOCK(CONCAT(
            'telegram-delivery-authority-v1:',
            LEFT(SHA2(DATABASE(), 256), 32)
       )), 0) <> CONNECTION_ID() THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery database lifecycle principal is invalid.';
    END IF;

    IF OLD.schema_version = 0 AND OLD.activated_at IS NULL THEN
        IF NEW.schema_version <> 1
           OR NEW.activated_at IS NULL
           OR COALESCE(@app_telegram_delivery_lifecycle_authority, '') <> 'activate' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery database capability activation is invalid.';
        END IF;
    ELSEIF OLD.schema_version = 1 AND OLD.activated_at IS NOT NULL THEN
        IF NEW.schema_version <> 0
           OR NEW.activated_at IS NOT NULL
           OR COALESCE(@app_telegram_delivery_lifecycle_authority, '') <> 'rollback' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery database capability rollback fence transition is invalid.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery database capability activation state is malformed.';
    END IF;
END
SQL);
        DB::unprepared("CREATE OR REPLACE TRIGGER telegram_delivery_capability_delete_guard BEFORE DELETE ON telegram_delivery_authority_capability FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery database capability is immutable.'; END");
    }

    private function activateAuthority(Connection $connection, Connection $lifecycleConnection): void
    {
        $armed = false;

        DB::statement('ALTER TABLE telegram_delivery_authority_capability DROP CONSTRAINT telegram_delivery_capability_schema_version_chk');
        DB::statement('ALTER TABLE telegram_delivery_authority_capability ADD CONSTRAINT telegram_delivery_capability_schema_version_chk CHECK (`schema_version` IN (0, 1))');

        if (! (new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected($connection)) {
            throw new RuntimeException('Telegram delivery authority schema semantics do not match the immutable v1 contract.');
        }

        try {
            $lifecycleConnection->statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_lifecycle_authority = 'activate'
SQL, [$this->capabilityValue()]);
            $armed = true;
            $updated = $lifecycleConnection->table('telegram_delivery_authority_capability')
                ->where('id', 1)
                ->where('schema_version', 0)
                ->whereNull('activated_at')
                ->update([
                    'schema_version' => 1,
                    'activated_at' => now('UTC'),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Telegram delivery authority final schema activation was rejected.');
            }
        } finally {
            if ($armed) {
                $this->clearLifecycleAuthority($lifecycleConnection);
            }
        }
    }

    private function clearLifecycleAuthority(Connection $connection): void
    {
        try {
            $connection->statement(<<<'SQL'
SET @app_telegram_delivery_lifecycle_authority = NULL,
    @app_telegram_delivery_capability = NULL
SQL);
        } catch (Throwable $exception) {
            $this->disconnect($connection);
            throw $exception;
        }
    }

    private function dropOutboxGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_envelope_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_envelope_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_envelope_insert_guard');
    }

    private function disconnect(Connection $connection): void
    {
        try {
            $connection->disconnect();
        } catch (Throwable) {
            $connection->setPdo(null);
            $connection->setReadPdo(null);
            $connection->setDirectPdo(null);
        }
    }

    private function createCapabilityTable(string $expectedHash): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE telegram_delivery_authority_capability (
  `id` TINYINT UNSIGNED NOT NULL,
  `capability_hash` CHAR(64) NOT NULL,
  `schema_version` TINYINT UNSIGNED NOT NULL,
  `activated_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `telegram_delivery_capability_singleton_chk` CHECK (`id` = 1),
  CONSTRAINT `telegram_delivery_capability_hash_chk` CHECK (`capability_hash` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `telegram_delivery_capability_schema_version_chk` CHECK (`schema_version` = 0),
  CONSTRAINT `telegram_delivery_capability_activation_chk` CHECK ((`schema_version` = 0 AND `activated_at` IS NULL) OR (`schema_version` = 1 AND `activated_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        DB::table('telegram_delivery_authority_capability')->insert([
            'id' => 1,
            'capability_hash' => $expectedHash,
            'schema_version' => 0,
            'activated_at' => null,
            'created_at' => now('UTC'),
        ]);
    }

    private function createPortableTable(): void
    {
        Schema::create('telegram_delivery_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->char('request_key_hash', 64)->unique();
            $table->char('request_fingerprint', 64);
            $table->string('correlation_id', 64);
            $table->string('action', 16);
            $table->string('bot_id', 20);
            $table->bigInteger('recipient_chat_id');
            $table->unsignedBigInteger('target_message_id')->nullable();
            $table->text('presentation_text')->nullable();
            $table->uuid('outbox_event_id')->unique();
            $table->string('state', 32);
            $table->unsignedInteger('state_version');
            $table->unsignedSmallInteger('provider_attempts')->default(0);
            $table->dateTime('provider_boundary_started_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('result_code', 64)->nullable();
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['state', 'updated_at'], 'telegram_delivery_operations_state_idx');
        });
    }

    private function createTable(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE telegram_delivery_operations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    request_key_hash CHAR(64) NOT NULL,
    request_fingerprint CHAR(64) NOT NULL,
    correlation_id VARCHAR(64) NOT NULL,
    action VARCHAR(16) NOT NULL,
    bot_id VARCHAR(20) NOT NULL,
    recipient_chat_id BIGINT NOT NULL,
    target_message_id BIGINT UNSIGNED NULL,
    presentation_text TEXT NULL,
    outbox_event_id CHAR(36) NOT NULL,
    state VARCHAR(32) NOT NULL,
    state_version INT UNSIGNED NOT NULL,
    provider_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    provider_boundary_started_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    telegram_message_id BIGINT UNSIGNED NULL,
    result_code VARCHAR(64) NULL,
    retry_after_seconds INT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY telegram_delivery_operations_public_unique (public_id),
    UNIQUE KEY telegram_delivery_operations_request_unique (request_key_hash),
    UNIQUE KEY telegram_delivery_operations_outbox_unique (outbox_event_id),
    KEY telegram_delivery_operations_state_idx (state, updated_at),
    CONSTRAINT telegram_delivery_operations_public_chk CHECK (
        public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$' AND BINARY public_id = BINARY UPPER(public_id)
    ),
    CONSTRAINT telegram_delivery_operations_request_hash_chk CHECK (request_key_hash REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT telegram_delivery_operations_fingerprint_chk CHECK (request_fingerprint REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT telegram_delivery_operations_correlation_chk CHECK (correlation_id REGEXP '^[A-Za-z0-9_.:-]{8,64}$'),
    CONSTRAINT telegram_delivery_operations_action_chk CHECK (action IN ('send','edit','delete')),
    CONSTRAINT telegram_delivery_operations_bot_chk CHECK (bot_id REGEXP '^[1-9][0-9]{5,19}$'),
    CONSTRAINT telegram_delivery_operations_recipient_chk CHECK (recipient_chat_id <> 0),
    CONSTRAINT telegram_delivery_operations_request_shape_chk CHECK (
        (action = 'send' AND target_message_id IS NULL AND presentation_text IS NOT NULL AND CHAR_LENGTH(presentation_text) BETWEEN 1 AND 4096)
        OR (action = 'edit' AND target_message_id IS NOT NULL AND target_message_id > 0 AND presentation_text IS NOT NULL AND CHAR_LENGTH(presentation_text) BETWEEN 1 AND 4096)
        OR (action = 'delete' AND target_message_id IS NOT NULL AND target_message_id > 0 AND presentation_text IS NULL)
    ),
    CONSTRAINT telegram_delivery_operations_state_chk CHECK (
        state IN ('prepared','sending','retryable','succeeded','failed_final','uncertain','review_required')
    ),
    CONSTRAINT telegram_delivery_operations_state_version_chk CHECK (state_version >= 1),
    CONSTRAINT telegram_delivery_operations_attempts_chk CHECK (provider_attempts <= 100),
    CONSTRAINT telegram_delivery_operations_result_shape_chk CHECK (
        (state = 'prepared'
            AND provider_attempts = 0
            AND provider_boundary_started_at IS NULL
            AND completed_at IS NULL
            AND telegram_message_id IS NULL
            AND result_code IS NULL
            AND retry_after_seconds IS NULL)
        OR (state = 'sending'
            AND provider_attempts >= 1
            AND provider_boundary_started_at IS NOT NULL
            AND completed_at IS NULL
            AND telegram_message_id IS NULL
            AND result_code IS NULL
            AND retry_after_seconds IS NULL)
        OR (state = 'retryable'
            AND provider_attempts >= 1
            AND provider_boundary_started_at IS NOT NULL
            AND completed_at IS NULL
            AND telegram_message_id IS NULL
            AND result_code IS NOT NULL
            AND retry_after_seconds IS NULL)
        OR (state = 'succeeded'
            AND provider_attempts >= 1
            AND provider_boundary_started_at IS NOT NULL
            AND completed_at IS NOT NULL
            AND result_code IS NOT NULL
            AND retry_after_seconds IS NULL
            AND ((action = 'delete' AND telegram_message_id IS NULL)
                 OR (action IN ('send','edit') AND telegram_message_id IS NOT NULL AND telegram_message_id > 0)))
        OR (state IN ('failed_final','uncertain')
            AND provider_attempts >= 1
            AND provider_boundary_started_at IS NOT NULL
            AND completed_at IS NOT NULL
            AND telegram_message_id IS NULL
            AND result_code IS NOT NULL
            AND retry_after_seconds IS NULL)
        OR (state = 'review_required'
            AND provider_attempts >= 1
            AND provider_boundary_started_at IS NOT NULL
            AND completed_at IS NOT NULL
            AND telegram_message_id IS NULL
            AND result_code IS NOT NULL
            AND retry_after_seconds BETWEEN 1 AND 86400)
    )
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
SQL);
    }

    private function createOutboxInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_telegram_delivery_envelope_insert_guard
BEFORE INSERT ON outbox_messages
FOR EACH ROW
BEGIN
    DECLARE capability_fence_rows INT DEFAULT 0;

    IF LOWER(NEW.event_type) = 'telegram.delivery.requested' THEN
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
           OR COALESCE(@app_telegram_delivery_authority, '') <> 'telegram_delivery_queue_v1'
           OR BINARY NEW.id <> BINARY COALESCE(@app_telegram_delivery_outbox_event_id, '')
           OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_telegram_delivery_correlation_id, '')
           OR HEX(NEW.event_type) <> HEX('telegram.delivery.requested')
           OR COALESCE(JSON_TYPE(NEW.payload), '') <> 'OBJECT'
           OR COALESCE(JSON_LENGTH(NEW.payload), -1) <> 1
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.payload, '$.telegram_delivery_operation_public_id')), '') <> 'STRING'
           OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.telegram_delivery_operation_public_id')), '') NOT REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
           OR HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.telegram_delivery_operation_public_id')), ''))
                <> HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.telegram_delivery_operation_public_id')), '')))
           OR HEX(CAST(NEW.payload AS CHAR)) <> HEX(CONCAT(
                '{"telegram_delivery_operation_public_id":"',
                JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.telegram_delivery_operation_public_id')),
                '"}'
           ))
           OR HEX(NEW.payload_hash) <> HEX(LOWER(SHA2(CAST(NEW.payload AS CHAR), 256)))
           OR HEX(NEW.event_key) <> HEX(CONCAT(
                'telegram-delivery-requested:',
                JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.telegram_delivery_operation_public_id'))
           ))
           OR HEX(NEW.aggregate_type) <> HEX('telegram_delivery_operation')
           OR HEX(NEW.aggregate_id) <> HEX(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.telegram_delivery_operation_public_id')))
           OR BINARY NEW.aggregate_id <> BINARY COALESCE(@app_telegram_delivery_public_id, '') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery Outbox command must use the exact canonical safe envelope.';
        END IF;

        SET NEW.dispatch_state = 'authority_pending';
        SET NEW.lease_token = NULL;
        SET NEW.leased_until = NULL;
        SET NEW.processed_at = NULL;
        SET NEW.attempts = 0;
        SET NEW.review_reason = NULL;
        SET NEW.last_error_class = NULL;
        SET NEW.last_error_code = NULL;
    END IF;
END
SQL);
    }

    private function createOperationInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_delivery_operations_insert_guard
BEFORE INSERT ON telegram_delivery_operations
FOR EACH ROW
BEGIN
    DECLARE valid_outbox_count INT DEFAULT 0;
    DECLARE expected_presentation_hash CHAR(64) DEFAULT NULL;

    SET expected_presentation_hash = CASE
        WHEN NEW.presentation_text IS NULL THEN NULL
        ELSE LOWER(SHA2(NEW.presentation_text, 256))
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM telegram_delivery_authority_capability capability_row
        WHERE capability_row.id = 1
          AND capability_row.schema_version = 1
          AND capability_row.activated_at IS NOT NULL
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_telegram_delivery_capability, ''), 256)
    )
       OR COALESCE(@app_telegram_delivery_authority, '') <> 'telegram_delivery_queue_v1'
       OR BINARY NEW.public_id <> BINARY COALESCE(@app_telegram_delivery_public_id, '')
       OR BINARY NEW.request_key_hash <> BINARY COALESCE(@app_telegram_delivery_request_hash, '')
       OR BINARY NEW.request_fingerprint <> BINARY COALESCE(@app_telegram_delivery_fingerprint, '')
       OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_telegram_delivery_correlation_id, '')
       OR BINARY NEW.action <> BINARY COALESCE(@app_telegram_delivery_action, '')
       OR BINARY NEW.bot_id <> BINARY COALESCE(@app_telegram_delivery_bot_id, '')
       OR NEW.recipient_chat_id <> COALESCE(@app_telegram_delivery_recipient_chat_id, 0)
       OR NOT (NEW.target_message_id <=> @app_telegram_delivery_target_message_id)
       OR NOT (BINARY expected_presentation_hash <=> BINARY @app_telegram_delivery_presentation_hash)
       OR BINARY NEW.outbox_event_id <> BINARY COALESCE(@app_telegram_delivery_outbox_event_id, '')
       OR BINARY NEW.state <> BINARY 'prepared'
       OR NEW.state_version <> 1
       OR NEW.provider_attempts <> 0
       OR NEW.provider_boundary_started_at IS NOT NULL
       OR NEW.completed_at IS NOT NULL
       OR NEW.telegram_message_id IS NOT NULL
       OR NEW.result_code IS NOT NULL
       OR NEW.retry_after_seconds IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery operation creation authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_outbox_count
    FROM outbox_messages outbox_row
    WHERE BINARY outbox_row.id = BINARY NEW.outbox_event_id
      AND BINARY outbox_row.event_type = BINARY 'telegram.delivery.requested'
      AND BINARY outbox_row.event_key = BINARY CONCAT('telegram-delivery-requested:', NEW.public_id)
      AND BINARY outbox_row.aggregate_type = BINARY 'telegram_delivery_operation'
      AND BINARY outbox_row.aggregate_id = BINARY NEW.public_id
      AND BINARY outbox_row.correlation_id = BINARY NEW.correlation_id
      AND outbox_row.dispatch_state = 'authority_pending'
      AND outbox_row.processed_at IS NULL
      AND outbox_row.lease_token IS NULL
      AND outbox_row.leased_until IS NULL
      AND outbox_row.attempts = 0
      AND outbox_row.review_reason IS NULL
      AND outbox_row.last_error_class IS NULL
      AND outbox_row.last_error_code IS NULL
      AND COALESCE(JSON_TYPE(outbox_row.payload), '') = 'OBJECT'
      AND COALESCE(JSON_LENGTH(outbox_row.payload), -1) = 1
      AND BINARY COALESCE(JSON_UNQUOTE(JSON_EXTRACT(outbox_row.payload, '$.telegram_delivery_operation_public_id')), '') = BINARY NEW.public_id
      AND HEX(CAST(outbox_row.payload AS CHAR)) = HEX(CONCAT('{"telegram_delivery_operation_public_id":"', NEW.public_id, '"}'))
      AND HEX(outbox_row.payload_hash) = HEX(LOWER(SHA2(CAST(outbox_row.payload AS CHAR), 256)));

    IF valid_outbox_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery operation requires one exact quarantined Outbox command.';
    END IF;
END
SQL);
    }

    private function createOperationUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_delivery_operations_update_guard
BEFORE UPDATE ON telegram_delivery_operations
FOR EACH ROW
BEGIN
    DECLARE capability_fence_rows INT DEFAULT 0;

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
       OR COALESCE(@app_telegram_delivery_effect_authority, '') <> 'telegram_delivery_effect_v1'
       OR BINARY OLD.public_id <> BINARY COALESCE(@app_telegram_delivery_effect_public_id, '')
       OR OLD.state_version <> COALESCE(@app_telegram_delivery_effect_expected_version, 0)
       OR NEW.state_version <> OLD.state_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery operation transition authority is invalid.';
    END IF;

    IF OLD.id <> NEW.id
       OR BINARY OLD.public_id <> BINARY NEW.public_id
       OR BINARY OLD.request_key_hash <> BINARY NEW.request_key_hash
       OR BINARY OLD.request_fingerprint <> BINARY NEW.request_fingerprint
       OR BINARY OLD.correlation_id <> BINARY NEW.correlation_id
       OR BINARY OLD.action <> BINARY NEW.action
       OR BINARY OLD.bot_id <> BINARY NEW.bot_id
       OR OLD.recipient_chat_id <> NEW.recipient_chat_id
       OR NOT (OLD.target_message_id <=> NEW.target_message_id)
       OR NOT (OLD.presentation_text <=> NEW.presentation_text)
       OR BINARY OLD.outbox_event_id <> BINARY NEW.outbox_event_id
       OR NOT (OLD.created_at <=> NEW.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery operation immutable identity cannot be retargeted.';
    END IF;

    IF OLD.state IN ('prepared','retryable') AND NEW.state = 'sending' THEN
        IF NEW.provider_attempts <> OLD.provider_attempts + 1
           OR NEW.provider_boundary_started_at IS NULL
           OR NEW.completed_at IS NOT NULL
           OR NEW.telegram_message_id IS NOT NULL
           OR NEW.result_code IS NOT NULL
           OR NEW.retry_after_seconds IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery provider boundary transition is invalid.';
        END IF;
    ELSEIF OLD.state = 'sending' AND NEW.state = 'retryable' THEN
        IF NEW.provider_attempts <> OLD.provider_attempts
           OR NOT (NEW.provider_boundary_started_at <=> OLD.provider_boundary_started_at)
           OR NEW.completed_at IS NOT NULL
           OR NEW.telegram_message_id IS NOT NULL
           OR NEW.result_code IS NULL
           OR NEW.retry_after_seconds IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery retryable result transition is invalid.';
        END IF;
    ELSEIF OLD.state = 'sending' AND NEW.state = 'succeeded' THEN
        IF NEW.provider_attempts <> OLD.provider_attempts
           OR NOT (NEW.provider_boundary_started_at <=> OLD.provider_boundary_started_at)
           OR NEW.completed_at IS NULL
           OR NEW.result_code IS NULL
           OR NEW.retry_after_seconds IS NOT NULL
           OR (NEW.action = 'delete' AND NEW.telegram_message_id IS NOT NULL)
           OR (NEW.action IN ('send','edit') AND (NEW.telegram_message_id IS NULL OR NEW.telegram_message_id < 1))
           OR (NEW.action = 'edit' AND NEW.telegram_message_id <> NEW.target_message_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery success result transition is invalid.';
        END IF;
    ELSEIF OLD.state = 'sending' AND NEW.state IN ('failed_final','uncertain') THEN
        IF NEW.provider_attempts <> OLD.provider_attempts
           OR NOT (NEW.provider_boundary_started_at <=> OLD.provider_boundary_started_at)
           OR NEW.completed_at IS NULL
           OR NEW.telegram_message_id IS NOT NULL
           OR NEW.result_code IS NULL
           OR NEW.retry_after_seconds IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery terminal result transition is invalid.';
        END IF;
    ELSEIF OLD.state = 'sending' AND NEW.state = 'review_required' THEN
        IF NEW.provider_attempts <> OLD.provider_attempts
           OR NOT (NEW.provider_boundary_started_at <=> OLD.provider_boundary_started_at)
           OR NEW.completed_at IS NULL
           OR NEW.telegram_message_id IS NOT NULL
           OR NEW.result_code IS NULL
           OR NEW.retry_after_seconds IS NULL
           OR NEW.retry_after_seconds < 1
           OR NEW.retry_after_seconds > 86400 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery review-required result transition is invalid.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery operation state transition is not allowed.';
    END IF;
END
SQL);
    }

    private function createOperationDeleteGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_delivery_operations_delete_guard
BEFORE DELETE ON telegram_delivery_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery operation evidence is non-deletable.';
END
SQL);
    }

    private function createOutboxUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_telegram_delivery_envelope_update_guard
BEFORE UPDATE ON outbox_messages
FOR EACH ROW
BEGIN
    DECLARE final_authority_count INT DEFAULT 0;

    IF HEX(OLD.event_type) = HEX('telegram.delivery.requested') THEN
        IF HEX(OLD.id) <> HEX(NEW.id)
           OR HEX(OLD.event_key) <> HEX(NEW.event_key)
           OR HEX(OLD.event_type) <> HEX(NEW.event_type)
           OR HEX(OLD.aggregate_type) <> HEX(NEW.aggregate_type)
           OR HEX(OLD.aggregate_id) <> HEX(NEW.aggregate_id)
           OR HEX(CAST(OLD.payload AS CHAR)) <> HEX(CAST(NEW.payload AS CHAR))
           OR HEX(OLD.payload_hash) <> HEX(NEW.payload_hash)
           OR HEX(OLD.correlation_id) <> HEX(NEW.correlation_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery Outbox command identity is immutable.';
        END IF;

        IF HEX(NEW.dispatch_state) NOT IN (
            HEX('authority_pending'), HEX('pending'), HEX('leased'), HEX('retry'), HEX('processed'), HEX('review_required')
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery Outbox command must use an exact dispatch lifecycle state.';
        END IF;

        IF OLD.dispatch_state = 'authority_pending' AND NEW.dispatch_state <> 'authority_pending' THEN
            IF NOT EXISTS (
                SELECT 1
                FROM telegram_delivery_authority_capability capability_row
                WHERE capability_row.id = 1
                  AND capability_row.schema_version = 1
                  AND capability_row.activated_at IS NOT NULL
                  AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_telegram_delivery_capability, ''), 256)
            )
               OR COALESCE(@app_telegram_delivery_authority, '') <> 'telegram_delivery_queue_v1'
               OR BINARY NEW.id <> BINARY COALESCE(@app_telegram_delivery_outbox_event_id, '')
               OR BINARY NEW.aggregate_id <> BINARY COALESCE(@app_telegram_delivery_public_id, '')
               OR HEX(NEW.dispatch_state) <> HEX('pending')
               OR NEW.processed_at IS NOT NULL
               OR NEW.lease_token IS NOT NULL
               OR NEW.leased_until IS NOT NULL
               OR NEW.attempts <> 0
               OR NEW.review_reason IS NOT NULL
               OR NEW.last_error_class IS NOT NULL
               OR NEW.last_error_code IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery Outbox authority can only release into a clean pending state.';
            END IF;

            SELECT COUNT(*) INTO final_authority_count
            FROM telegram_delivery_operations operation_row
            WHERE BINARY operation_row.outbox_event_id = BINARY NEW.id
              AND BINARY operation_row.public_id = BINARY NEW.aggregate_id
              AND BINARY operation_row.correlation_id = BINARY NEW.correlation_id
              AND BINARY operation_row.request_key_hash = BINARY COALESCE(@app_telegram_delivery_request_hash, '')
              AND BINARY operation_row.request_fingerprint = BINARY COALESCE(@app_telegram_delivery_fingerprint, '')
              AND BINARY operation_row.action = BINARY COALESCE(@app_telegram_delivery_action, '')
              AND BINARY operation_row.bot_id = BINARY COALESCE(@app_telegram_delivery_bot_id, '')
              AND operation_row.recipient_chat_id = COALESCE(@app_telegram_delivery_recipient_chat_id, 0)
              AND (operation_row.target_message_id <=> @app_telegram_delivery_target_message_id)
              AND operation_row.state = 'prepared'
              AND operation_row.state_version = 1
              AND operation_row.provider_attempts = 0;

            IF final_authority_count <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery Outbox dispatch requires the exact final operation authority.';
            END IF;
        ELSEIF OLD.dispatch_state <> 'authority_pending' AND NEW.dispatch_state = 'authority_pending' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery Outbox lifecycle cannot return to authority_pending.';
        END IF;
    ELSEIF LOWER(NEW.event_type) = 'telegram.delivery.requested' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing Outbox rows cannot be converted into Telegram delivery commands.';
    END IF;
END
SQL);
    }

    private function createOutboxDeleteGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_telegram_delivery_envelope_delete_guard
BEFORE DELETE ON outbox_messages
FOR EACH ROW
BEGIN
    IF HEX(OLD.event_type) = HEX('telegram.delivery.requested') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery Outbox commands are non-deletable.';
    END IF;
END
SQL);
    }

    private function capabilityValue(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Telegram delivery database capability key is unavailable.');
        }

        return hash_hmac('sha256', 'telegram-delivery-database-authority-v1', $key);
    }

    private function capabilityHash(): string
    {
        return hash('sha256', $this->capabilityValue());
    }
};

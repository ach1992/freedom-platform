<?php

declare(strict_types=1);

use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryLifecycleDatabaseAuthority;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROLLBACK_TABLE = 'telegram_delivery_interactive_presentations_rollback';

    public function up(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            if (! Schema::hasTable('telegram_delivery_interactive_presentations')) {
                Schema::create('telegram_delivery_interactive_presentations', function (Blueprint $table): void {
                    $table->char('delivery_operation_public_id', 26)->primary();
                    $table->longText('keyboard_snapshot');
                    $table->char('keyboard_snapshot_hash', 64);
                    $table->dateTime('created_at', 6);
                });
            }

            return;
        }

        $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority;
        $lifecycleConnection = $lifecycleAuthority->requireConnection($connection);
        $lifecycleAuthority->principalUsername($lifecycleConnection);

        $this->withInstallationLock($lifecycleConnection, function () use ($connection): void {
            $baseSurface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
            $baseCapability = new TelegramDeliveryDatabaseCapability;
            if (! $baseSurface->isReady($connection, $baseCapability->expectedHash())) {
                throw new RuntimeException('Telegram interactive presentation authority requires the active v1 delivery authority.');
            }

            $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
            if ($surface->isReady($connection)) {
                return;
            }

            if (Schema::hasTable('telegram_delivery_interactive_presentations')) {
                if ($connection->table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->exists()) {
                    throw new RuntimeException('Telegram interactive presentation authority cannot repair a non-empty unrecognized surface.');
                }
                // Keep every authority trigger attached until the table itself is
                // successfully removed. A dependency-sensitive DROP can fail; MariaDB
                // removes the table's triggers atomically only when the DROP succeeds.
                Schema::drop('telegram_delivery_interactive_presentations');
            }

            DB::unprepared(<<<'SQL'
CREATE TABLE telegram_delivery_interactive_presentations (
    delivery_operation_public_id CHAR(26) NOT NULL,
    keyboard_snapshot LONGTEXT NOT NULL,
    keyboard_snapshot_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (delivery_operation_public_id),
    CONSTRAINT telegram_delivery_interactive_public_chk CHECK (
        delivery_operation_public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
        AND BINARY delivery_operation_public_id = BINARY UPPER(delivery_operation_public_id)
    ),
    CONSTRAINT telegram_delivery_interactive_snapshot_hash_chk CHECK (
        keyboard_snapshot_hash REGEXP '^[0-9a-f]{64}$'
    ),
    CONSTRAINT telegram_delivery_interactive_snapshot_json_chk CHECK (
        JSON_VALID(keyboard_snapshot) = 1
        AND JSON_TYPE(keyboard_snapshot) = 'OBJECT'
        AND OCTET_LENGTH(keyboard_snapshot) BETWEEN 2 AND 16384
    )
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
SQL);

            $this->createTriggers();
            if (! $surface->isReady($connection)) {
                throw new RuntimeException('Telegram interactive presentation authority did not reach its exact database surface.');
            }
        });
    }

    public function down(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            if (! Schema::hasTable('telegram_delivery_interactive_presentations')) {
                return;
            }
            if ($connection->table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->exists()) {
                throw new RuntimeException('Telegram interactive presentation authority cannot be removed while durable snapshots exist.');
            }
            Schema::dropIfExists('telegram_delivery_interactive_presentations');

            return;
        }

        $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority;
        $lifecycleConnection = $lifecycleAuthority->requireConnection($connection);
        $lifecycleAuthority->principalUsername($lifecycleConnection);

        $this->withInstallationLock($lifecycleConnection, function () use ($connection, $lifecycleConnection): void {
            $this->rollbackMysql($connection, $lifecycleConnection);
        });
    }

    /**
     * Persist the existing #179 capability as state 0, then move the interactive
     * table to a rollback-only staging name before restoring v1 runtime authority.
     *
     * The 1 -> 0 transition drains producers that already entered through either
     * the application pre-lock or the INSERT trigger. Later producers fail closed
     * while state 0 is durable. The staging rename then waits only for table metadata
     * users and does not hold the capability X-lock, avoiding the MDL/row-lock
     * inversion identified by independent review. Once renamed, normal runtime
     * code can no longer target the table; v1 can be reactivated before DROP so a
     * crash after successful destruction cannot strand the shared authority at 0.
     *
     * A failed dependency-sensitive DROP restores the staged table to its canonical
     * name with all original triggers still attached. An interrupted rollback with
     * the staging name present is normalized back to the canonical name on retry.
     *
     * @param  null|Closure():void  $afterRuntimeFence
     */
    private function rollbackMysql(
        Connection $connection,
        Connection $lifecycleConnection,
        ?Closure $afterRuntimeFence = null,
    ): void {
        $this->restoreStagedTableForRetry($connection);
        if (! Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)) {
            return;
        }

        if ($connection->table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->exists()) {
            throw new RuntimeException('Telegram interactive presentation authority cannot be removed while durable snapshots exist.');
        }

        // Re-attest the lifecycle boundary while this session owns the shared
        // installation lock. This preserves #179's exact principal/server checks.
        (new TelegramDeliveryLifecycleDatabaseAuthority)->requireConnection($connection);

        $fenceEstablished = false;
        $staged = false;
        try {
            $this->establishPersistentRuntimeFence($connection, $lifecycleConnection);
            $fenceEstablished = true;

            if ($afterRuntimeFence !== null) {
                $afterRuntimeFence();
            }

            if ($connection->table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->exists()) {
                throw new RuntimeException('Telegram interactive presentation authority cannot be removed while durable snapshots exist.');
            }

            $this->stageInteractiveTableForRollback($connection);
            $staged = true;

            // The canonical table name is now absent, so ordinary runtime and the
            // trigger-only producer path cannot enter between reactivation and DROP.
            $this->reactivateRuntimeAuthority($connection, $lifecycleConnection);
            $fenceEstablished = false;

            try {
                $connection->statement('DROP TABLE `'.self::ROLLBACK_TABLE.'`');
                $staged = false;
            } catch (Throwable $exception) {
                $this->restoreStagedTableForRetry($connection);
                $staged = false;
                throw $exception;
            }

            if ($this->baseLifecycleState($connection) !== 'active') {
                throw new RuntimeException('Telegram interactive presentation rollback did not preserve active v1 delivery authority after table destruction.');
            }
        } finally {
            if ($staged) {
                $this->restoreStagedTableForRetry($connection);
                $staged = false;
            }

            if ($fenceEstablished || $this->baseLifecycleState($connection) === 'fenced') {
                $this->reactivateRuntimeAuthority($connection, $lifecycleConnection);
            }
        }
    }

    private function stageInteractiveTableForRollback(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            || $schema->hasTable(self::ROLLBACK_TABLE)) {
            throw new RuntimeException('Telegram interactive presentation rollback staging identity is not available.');
        }

        $connection->statement(
            'RENAME TABLE `telegram_delivery_interactive_presentations` TO `telegram_delivery_interactive_presentations_rollback`',
        );

        $postRenameSchema = $connection->getSchemaBuilder();
        if ($postRenameSchema->hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            || ! $postRenameSchema->hasTable(self::ROLLBACK_TABLE)) {
            throw new RuntimeException('Telegram interactive presentation rollback staging rename did not reach the exact expected state.');
        }
    }

    private function restoreStagedTableForRetry(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        $hasCanonical = $schema->hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE);
        $hasStaged = $schema->hasTable(self::ROLLBACK_TABLE);
        if (! $hasStaged) {
            return;
        }
        if ($hasCanonical) {
            throw new RuntimeException('Telegram interactive presentation rollback found both canonical and staged authority tables.');
        }

        $connection->statement(
            'RENAME TABLE `telegram_delivery_interactive_presentations_rollback` TO `telegram_delivery_interactive_presentations`',
        );

        $postRestoreSchema = $connection->getSchemaBuilder();
        if (! $postRestoreSchema->hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            || $postRestoreSchema->hasTable(self::ROLLBACK_TABLE)) {
            throw new RuntimeException('Telegram interactive presentation rollback could not restore the staged authority table.');
        }
    }

    private function establishPersistentRuntimeFence(
        Connection $connection,
        Connection $lifecycleConnection,
    ): void {
        $state = $this->baseLifecycleState($connection);
        if ($state === 'fenced') {
            return;
        }
        if ($state !== 'active') {
            throw new RuntimeException('Telegram interactive presentation rollback requires the exact active or rollback-fenced v1 delivery authority.');
        }

        $capabilityAuthority = new TelegramDeliveryDatabaseCapability;
        $lifecycleConnection->transaction(function (Connection $lifecycleConnection) use ($capabilityAuthority): void {
            $capability = $lifecycleConnection->selectOne(<<<'SQL'
SELECT id, capability_hash, schema_version, activated_at
FROM telegram_delivery_authority_capability
WHERE id = 1
FOR UPDATE
SQL, [], false);
            if ($capability === null
                || (int) ($capability->id ?? 0) !== 1
                || ! is_string($capability->capability_hash ?? null)
                || ! hash_equals($capabilityAuthority->expectedHash(), $capability->capability_hash)
                || (int) ($capability->schema_version ?? -1) !== 1
                || ($capability->activated_at ?? null) === null) {
                throw new RuntimeException('Telegram interactive presentation rollback could not establish the v1 persistent lifecycle fence.');
            }

            $armed = false;
            try {
                $lifecycleConnection->statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_lifecycle_authority = 'rollback'
SQL, [$capabilityAuthority->value()]);
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
                    throw new RuntimeException('Telegram interactive presentation rollback lifecycle fence activation was rejected.');
                }
            } finally {
                if ($armed) {
                    $this->clearLifecycleAuthority($lifecycleConnection);
                }
            }
        }, 1);

        if ($this->baseLifecycleState($connection) !== 'fenced') {
            throw new RuntimeException('Telegram interactive presentation rollback lifecycle fence did not reach the persistent disabled state.');
        }
    }

    private function reactivateRuntimeAuthority(
        Connection $connection,
        Connection $lifecycleConnection,
    ): void {
        $state = $this->baseLifecycleState($connection);
        if ($state === 'active') {
            return;
        }
        if ($state !== 'fenced') {
            throw new RuntimeException('Telegram interactive presentation rollback cannot reactivate an unrecognized v1 delivery lifecycle state.');
        }

        $this->reactivateRuntimeAuthorityLifecycleOnly($lifecycleConnection);

        if ($this->baseLifecycleState($connection) !== 'active') {
            throw new RuntimeException('Telegram interactive presentation rollback did not restore the active v1 delivery authority.');
        }
    }

    private function reactivateRuntimeAuthorityLifecycleOnly(Connection $lifecycleConnection): void
    {
        $capabilityAuthority = new TelegramDeliveryDatabaseCapability;
        $lifecycleConnection->transaction(function (Connection $lifecycleConnection) use ($capabilityAuthority): void {
            $capability = $lifecycleConnection->selectOne(<<<'SQL'
SELECT id, capability_hash, schema_version, activated_at
FROM telegram_delivery_authority_capability
WHERE id = 1
FOR UPDATE
SQL, [], false);
            if ($capability === null
                || (int) ($capability->id ?? 0) !== 1
                || ! is_string($capability->capability_hash ?? null)
                || ! hash_equals($capabilityAuthority->expectedHash(), $capability->capability_hash)
                || (int) ($capability->schema_version ?? -1) !== 0
                || ($capability->activated_at ?? null) !== null) {
                throw new RuntimeException('Telegram interactive presentation rollback cannot reactivate a changed v1 lifecycle fence.');
            }

            $armed = false;
            try {
                $lifecycleConnection->statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_lifecycle_authority = 'activate'
SQL, [$capabilityAuthority->value()]);
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
                    throw new RuntimeException('Telegram interactive presentation rollback v1 lifecycle reactivation was rejected.');
                }
            } finally {
                if ($armed) {
                    $this->clearLifecycleAuthority($lifecycleConnection);
                }
            }
        }, 1);

        $capability = $lifecycleConnection->table('telegram_delivery_authority_capability')
            ->where('id', 1)
            ->first(['capability_hash', 'schema_version', 'activated_at']);
        if ($capability === null
            || ! is_string($capability->capability_hash ?? null)
            || ! hash_equals($capabilityAuthority->expectedHash(), $capability->capability_hash)
            || (int) ($capability->schema_version ?? -1) !== 1
            || ($capability->activated_at ?? null) === null) {
            throw new RuntimeException('Telegram interactive presentation rollback lifecycle reactivation did not reach the exact active row state.');
        }
    }

    /** @return 'active'|'fenced'|'invalid' */
    private function baseLifecycleState(Connection $connection): string
    {
        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        if (! $surface->semanticsMatchExpected($connection)) {
            return 'invalid';
        }

        $rows = $connection->table('telegram_delivery_authority_capability')->get([
            'id', 'capability_hash', 'schema_version', 'activated_at',
        ]);
        if ($rows->count() !== 1) {
            return 'invalid';
        }

        $capability = $rows->first();
        $expectedHash = (new TelegramDeliveryDatabaseCapability)->expectedHash();
        if ($capability === null
            || (int) ($capability->id ?? 0) !== 1
            || ! is_string($capability->capability_hash ?? null)
            || ! hash_equals($expectedHash, $capability->capability_hash)) {
            return 'invalid';
        }

        if ((int) ($capability->schema_version ?? -1) === 1
            && ($capability->activated_at ?? null) !== null) {
            return 'active';
        }
        if ((int) ($capability->schema_version ?? -1) === 0
            && ($capability->activated_at ?? null) === null) {
            return 'fenced';
        }

        return 'invalid';
    }

    private function clearLifecycleAuthority(Connection $connection): void
    {
        try {
            $connection->statement(<<<'SQL'
SET @app_telegram_delivery_lifecycle_authority = NULL,
    @app_telegram_delivery_capability = NULL
SQL);
        } catch (Throwable $exception) {
            $connection->disconnect();
            throw $exception;
        }
    }

    private function createTriggers(): void
    {
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        DB::unprepared('CREATE TRIGGER '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::INSERT_TRIGGER
            .' BEFORE INSERT ON '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$surface::insertTriggerBody());
        DB::unprepared('CREATE TRIGGER '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::UPDATE_TRIGGER
            .' BEFORE UPDATE ON '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$surface::updateTriggerBody());
        DB::unprepared('CREATE TRIGGER '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::DELETE_TRIGGER
            .' BEFORE DELETE ON '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$surface::deleteTriggerBody());
    }

    /** @param Closure():void $operation */
    private function withInstallationLock(Connection $connection, Closure $operation): void
    {
        $lockName = TelegramDeliveryDatabaseAuthoritySurfaceV1::installationLockName($connection);
        $lock = $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName], false);
        if ($lock === null || (int) ($lock->acquired ?? 0) !== 1) {
            throw new RuntimeException('Could not acquire the Telegram delivery installation lock for interactive presentation authority.');
        }

        try {
            $operation();
        } finally {
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName], false);
        }
    }
};

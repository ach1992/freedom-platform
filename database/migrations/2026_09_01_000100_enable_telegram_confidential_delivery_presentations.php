<?php

declare(strict_types=1);

use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryLifecycleDatabaseAuthority;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROLLBACK_TABLE = 'telegram_delivery_confidential_presentations_rollback';

    public function up(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            if (! Schema::hasTable('telegram_delivery_confidential_presentations')) {
                Schema::create('telegram_delivery_confidential_presentations', function (Blueprint $table): void {
                    $table->char('delivery_operation_public_id', 26)->primary();
                    $table->longText('presentation_ciphertext');
                    $table->char('presentation_hash', 64);
                    $table->dateTime('created_at', 6);
                });
            }

            return;
        }

        $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority;
        $lifecycleConnection = $lifecycleAuthority->requireConnection($connection);
        $lifecycleAuthority->principalUsername($lifecycleConnection);

        $this->withInstallationLock($connection, $lifecycleConnection, function () use ($connection): void {
            $baseSurface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
            $baseCapability = new TelegramDeliveryDatabaseCapability;
            $persistedCapabilityHash = $connection->table('telegram_delivery_authority_capability')
                ->where('id', 1)
                ->value('capability_hash');
            if (! is_string($persistedCapabilityHash)
                || $baseCapability->valueMatchingHash($persistedCapabilityHash) === null
                || ! $baseSurface->isReady($connection, $persistedCapabilityHash)) {
                throw new RuntimeException('Telegram confidential presentation authority requires the active v1 delivery authority.');
            }

            $surface = new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
            $schema = $connection->getSchemaBuilder();
            $hasConfidentialSurface = $schema->hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE);

            // A durable unrecognized confidential surface is a hard preflight
            // failure. Refuse it before upgrading the shared #209 trigger so an
            // unsuccessful #212 install cannot leave a needless partial schema
            // mutation behind. Exact durable surfaces remain recoverable.
            if ($hasConfidentialSurface
                && ! $surface->isReady($connection)
                && $connection->table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)->exists()
            ) {
                throw new RuntimeException('Telegram confidential presentation authority cannot repair a non-empty unrecognized surface.');
            }

            $this->ensureInteractiveV3Trigger($connection);

            if ($surface->isReady($connection)) {
                return;
            }

            if ($hasConfidentialSurface) {
                // Keep every authority trigger attached until the table itself is
                // successfully removed. A dependency-sensitive DROP can fail; MariaDB
                // removes the table's triggers atomically only when the DROP succeeds.
                $schema->drop('telegram_delivery_confidential_presentations');
            }

            $connection->unprepared(<<<'SQL'
CREATE TABLE telegram_delivery_confidential_presentations (
    delivery_operation_public_id CHAR(26) NOT NULL,
    presentation_ciphertext LONGTEXT NOT NULL,
    presentation_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (delivery_operation_public_id),
    CONSTRAINT telegram_delivery_confidential_public_chk CHECK (
        delivery_operation_public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
        AND BINARY delivery_operation_public_id = BINARY UPPER(delivery_operation_public_id)
    ),
    CONSTRAINT telegram_delivery_confidential_hash_chk CHECK (
        presentation_hash REGEXP '^[0-9a-f]{64}$'
    ),
    CONSTRAINT telegram_delivery_confidential_ciphertext_chk CHECK (
        OCTET_LENGTH(presentation_ciphertext) BETWEEN 1 AND 65536
    )
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
SQL);

            $this->createTriggers($connection);
            if (! $surface->isReady($connection)) {
                throw new RuntimeException('Telegram confidential presentation authority did not reach its exact database surface.');
            }
        });
    }

    public function down(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            if (! Schema::hasTable('telegram_delivery_confidential_presentations')) {
                return;
            }
            if ($connection->table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)->exists()) {
                throw new RuntimeException('Telegram confidential presentation authority cannot be removed while durable confidential presentations exist.');
            }
            Schema::dropIfExists('telegram_delivery_confidential_presentations');

            return;
        }

        $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority;
        $lifecycleConnection = $lifecycleAuthority->requireConnection($connection);
        $lifecycleAuthority->principalUsername($lifecycleConnection);

        $this->withInstallationLock($connection, $lifecycleConnection, function () use ($connection, $lifecycleConnection): void {
            $this->rollbackMysql($connection, $lifecycleConnection);
        });
    }

    /**
     * Persist the existing #179 capability as state 0, then move the confidential
     * table to a rollback-only staging name before restoring v1 runtime authority.
     *
     * The 1 -> 0 transition drains producers that already entered through either
     * the application pre-lock or the INSERT trigger. Later producers fail closed
     * while state 0 is durable. The staging rename then waits only for table metadata
     * users and does not hold the capability X-lock, avoiding the MDL/row-lock
     * inversion identified by independent review. Once renamed, normal runtime
     * code can no longer target the table. The state-0 fence remains active through
     * destructive DROP so even a trigger-only writer that knows the staging name
     * cannot create durable rows in the final DDL window. v1 is reactivated only
     * after successful destruction (or after a failed DROP restores the table).
     *
     * A failed dependency-sensitive DROP restores the staged table to its canonical
     * name with all original triggers still attached. An interrupted rollback with
     * the staging name present is normalized back to the canonical name on retry.
     *
     * @param  null|Closure():void  $afterRuntimeFence
     * @param  null|Closure():void  $afterStagingFence
     */
    private function rollbackMysql(
        Connection $connection,
        Connection $lifecycleConnection,
        ?Closure $afterRuntimeFence = null,
        ?Closure $afterStagingFence = null,
    ): void {
        $this->restoreStagedTableForRetry($connection);
        if (! $connection->getSchemaBuilder()->hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)) {
            // A completed #212 rollback must restore the exact pre-#212 interactive
            // v2 trigger, not leave the current v2/v3 trigger behind for older code.
            $this->restoreLegacyInteractiveV2Trigger($connection);
            if ($this->baseLifecycleState($connection) === 'fenced') {
                // A prior rollback can die after DROP committed but before the
                // trigger downgrade and/or lifecycle finally block restored v1.
                $this->reactivateRuntimeAuthority($connection, $lifecycleConnection);
            }

            return;
        }

        if ($connection->table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)->exists()) {
            throw new RuntimeException('Telegram confidential presentation authority cannot be removed while durable confidential presentations exist.');
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

            if ($connection->table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)->exists()) {
                throw new RuntimeException('Telegram confidential presentation authority cannot be removed while durable confidential presentations exist.');
            }

            $this->stageConfidentialTableForRollback($connection);
            $staged = true;

            if ($afterStagingFence !== null) {
                $afterStagingFence();
            }

            try {
                // Keep schema_version=0 through DROP. The staging table retains
                // its original triggers, so even explicit trigger-only access to
                // the staging name remains fail-closed until destruction commits.
                $connection->statement('DROP TABLE `'.self::ROLLBACK_TABLE.'`');
                $staged = false;
            } catch (Throwable $exception) {
                $this->restoreStagedTableForRetry($connection);
                $staged = false;
                throw $exception;
            }

            $this->restoreLegacyInteractiveV2Trigger($connection);
            $this->reactivateRuntimeAuthority($connection, $lifecycleConnection);
            $fenceEstablished = false;

            if ($this->baseLifecycleState($connection) !== 'active') {
                throw new RuntimeException('Telegram confidential presentation rollback did not preserve active v1 delivery authority after table destruction.');
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

    private function stageConfidentialTableForRollback(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            || $schema->hasTable(self::ROLLBACK_TABLE)) {
            throw new RuntimeException('Telegram confidential presentation rollback staging identity is not available.');
        }

        $connection->statement(
            'RENAME TABLE `telegram_delivery_confidential_presentations` TO `telegram_delivery_confidential_presentations_rollback`',
        );

        $postRenameSchema = $connection->getSchemaBuilder();
        if ($postRenameSchema->hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            || ! $postRenameSchema->hasTable(self::ROLLBACK_TABLE)) {
            throw new RuntimeException('Telegram confidential presentation rollback staging rename did not reach the exact expected state.');
        }
    }

    private function restoreStagedTableForRetry(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        $hasCanonical = $schema->hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE);
        $hasStaged = $schema->hasTable(self::ROLLBACK_TABLE);
        if (! $hasStaged) {
            return;
        }
        if ($hasCanonical) {
            throw new RuntimeException('Telegram confidential presentation rollback found both canonical and staged authority tables.');
        }

        $connection->statement(
            'RENAME TABLE `telegram_delivery_confidential_presentations_rollback` TO `telegram_delivery_confidential_presentations`',
        );

        $postRestoreSchema = $connection->getSchemaBuilder();
        if (! $postRestoreSchema->hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            || $postRestoreSchema->hasTable(self::ROLLBACK_TABLE)) {
            throw new RuntimeException('Telegram confidential presentation rollback could not restore the staged authority table.');
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
            throw new RuntimeException('Telegram confidential presentation rollback requires the exact active or rollback-fenced v1 delivery authority.');
        }

        $capabilityAuthority = new TelegramDeliveryDatabaseCapability;
        $lifecycleConnection->transaction(function (Connection $lifecycleConnection) use ($capabilityAuthority): void {
            $capability = $lifecycleConnection->selectOne(<<<'SQL'
SELECT id, capability_hash, schema_version, activated_at
FROM telegram_delivery_authority_capability
WHERE id = 1
FOR UPDATE
SQL, [], false);
            $capabilityValue = $capability !== null && is_string($capability->capability_hash ?? null)
                ? $capabilityAuthority->valueMatchingHash($capability->capability_hash)
                : null;
            if ($capability === null
                || (int) ($capability->id ?? 0) !== 1
                || ! is_string($capability->capability_hash ?? null)
                || $capabilityValue === null
                || (int) ($capability->schema_version ?? -1) !== 1
                || ($capability->activated_at ?? null) === null) {
                throw new RuntimeException('Telegram confidential presentation rollback could not establish the v1 persistent lifecycle fence.');
            }

            $armed = false;
            try {
                $lifecycleConnection->statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_lifecycle_authority = 'rollback'
SQL, [$capabilityValue]);
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
                    throw new RuntimeException('Telegram confidential presentation rollback lifecycle fence activation was rejected.');
                }
            } finally {
                if ($armed) {
                    $this->clearLifecycleAuthority($lifecycleConnection);
                }
            }
        }, 1);

        if ($this->baseLifecycleState($connection) !== 'fenced') {
            throw new RuntimeException('Telegram confidential presentation rollback lifecycle fence did not reach the persistent disabled state.');
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
            throw new RuntimeException('Telegram confidential presentation rollback cannot reactivate an unrecognized v1 delivery lifecycle state.');
        }

        $this->reactivateRuntimeAuthorityLifecycleOnly($lifecycleConnection);

        if ($this->baseLifecycleState($connection) !== 'active') {
            throw new RuntimeException('Telegram confidential presentation rollback did not restore the active v1 delivery authority.');
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
            $capabilityValue = $capability !== null && is_string($capability->capability_hash ?? null)
                ? $capabilityAuthority->valueMatchingHash($capability->capability_hash)
                : null;
            if ($capability === null
                || (int) ($capability->id ?? 0) !== 1
                || ! is_string($capability->capability_hash ?? null)
                || $capabilityValue === null
                || (int) ($capability->schema_version ?? -1) !== 0
                || ($capability->activated_at ?? null) !== null) {
                throw new RuntimeException('Telegram confidential presentation rollback cannot reactivate a changed v1 lifecycle fence.');
            }

            $armed = false;
            try {
                $lifecycleConnection->statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_lifecycle_authority = 'activate'
SQL, [$capabilityValue]);
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
                    throw new RuntimeException('Telegram confidential presentation rollback v1 lifecycle reactivation was rejected.');
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
            || $capabilityAuthority->valueMatchingHash($capability->capability_hash) === null
            || (int) ($capability->schema_version ?? -1) !== 1
            || ($capability->activated_at ?? null) === null) {
            throw new RuntimeException('Telegram confidential presentation rollback lifecycle reactivation did not reach the exact active row state.');
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
        $capabilityAuthority = new TelegramDeliveryDatabaseCapability;
        if ($capability === null
            || (int) ($capability->id ?? 0) !== 1
            || ! is_string($capability->capability_hash ?? null)
            || $capabilityAuthority->valueMatchingHash($capability->capability_hash) === null) {
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

    private function restoreLegacyInteractiveV2Trigger(Connection $connection): void
    {
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        if ($surface->isReady($connection, $surface::legacyV2InsertTriggerBody())) {
            return;
        }
        if (! $surface->isReady($connection)) {
            throw new RuntimeException('Telegram confidential rollback cannot restore legacy v2 from an unrecognized interactive presentation authority.');
        }

        $connection->unprepared('CREATE OR REPLACE TRIGGER '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::INSERT_TRIGGER
            .' BEFORE INSERT ON '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$surface::legacyV2InsertTriggerBody());

        if (! $surface->isReady($connection, $surface::legacyV2InsertTriggerBody())) {
            throw new RuntimeException('Telegram confidential rollback did not restore the exact legacy v2 interactive trigger.');
        }
    }

    private function ensureInteractiveV3Trigger(Connection $connection): void
    {
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        if ($surface->isReady($connection)) {
            return;
        }
        if (! $surface->isReady($connection, $surface::legacyV2InsertTriggerBody())) {
            throw new RuntimeException('Telegram confidential delivery cannot upgrade an unrecognized interactive presentation authority.');
        }

        $connection->unprepared('CREATE OR REPLACE TRIGGER '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::INSERT_TRIGGER
            .' BEFORE INSERT ON '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$surface::insertTriggerBody());

        if (! $surface->isReady($connection)) {
            throw new RuntimeException('Telegram confidential delivery did not upgrade the interactive v2/v3 trigger exactly.');
        }
    }

    private function createTriggers(Connection $connection): void
    {
        $surface = new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
        $connection->unprepared('CREATE TRIGGER '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::INSERT_TRIGGER
            .' BEFORE INSERT ON '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$surface::insertTriggerBody());
        $connection->unprepared('CREATE TRIGGER '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::UPDATE_TRIGGER
            .' BEFORE UPDATE ON '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$surface::updateTriggerBody());
        $connection->unprepared('CREATE TRIGGER '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::DELETE_TRIGGER
            .' BEFORE DELETE ON '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$surface::deleteTriggerBody());
    }

    /** @param Closure():void $operation */
    private function withInstallationLock(
        Connection $ddlConnection,
        Connection $lifecycleConnection,
        Closure $operation,
    ): void {
        $sharedLockName = TelegramDeliveryDatabaseAuthoritySurfaceV1::installationLockName($lifecycleConnection);
        $ddlLockName = $this->ddlInstallationLockName($ddlConnection);
        $sharedLockAcquired = false;
        $ddlLockAcquired = false;
        $lifecycleReconnectDisabled = false;
        $ddlReconnectDisabled = false;

        try {
            $sharedLock = $lifecycleConnection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$sharedLockName], false);
            if ($sharedLock === null || (int) ($sharedLock->acquired ?? 0) !== 1) {
                throw new RuntimeException('Could not acquire the shared Telegram delivery installation lock for confidential presentation authority.');
            }
            $sharedLockAcquired = true;
            $this->disableReconnectWhileLockHeld($lifecycleConnection, 'lifecycle');
            $lifecycleReconnectDisabled = true;

            // The shared #179 lock must remain owned by the dedicated lifecycle
            // principal because the immutable capability trigger authenticates that
            // exact session. Protected confidential DDL therefore also takes a second
            // database-scoped advisory lock on the exact runtime/DDL session. If the
            // lifecycle session disappears, another runner may recover the shared
            // lock but cannot overlap CREATE/DROP/RENAME/trigger DDL while this lock
            // remains owned by the first runner. If the DDL session disappears, its
            // own fail-closed reconnector prevents unlocked continuation.
            $ddlLock = $ddlConnection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$ddlLockName], false);
            if ($ddlLock === null || (int) ($ddlLock->acquired ?? 0) !== 1) {
                throw new RuntimeException('Could not acquire the Telegram confidential DDL installation lock.');
            }
            $ddlLockAcquired = true;
            $this->disableReconnectWhileLockHeld($ddlConnection, 'DDL');
            $ddlReconnectDisabled = true;

            $operation();
        } finally {
            $cleanupFailure = null;

            if ($ddlLockAcquired) {
                try {
                    $released = $ddlConnection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$ddlLockName], false);
                    if ($released === null || (int) ($released->released ?? 0) !== 1) {
                        throw new RuntimeException('Telegram confidential DDL installation lock release was not exact.');
                    }
                } catch (Throwable $exception) {
                    $this->disconnect($ddlConnection);
                    $cleanupFailure = new RuntimeException(
                        'Telegram confidential presentation DDL installation lock cleanup failed.',
                        0,
                        $exception,
                    );
                }
            }

            if ($sharedLockAcquired) {
                try {
                    $released = $lifecycleConnection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$sharedLockName], false);
                    if ($released === null || (int) ($released->released ?? 0) !== 1) {
                        throw new RuntimeException('Shared Telegram delivery installation lock release was not exact.');
                    }
                } catch (Throwable $exception) {
                    $this->disconnect($lifecycleConnection);
                    $cleanupFailure ??= new RuntimeException(
                        'Telegram confidential presentation shared installation lock cleanup failed.',
                        0,
                        $exception,
                    );
                }
            }

            if ($ddlReconnectDisabled) {
                $this->restoreDefaultReconnector($ddlConnection);
            }
            if ($lifecycleReconnectDisabled) {
                $this->restoreDefaultReconnector($lifecycleConnection);
            }

            if ($cleanupFailure instanceof RuntimeException) {
                throw $cleanupFailure;
            }
        }
    }

    private function ddlInstallationLockName(Connection $connection): string
    {
        $database = $connection->getDatabaseName();
        if ($database === '') {
            throw new RuntimeException('Telegram confidential DDL installation lock requires an exact database name.');
        }

        // #212 mutates the #209 interactive INSERT trigger as part of the same
        // protected schema transition. Reuse the exact historical #209 DDL-session
        // lock identity so lifecycle-session loss cannot let an interactive runner
        // overlap this migration on the shared trigger surface. A separate
        // confidential-only lock would leave that cross-migration window open.
        return 'telegram-interactive-ddl:'.substr(hash('sha256', $database), 0, 32);
    }

    private function disableReconnectWhileLockHeld(Connection $connection, string $sessionRole): void
    {
        $connection->setReconnector(static function (Connection $connection) use ($sessionRole): never {
            throw new RuntimeException(
                'Telegram confidential presentation '.$sessionRole.' database session was lost while its installation lock was held.',
            );
        });
    }

    private function restoreDefaultReconnector(Connection $connection): void
    {
        $database = app(DatabaseManager::class);

        $connection->setReconnector(static function (Connection $connection) use ($database): void {
            $name = $connection->getNameWithReadWriteType();
            if (! is_string($name) || $name === '') {
                throw new RuntimeException('Telegram confidential presentation database connection name is unavailable for reconnect.');
            }

            $reconnected = $database->reconnect($name);
            if (! $reconnected instanceof Connection) {
                throw new RuntimeException('Telegram confidential presentation database connection could not be restored.');
            }

            $connection->setPdo($reconnected->getRawPdo());
        });
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
};

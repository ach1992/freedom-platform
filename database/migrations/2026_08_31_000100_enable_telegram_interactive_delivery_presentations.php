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
                $this->dropTriggers();
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
     * Hold the existing #179 lifecycle capability row exclusively on the
     * dedicated lifecycle connection while the DDL connection performs the
     * interactive rollback. Runtime queue and trigger paths take a shared lock
     * on the same row, so the empty-snapshot preflight cannot race a late
     * durable insert between validation and DROP TABLE.
     *
     * The callback is a deterministic concurrency-test seam invoked only after
     * the runtime fence is held and before the final durable-data preflight.
     * Production down() never supplies it.
     *
     * @param  null|Closure():void  $afterRuntimeFence
     */
    private function rollbackMysql(
        Connection $connection,
        Connection $lifecycleConnection,
        ?Closure $afterRuntimeFence = null,
    ): void {
        $lifecycleConnection->transaction(function (Connection $lifecycleConnection) use ($connection, $afterRuntimeFence): void {
            $capability = $lifecycleConnection->selectOne(<<<'SQL'
SELECT id, capability_hash, schema_version, activated_at
FROM telegram_delivery_authority_capability
WHERE id = 1
FOR UPDATE
SQL, [], false);
            $expectedHash = (new TelegramDeliveryDatabaseCapability)->expectedHash();
            if ($capability === null
                || (int) ($capability->id ?? 0) !== 1
                || ! is_string($capability->capability_hash ?? null)
                || ! hash_equals($expectedHash, $capability->capability_hash)
                || (int) ($capability->schema_version ?? -1) !== 1
                || ($capability->activated_at ?? null) === null) {
                throw new RuntimeException('Telegram interactive presentation rollback requires the active v1 delivery lifecycle fence.');
            }

            if ($afterRuntimeFence !== null) {
                $afterRuntimeFence();
            }
            if (! Schema::hasTable('telegram_delivery_interactive_presentations')) {
                return;
            }
            if ($connection->table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->exists()) {
                throw new RuntimeException('Telegram interactive presentation authority cannot be removed while durable snapshots exist.');
            }

            $this->dropTriggers();
            Schema::dropIfExists('telegram_delivery_interactive_presentations');
        });
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

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::DELETE_TRIGGER);
        DB::unprepared('DROP TRIGGER IF EXISTS '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::UPDATE_TRIGGER);
        DB::unprepared('DROP TRIGGER IF EXISTS '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::INSERT_TRIGGER);
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

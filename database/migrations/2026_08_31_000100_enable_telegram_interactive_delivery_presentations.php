<?php

declare(strict_types=1);

use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
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
            if (! Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)) {
                Schema::create(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE, function (Blueprint $table): void {
                    $table->char('delivery_operation_public_id', 26)->primary();
                    $table->longText('keyboard_snapshot');
                    $table->char('keyboard_snapshot_hash', 64);
                    $table->dateTime('created_at', 6);
                });
            }

            return;
        }

        $baseSurface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        $baseCapability = new TelegramDeliveryDatabaseCapability;
        if (! $baseSurface->isReady($connection, $baseCapability->expectedHash())) {
            throw new RuntimeException('Telegram interactive presentation authority requires the active v1 delivery authority.');
        }

        $this->withInstallationLock($connection, function () use ($connection): void {
            $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
            if ($surface->isReady($connection)) {
                return;
            }

            if (Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)) {
                if ($connection->table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->exists()) {
                    throw new RuntimeException('Telegram interactive presentation authority cannot repair a non-empty unrecognized surface.');
                }
                $this->dropTriggers();
                Schema::drop(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE);
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
        if (! Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)) {
            return;
        }
        if (DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->exists()) {
            throw new RuntimeException('Telegram interactive presentation authority cannot be removed while durable snapshots exist.');
        }

        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            Schema::drop(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE);

            return;
        }

        $this->withInstallationLock($connection, function (): void {
            $this->dropTriggers();
            Schema::dropIfExists(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE);
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

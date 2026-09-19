<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @requirement COM-001 DAT-002 DAT-003 SEC-002 SEC-003 QUA-004 */
final class TelegramAdministratorDirectMessageCompletionMigrationTest extends TestCase
{
    use DatabaseTruncation;

    public function test_migration_restores_previous_interactive_trigger_on_safe_rollback_and_reapplies_current_authority(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram administrator direct-message migration safety requires MariaDB/MySQL.');
        }

        $migration = require database_path(
            'migrations/2026_09_19_000200_complete_telegram_administrator_direct_messaging.php',
        );
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        self::assertTrue($surface->isReady(DB::connection()));
        self::assertTrue(Schema::hasColumn(
            'telegram_administrator_direct_messages',
            'inline_keyboard_ciphertext',
        ));

        try {
            $migration->down();

            self::assertFalse(Schema::hasColumn(
                'telegram_administrator_direct_messages',
                'inline_keyboard_ciphertext',
            ));
            self::assertTrue($surface->isReady(
                DB::connection(),
                TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::legacyV3InsertTriggerBody(),
            ));
            self::assertFalse($surface->isReady(DB::connection()));

            $migration->up();

            self::assertTrue(Schema::hasColumn(
                'telegram_administrator_direct_messages',
                'inline_keyboard_ciphertext',
            ));
            self::assertTrue($surface->isReady(DB::connection()));
        } finally {
            if (! Schema::hasColumn(
                'telegram_administrator_direct_messages',
                'inline_keyboard_ciphertext',
            )) {
                $migration->up();
            }
        }
    }
}

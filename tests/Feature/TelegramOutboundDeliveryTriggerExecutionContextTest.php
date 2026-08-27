<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-004 QUA-007 QUA-010 */
final class TelegramOutboundDeliveryTriggerExecutionContextTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram outbound trigger execution-context safety requires MariaDB/MySQL.');
        }

        $this->migration()->up();
    }

    public function test_sql_mode_drift_is_rejected_before_effect_authority_can_arm(): void
    {
        $connection = DB::connection();
        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        self::assertTrue($surface->semanticsMatchExpected($connection));
        $baselineFingerprint = $surface->semanticFingerprint($connection);

        $session = $connection->selectOne('SELECT @@SESSION.sql_mode AS sql_mode', [], false);
        self::assertNotNull($session);
        $originalSqlMode = (string) ($session->sql_mode ?? '');
        $driftSqlMode = $this->toggleSqlMode($originalSqlMode, 'NO_BACKSLASH_ESCAPES');

        try {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_capability_delete_guard');
            DB::statement('SET SESSION sql_mode = ?', [$driftSqlMode]);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_delivery_capability_delete_guard
BEFORE DELETE ON telegram_delivery_authority_capability
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery database capability is immutable.';
END
SQL);
            DB::statement('SET SESSION sql_mode = ?', [$originalSqlMode]);

            self::assertSame($baselineFingerprint, $surface->semanticFingerprint($connection));
            self::assertFalse($surface->semanticsMatchExpected($connection));

            $effectCallbackRan = false;
            try {
                $connection->transaction(function (Connection $transaction) use (&$effectCallbackRan): void {
                    (new TelegramDeliveryDatabaseCapability)->runEffect(
                        $transaction,
                        'telegram_delivery_effect_v1',
                        'execution-context-review-probe',
                        1,
                        function () use (&$effectCallbackRan): int {
                            $effectCallbackRan = true;

                            return 1;
                        },
                    );
                });
                self::fail('Execution-context drift must reject effect authority before its callback can run.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString(
                    'Telegram delivery database authority is not fully activated.',
                    $exception->getMessage(),
                );
            }
            self::assertFalse($effectCallbackRan);
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$originalSqlMode]);
            $this->restoreCapabilityGuards();
        }

        self::assertTrue($surface->semanticsMatchExpected($connection));
    }

    public function test_unexpected_foreign_key_is_rejected_before_effect_authority_can_arm(): void
    {
        $connection = DB::connection();
        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        self::assertTrue($surface->semanticsMatchExpected($connection));
        $baselineFingerprint = $surface->semanticFingerprint($connection);
        $foreignKeyCreated = false;

        DB::statement(<<<'SQL'
CREATE TABLE telegram_delivery_foreign_key_probe (
    id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
SQL);

        try {
            DB::statement(<<<'SQL'
ALTER TABLE telegram_delivery_operations
ADD CONSTRAINT telegram_delivery_operations_outbox_fk_probe
FOREIGN KEY (outbox_event_id)
REFERENCES telegram_delivery_foreign_key_probe (id)
ON UPDATE CASCADE
ON DELETE CASCADE
SQL);
            $foreignKeyCreated = true;

            self::assertTrue($connection->table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $connection->getDatabaseName())
                ->where('TABLE_NAME', 'telegram_delivery_operations')
                ->where('CONSTRAINT_NAME', 'telegram_delivery_operations_outbox_fk_probe')
                ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
                ->exists());
            self::assertSame($baselineFingerprint, $surface->semanticFingerprint($connection));
            self::assertFalse($surface->semanticsMatchExpected($connection));

            $effectCallbackRan = false;
            try {
                $connection->transaction(function (Connection $transaction) use (&$effectCallbackRan): void {
                    (new TelegramDeliveryDatabaseCapability)->runEffect(
                        $transaction,
                        'telegram_delivery_effect_v1',
                        'referential-constraint-review-probe',
                        1,
                        function () use (&$effectCallbackRan): int {
                            $effectCallbackRan = true;

                            return 1;
                        },
                    );
                });
                self::fail('Unexpected referential constraints must reject effect authority before its callback can run.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString(
                    'Telegram delivery database authority is not fully activated.',
                    $exception->getMessage(),
                );
            }
            self::assertFalse($effectCallbackRan);
        } finally {
            if ($foreignKeyCreated) {
                DB::statement('ALTER TABLE telegram_delivery_operations DROP FOREIGN KEY telegram_delivery_operations_outbox_fk_probe');
            }
            DB::statement('DROP TABLE IF EXISTS telegram_delivery_foreign_key_probe');
        }

        self::assertSame($baselineFingerprint, $surface->semanticFingerprint($connection));
        self::assertTrue($surface->semanticsMatchExpected($connection));
    }

    public function test_incoming_foreign_key_is_rejected_before_effect_authority_can_arm(): void
    {
        $connection = DB::connection();
        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        self::assertTrue($surface->semanticsMatchExpected($connection));
        $baselineFingerprint = $surface->semanticFingerprint($connection);

        try {
            DB::statement(<<<'SQL'
CREATE TABLE telegram_delivery_reverse_foreign_key_probe (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_public_id CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    PRIMARY KEY (id),
    KEY telegram_delivery_reverse_fk_operation_idx (operation_public_id),
    CONSTRAINT telegram_delivery_reverse_fk_operation_fk
        FOREIGN KEY (operation_public_id)
        REFERENCES telegram_delivery_operations (public_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
SQL);

            self::assertTrue($connection->table('information_schema.KEY_COLUMN_USAGE')
                ->where('CONSTRAINT_SCHEMA', $connection->getDatabaseName())
                ->where('TABLE_NAME', 'telegram_delivery_reverse_foreign_key_probe')
                ->where('CONSTRAINT_NAME', 'telegram_delivery_reverse_fk_operation_fk')
                ->where('REFERENCED_TABLE_SCHEMA', $connection->getDatabaseName())
                ->where('REFERENCED_TABLE_NAME', 'telegram_delivery_operations')
                ->where('REFERENCED_COLUMN_NAME', 'public_id')
                ->exists());
            self::assertSame($baselineFingerprint, $surface->semanticFingerprint($connection));
            self::assertFalse($surface->semanticsMatchExpected($connection));

            $effectCallbackRan = false;
            try {
                $connection->transaction(function (Connection $transaction) use (&$effectCallbackRan): void {
                    (new TelegramDeliveryDatabaseCapability)->runEffect(
                        $transaction,
                        'telegram_delivery_effect_v1',
                        'incoming-referential-constraint-review-probe',
                        1,
                        function () use (&$effectCallbackRan): int {
                            $effectCallbackRan = true;

                            return 1;
                        },
                    );
                });
                self::fail('Incoming referential constraints must reject effect authority before its callback can run.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString(
                    'Telegram delivery database authority is not fully activated.',
                    $exception->getMessage(),
                );
            }
            self::assertFalse($effectCallbackRan);
        } finally {
            DB::statement('DROP TABLE IF EXISTS telegram_delivery_reverse_foreign_key_probe');
        }

        self::assertSame($baselineFingerprint, $surface->semanticFingerprint($connection));
        self::assertTrue($surface->semanticsMatchExpected($connection));
    }

    public function test_rollback_preflights_incoming_foreign_keys_before_guard_removal(): void
    {
        $connection = DB::connection();
        $migration = $this->migration();
        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        $guardsBefore = $surface->presentRequiredTriggers($connection);
        sort($guardsBefore, SORT_STRING);

        DB::statement(<<<'SQL'
CREATE TABLE telegram_delivery_rollback_fk_probe (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_public_id CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    PRIMARY KEY (id),
    KEY telegram_delivery_rollback_fk_operation_idx (operation_public_id),
    CONSTRAINT telegram_delivery_rollback_fk_operation_fk
        FOREIGN KEY (operation_public_id)
        REFERENCES telegram_delivery_operations (public_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
SQL);

        try {
            try {
                $migration->down();
                self::fail('Rollback must fail before guard removal when an incoming FK changes the activated authority surface.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString(
                    'complete activated authority surface is attested before guard removal',
                    $exception->getMessage(),
                );
            }

            self::assertTrue($connection->getSchemaBuilder()->hasTable('telegram_delivery_operations'));
            self::assertTrue($connection->getSchemaBuilder()->hasTable('telegram_delivery_authority_capability'));
            $guardsAfterFailure = $surface->presentRequiredTriggers($connection);
            sort($guardsAfterFailure, SORT_STRING);
            self::assertSame($guardsBefore, $guardsAfterFailure);
        } finally {
            DB::statement('DROP TABLE IF EXISTS telegram_delivery_rollback_fk_probe');
        }

        $migration->down();
        self::assertFalse($connection->getSchemaBuilder()->hasTable('telegram_delivery_operations'));
        self::assertFalse($connection->getSchemaBuilder()->hasTable('telegram_delivery_authority_capability'));

        $migration->up();
        self::assertTrue($surface->semanticsMatchExpected($connection));
    }

    private function toggleSqlMode(string $sqlMode, string $mode): string
    {
        $modes = array_values(array_filter(
            array_map(static fn (string $value): string => trim($value), explode(',', $sqlMode)),
            static fn (string $value): bool => $value !== '',
        ));

        $index = array_search($mode, $modes, true);
        if ($index === false) {
            $modes[] = $mode;
        } else {
            unset($modes[$index]);
            $modes = array_values($modes);
        }

        return implode(',', $modes);
    }

    private function restoreCapabilityGuards(): void
    {
        $migration = $this->migration();
        (new ReflectionClass($migration))->getMethod('installCapabilityGuards')->invoke($migration);
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php');

        return $migration;
    }
}

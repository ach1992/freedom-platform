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

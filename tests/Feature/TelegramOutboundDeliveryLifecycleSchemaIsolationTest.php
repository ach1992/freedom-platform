<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement DAT-003 SEC-008 OPS-003 QUA-004 */
final class TelegramOutboundDeliveryLifecycleSchemaIsolationTest extends TestCase
{
    public function test_lifecycle_principal_cannot_inherit_database_wildcard_neighbor_authority(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram lifecycle schema-isolation regression requires MariaDB/MySQL.');
        }

        $lifecycle = app(DatabaseManager::class)->connection('telegram_lifecycle');
        $grants = $lifecycle->select('SHOW GRANTS FOR CURRENT_USER', [], false);
        $grantText = implode("\n", array_map(
            static fn (object $row): string => implode(' ', array_map('strval', array_values((array) $row))),
            $grants,
        ));

        self::assertStringContainsString('freedom\\_platform\\_ci', $grantText);
        self::assertStringNotContainsString('`freedom_platform_ci`.*', $grantText);

        try {
            $lifecycle->select(
                'SELECT `id` FROM `freedomXplatformYci`.`telegram_lifecycle_scope_probe` LIMIT 1',
                [],
                false,
            );
            self::fail('The lifecycle principal must not inherit authority through database-name wildcard matching.');
        } catch (QueryException $exception) {
            self::assertContains((int) ($exception->errorInfo[1] ?? 0), [1044, 1142]);
        }
    }
}

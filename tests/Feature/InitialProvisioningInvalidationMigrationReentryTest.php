<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement PAY-002 PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-008 QUA-004 */
final class InitialProvisioningInvalidationMigrationReentryTest extends TestCase
{
    use DatabaseTruncation;

    public function test_financial_invalidation_migration_repairs_partial_trigger_state_and_is_reentrant(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_14_001163_harden_provisioning_financial_invalidation.php');

        try {
            foreach ([
                'service_subscriptions_financial_invalidation_guard',
                'provisioning_operations_financial_invalidation_guard',
                'orders_provisioning_invalidation_guard',
                'provisioning_financial_invalidations_update_guard',
                'provisioning_financial_invalidations_delete_guard',
                'purchase_refunds_provisioning_invalidation',
                'payment_intents_provisioning_invalidation_guard',
            ] as $trigger) {
                DB::unprepared('DROP TRIGGER IF EXISTS `'.$trigger.'`');
            }

            $migration->up();
            $migration->up();

            self::assertTrue(DB::getSchemaBuilder()->hasTable('provisioning_financial_invalidations'));
            foreach ([
                'service_subscriptions_financial_invalidation_guard',
                'provisioning_operations_financial_invalidation_guard',
                'orders_provisioning_invalidation_guard',
                'provisioning_financial_invalidations_update_guard',
                'provisioning_financial_invalidations_delete_guard',
                'purchase_refunds_provisioning_invalidation',
                'payment_intents_provisioning_invalidation_guard',
            ] as $trigger) {
                self::assertSame(1, $this->triggerCount($trigger), $trigger);
            }
        } finally {
            $migration->up();
        }
    }

    private function triggerCount(string $trigger): int
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );

        return $row === null ? 0 : (int) $row->aggregate;
    }
}

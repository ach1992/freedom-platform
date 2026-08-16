<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement BUY-001 BUY-002 PAY-002 DAT-002 DAT-003 DAT-004 QUA-004 */
final class PurchaseOrderMigrationAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pre_payment_order_migrations_leave_only_split_shape_constraints(): void
    {
        foreach ([
            '2026_08_17_000099_preserve_unimplemented_order_source_fence',
            '2026_08_17_000100_reconcile_pre_payment_order_authority',
            '2026_08_17_000101_split_pre_payment_order_shape_constraints',
            '2026_08_17_000102_harden_purchase_order_insert_lifecycle_authority',
        ] as $migration) {
            self::assertTrue(
                DB::table('migrations')->where('migration', $migration)->exists(),
                "Expected migration {$migration} to be recorded as executed.",
            );
        }

        $constraints = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'orders')
            ->whereIn('CONSTRAINT_NAME', [
                'orders_purchase_shape_chk',
                'orders_purchase_quote_identity_chk',
                'orders_purchase_financial_shape_chk',
                'orders_purchase_captured_shape_chk',
            ])
            ->pluck('CONSTRAINT_NAME')
            ->all();

        self::assertNotContains('orders_purchase_shape_chk', $constraints);
        self::assertContains('orders_purchase_quote_identity_chk', $constraints);
        self::assertContains('orders_purchase_financial_shape_chk', $constraints);
        self::assertContains('orders_purchase_captured_shape_chk', $constraints);
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORDER_ITEM_SUPPORT_INDEX = 'provisioning_operations_order_item_fk_idx';

    private const SERVICE_SUBSCRIPTION_SUPPORT_INDEX = 'provisioning_operations_service_subscription_fk_idx';

    /** @requirement PRV-001 DAT-003 QUA-004 */
    public function up(): void
    {
        if (! Schema::hasTable('provisioning_operations')
            || ! Schema::hasColumn('provisioning_operations', 'order_item_id')
            || ! Schema::hasColumn('provisioning_operations', 'service_subscription_id')) {
            throw new RuntimeException('Provisioning Operation foreign-key authority must exist before Service mutation migration support is installed.');
        }

        if (! $this->indexExists(self::ORDER_ITEM_SUPPORT_INDEX)) {
            DB::statement('ALTER TABLE provisioning_operations ADD INDEX provisioning_operations_order_item_fk_idx (order_item_id)');
        }
        if (! $this->indexExists(self::SERVICE_SUBSCRIPTION_SUPPORT_INDEX)) {
            DB::statement('ALTER TABLE provisioning_operations ADD INDEX provisioning_operations_service_subscription_fk_idx (service_subscription_id)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('provisioning_operations')) {
            return;
        }

        $this->dropSupportIndexWhenAnotherLeftmostIndexExists(
            self::ORDER_ITEM_SUPPORT_INDEX,
            'order_item_id',
        );
        $this->dropSupportIndexWhenAnotherLeftmostIndexExists(
            self::SERVICE_SUBSCRIPTION_SUPPORT_INDEX,
            'service_subscription_id',
        );
    }

    private function dropSupportIndexWhenAnotherLeftmostIndexExists(string $index, string $column): void
    {
        if (! $this->indexExists($index)) {
            return;
        }

        if (! $this->alternateLeftmostIndexExists($index, $column)) {
            // MariaDB requires a leftmost index for the foreign key. Keeping this non-authoritative
            // support index is safer than weakening or dropping the existing FK on rollback.
            return;
        }

        DB::statement(match ($index) {
            self::ORDER_ITEM_SUPPORT_INDEX => 'ALTER TABLE provisioning_operations DROP INDEX provisioning_operations_order_item_fk_idx',
            self::SERVICE_SUBSCRIPTION_SUPPORT_INDEX => 'ALTER TABLE provisioning_operations DROP INDEX provisioning_operations_service_subscription_fk_idx',
        });
    }

    private function indexExists(string $index): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['provisioning_operations', $index],
        );

        return $row !== null && (int) $row->aggregate > 0;
    }

    private function alternateLeftmostIndexExists(string $excludedIndex, string $column): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1 AND INDEX_NAME <> ?',
            ['provisioning_operations', $column, $excludedIndex],
        );

        return $row !== null && (int) $row->aggregate > 0;
    }
};

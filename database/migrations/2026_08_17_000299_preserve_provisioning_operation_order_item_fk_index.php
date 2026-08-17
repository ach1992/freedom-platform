<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SUPPORT_INDEX = 'provisioning_operations_order_item_fk_idx';

    /** @requirement PRV-001 DAT-003 QUA-004 */
    public function up(): void
    {
        if (! Schema::hasTable('provisioning_operations')
            || ! Schema::hasColumn('provisioning_operations', 'order_item_id')) {
            throw new RuntimeException('Provisioning Operation order-item authority must exist before Service mutation migration support is installed.');
        }

        if (! $this->indexExists(self::SUPPORT_INDEX)) {
            DB::statement('ALTER TABLE provisioning_operations ADD INDEX provisioning_operations_order_item_fk_idx (order_item_id)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('provisioning_operations') || ! $this->indexExists(self::SUPPORT_INDEX)) {
            return;
        }

        if (! $this->indexExists('provisioning_operations_item_type_unique')) {
            throw new RuntimeException('Cannot remove the Provisioning Operation order-item FK support index while Service mutation authority is active.');
        }

        DB::statement('ALTER TABLE provisioning_operations DROP INDEX provisioning_operations_order_item_fk_idx');
    }

    private function indexExists(string $index): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['provisioning_operations', $index],
        );

        return $row !== null && (int) $row->aggregate > 0;
    }
};

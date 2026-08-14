<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement C2C-002 DAT-003 QUA-004 */
    public function up(): void
    {
        DB::statement('ALTER TABLE c2c_destination_accounts DROP CONSTRAINT c2c_destination_adjustment_chk');
        DB::statement(<<<'SQL'
ALTER TABLE c2c_destination_accounts ADD CONSTRAINT c2c_destination_adjustment_chk CHECK (
    (`adjustment_enabled` = 0 AND `adjustment_min_irr` = 0 AND `adjustment_max_irr` = 0)
    OR (`adjustment_enabled` = 1
        AND `adjustment_min_irr` >= 0
        AND `adjustment_max_irr` >= `adjustment_min_irr`
        AND `adjustment_max_irr` <= 999999
        AND (`adjustment_max_irr` - `adjustment_min_irr` + 1) <= 10000)
)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE c2c_destination_accounts DROP CONSTRAINT c2c_destination_adjustment_chk');
        DB::statement('ALTER TABLE c2c_destination_accounts ADD CONSTRAINT c2c_destination_adjustment_chk CHECK ((`adjustment_enabled` = 0 AND `adjustment_min_irr` = 0 AND `adjustment_max_irr` = 0) OR (`adjustment_enabled` = 1 AND `adjustment_min_irr` >= 0 AND `adjustment_max_irr` >= `adjustment_min_irr` AND `adjustment_max_irr` <= 999999))');
    }
};

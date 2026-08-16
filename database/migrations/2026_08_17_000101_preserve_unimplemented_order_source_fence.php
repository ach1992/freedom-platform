<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement BUY-001 BUY-002 DAT-003 SEC-002 QUA-004 */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_unimplemented_source_insert_guard
BEFORE INSERT ON orders
FOR EACH ROW
BEGIN
    IF NEW.source_type <> 'purchase' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order source type has no active creation authority.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS orders_unimplemented_source_insert_guard');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement C2C-003 C2C-004 DAT-002 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_bank_transactions_insert_guard
BEFORE INSERT ON c2c_bank_transactions
FOR EACH ROW
BEGIN
    DECLARE destination_count INT DEFAULT 0;

    IF NEW.status <> 'pending' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C bank transaction must start pending before event-backed status authority.';
    END IF;
    SELECT COUNT(*) INTO destination_count
    FROM c2c_destination_accounts
    WHERE id = NEW.c2c_destination_account_id;
    IF destination_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C bank transaction destination authority is invalid.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_bank_transactions_insert_guard');
    }
};

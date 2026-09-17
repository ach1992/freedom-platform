<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SUP-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        if (! Schema::hasTable('support_ticket_ratings')) {
            Schema::create('support_ticket_ratings', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('ticket_id');
                $table->unsignedBigInteger('requester_user_id');
                $table->unsignedTinyInteger('score');
                $table->dateTime('created_at', 6);

                $table->foreign('requester_user_id', 'support_ticket_rating_requester_fk')
                    ->references('id')->on('users')->restrictOnDelete();
                $table->unique('ticket_id', 'support_ticket_rating_ticket_unique');
                $table->index(['requester_user_id', 'created_at'], 'support_ticket_rating_requester_created_idx');
            });
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->addCheckIfMissing('support_ticket_rating_score_chk', <<<'SQL'
ALTER TABLE support_ticket_ratings
ADD CONSTRAINT support_ticket_rating_score_chk CHECK (`score` BETWEEN 1 AND 5)
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_ticket_ratings_insert_guard
BEFORE INSERT ON support_ticket_ratings
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM support_tickets
        WHERE id = NEW.ticket_id
          AND requester_user_id = NEW.requester_user_id
          AND state = 'closed'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket rating requires the closed ticket requester.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_ticket_ratings_update_guard
BEFORE UPDATE ON support_ticket_ratings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket ratings are append-only.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_ticket_ratings_delete_guard
BEFORE DELETE ON support_ticket_ratings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket ratings cannot be deleted.';
END
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('support_ticket_ratings')) {
            return;
        }
        if (DB::table('support_ticket_ratings')->exists()) {
            throw new RuntimeException('Cannot roll back Support ticket rating authority after durable ratings exist.');
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS support_ticket_ratings_delete_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS support_ticket_ratings_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS support_ticket_ratings_insert_guard');
        }
        Schema::drop('support_ticket_ratings');
    }

    private function addCheckIfMissing(string $constraintName, string $ddl): void
    {
        if ($this->checkConstraintExists($constraintName)) {
            return;
        }

        DB::statement($ddl);
    }

    private function checkConstraintExists(string $constraintName): bool
    {
        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = 'support_ticket_ratings'
  AND CONSTRAINT_NAME = ?
  AND CONSTRAINT_TYPE = 'CHECK'
SQL, [$constraintName]);

        return (int) ($row->aggregate ?? 0) === 1;
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement IPG-002 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        if (! Schema::hasTable('purchase_provider_mutation_attempts')) {
            Schema::create('purchase_provider_mutation_attempts', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique();
                $table->unsignedBigInteger('payment_intent_id');
                $table->ulid('payment_intent_public_id');
                $table->string('provider_code', 64);
                $table->string('mutation_key', 191);
                $table->unsignedBigInteger('provider_session_id');
                $table->unsignedSmallInteger('slot');
                $table->string('state', 32);
                $table->dateTime('prepared_at', 6);
                $table->dateTime('external_started_at', 6)->nullable();
                $table->dateTime('resolved_at', 6)->nullable();
                $table->dateTime('updated_at', 6);
                $table->index(
                    ['payment_intent_id', 'payment_intent_public_id', 'state'],
                    'purchase_provider_mutation_intent_state_idx',
                );
                $table->index(['state', 'updated_at'], 'purchase_provider_mutation_state_idx');
            });
        }

        $shape = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'purchase_provider_mutation_attempts'
  AND COLUMN_NAME IN (
      'id','public_id','payment_intent_id','payment_intent_public_id','provider_code',
      'mutation_key','provider_session_id','slot','state','prepared_at',
      'external_started_at','resolved_at','updated_at'
  )
SQL);
        if ($shape === null || (int) $shape->aggregate !== 13) {
            throw new RuntimeException('Provider mutation attempt authority table is partially applied.');
        }

        $this->ensureCheckConstraint(
            'purchase_provider_mutation_slot_chk',
            'CHECK (`slot` < 128)',
        );
        $this->ensureCheckConstraint(
            'purchase_provider_mutation_state_chk',
            "CHECK (`state` IN ('prepared','external_started','completed','aborted','reconciliation_required'))",
        );
        $this->ensureCheckConstraint(
            'purchase_provider_mutation_timestamps_chk',
            <<<'SQL'
CHECK (
    (`state` = 'prepared' AND `external_started_at` IS NULL AND `resolved_at` IS NULL)
    OR (`state` = 'external_started' AND `external_started_at` IS NOT NULL AND `resolved_at` IS NULL)
    OR (`state` = 'completed' AND `external_started_at` IS NOT NULL AND `resolved_at` IS NOT NULL)
    OR (`state` = 'reconciliation_required' AND `external_started_at` IS NOT NULL AND `resolved_at` IS NULL)
    OR (`state` = 'aborted' AND `external_started_at` IS NULL AND `resolved_at` IS NOT NULL)
)
SQL,
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER purchase_provider_mutation_attempts_insert_guard
BEFORE INSERT ON purchase_provider_mutation_attempts
FOR EACH ROW
BEGIN
    DECLARE lock_owner BIGINT DEFAULT NULL;

    -- The provider-session barrier validates the exact PaymentIntent identity before
    -- this separately committed authority is inserted. Do not re-read that row here:
    -- callers may legitimately sit inside a wider application/test transaction whose
    -- PaymentIntent is not yet visible to this independent durability connection.
    SET lock_owner = IS_USED_LOCK(
        CONCAT(
            'purchase-provider:',
            LEFT(LOWER(SHA2(DATABASE(), 256)), 16),
            ':',
            LPAD(CAST(NEW.slot AS CHAR), 3, '0')
        )
    );
    IF lock_owner IS NULL OR lock_owner <> NEW.provider_session_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provider mutation attempt requires the exact live named-lock owner.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER purchase_provider_mutation_attempts_update_guard
BEFORE UPDATE ON purchase_provider_mutation_attempts
FOR EACH ROW
BEGIN
    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NOT (NEW.payment_intent_public_id <=> OLD.payment_intent_public_id)
       OR NOT (NEW.provider_code <=> OLD.provider_code)
       OR NOT (NEW.mutation_key <=> OLD.mutation_key)
       OR NOT (NEW.provider_session_id <=> OLD.provider_session_id)
       OR NOT (NEW.slot <=> OLD.slot)
       OR NOT (NEW.prepared_at <=> OLD.prepared_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provider mutation attempt identity is immutable.';
    END IF;

    IF NOT (
        (OLD.state = 'prepared' AND NEW.state IN ('external_started','aborted'))
        OR (OLD.state = 'external_started' AND NEW.state IN ('completed','reconciliation_required'))
        OR (OLD.state = 'reconciliation_required' AND NEW.state = 'completed')
        OR (OLD.state = NEW.state)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provider mutation attempt state transition is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER purchase_provider_mutation_attempts_delete_guard
BEFORE DELETE ON purchase_provider_mutation_attempts
FOR EACH ROW
BEGIN
    IF OLD.state NOT IN ('completed','aborted') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unresolved provider mutation attempts are durable reconciliation authority.';
    END IF;
END
SQL);
    }

    private function ensureCheckConstraint(string $name, string $definition): void
    {
        $exists = DB::selectOne(
            <<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = 'purchase_provider_mutation_attempts'
  AND CONSTRAINT_NAME = ?
  AND CONSTRAINT_TYPE = 'CHECK'
SQL,
            [$name],
        );
        if ($exists !== null && (int) $exists->aggregate === 1) {
            return;
        }
        if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
            throw new RuntimeException('Provider mutation attempt constraint name is invalid.');
        }

        DB::statement(
            'ALTER TABLE purchase_provider_mutation_attempts ADD CONSTRAINT `'.$name.'` '.$definition,
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_provider_mutation_attempts')) {
            return;
        }

        if (DB::table('purchase_provider_mutation_attempts')
            ->whereIn('state', ['prepared', 'external_started', 'reconciliation_required'])
            ->exists()) {
            throw new RuntimeException('Cannot roll back provider mutation attempt authority while unresolved attempts exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS purchase_provider_mutation_attempts_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_provider_mutation_attempts_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_provider_mutation_attempts_insert_guard');
        Schema::drop('purchase_provider_mutation_attempts');
    }
};

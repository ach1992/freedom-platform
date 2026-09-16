<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LOCK_NAME = 'freedom.support_ticket_foundation_v1';

    /** @var list<string> */
    private const TABLES = [
        'support_ticket_categories',
        'support_tickets',
        'support_ticket_messages',
        'support_ticket_state_histories',
    ];

    /** @requirement SUP-001 SUP-002 DAT-003 QUA-001 QUA-004 */
    public function up(): void
    {
        $this->withInstallationLock(function (): void {
            $this->assertRecognizedPartialSurface();
            $this->createTables();
            $this->ensureConstraints();
            $this->createGuards();

            if (! $this->isReady()) {
                throw new RuntimeException('Support ticket foundation did not reach its exact database surface.');
            }
        });
    }

    public function down(): void
    {
        $this->withInstallationLock(function (): void {
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'mysql') {
                $this->dropGuards();
                Schema::dropIfExists('support_ticket_state_histories');
                Schema::dropIfExists('support_ticket_messages');
                Schema::dropIfExists('support_tickets');
                Schema::dropIfExists('support_ticket_categories');

                return;
            }

            $this->rollbackMysql($connection);
        });
    }

    /**
     * Each destructive boundary is protected by an explicit InnoDB WRITE fence
     * over every Support table that still exists. The fence drains entered DML,
     * excludes later DML/DDL, then repeats durable-row and dependency attestation
     * immediately before dropping exactly one child-first table. Surviving tables
     * retain their triggers; an interrupted/dependency-blocked rollback is therefore
     * fail-closed and can be repaired to the exact ready surface before retry.
     *
     * @param  null|Closure(string):void  $afterFinalPreflight
     * @param  null|Closure(string):void  $afterDrop
     * @param  null|Closure():void  $afterInitialPreflight
     */
    private function rollbackMysql(
        Connection $connection,
        ?Closure $afterFinalPreflight = null,
        ?Closure $afterDrop = null,
        ?Closure $afterInitialPreflight = null,
    ): void {
        $this->prepareRollbackSurface();

        if ($afterInitialPreflight !== null) {
            $afterInitialPreflight();
        }

        $this->assertRollbackFenceLockingPrerequisites($connection);

        foreach (array_reverse(self::TABLES) as $table) {
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $this->dropTableWithRollbackWriteFence(
                $connection,
                $table,
                $afterFinalPreflight,
                $afterDrop,
            );
        }
    }

    private function prepareRollbackSurface(): void
    {
        $this->assertRecognizedPartialSurface();
        $this->assertRollbackSafe();

        $present = 0;
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $present++;
            }
        }
        if ($present === 0) {
            return;
        }

        if (! $this->isReady()) {
            // A previous child-first DROP may have committed before a later
            // dependency/interruption stopped rollback. Empty recognized partial
            // surfaces are safe to rebuild; no durable evidence is overwritten.
            $this->createTables();
            $this->ensureConstraints();
            $this->createGuards();
        }

        if (! $this->isReady()) {
            throw new RuntimeException('Support ticket rollback cannot repair the recognized partial surface to exact readiness.');
        }

        $this->assertNoUnexpectedIncomingForeignKeys();
    }

    /**
     * @param  null|Closure(string):void  $afterFinalPreflight
     * @param  null|Closure(string):void  $afterDrop
     */
    private function dropTableWithRollbackWriteFence(
        Connection $connection,
        string $table,
        ?Closure $afterFinalPreflight = null,
        ?Closure $afterDrop = null,
    ): void {
        if (! in_array($table, self::TABLES, true)) {
            throw new RuntimeException('Unsupported Support rollback write-fence table.');
        }
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Support rollback write fence requires no active runtime transaction.');
        }

        $existingTables = array_values(array_filter(
            self::TABLES,
            static fn (string $candidate): bool => $connection->getSchemaBuilder()->hasTable($candidate),
        ));
        if (! in_array($table, $existingTables, true)) {
            return;
        }

        $this->assertRollbackFenceLockingPrerequisites($connection);
        $autocommit = $connection->selectOne('SELECT @@SESSION.autocommit AS autocommit', [], false);
        if ($autocommit === null) {
            throw new RuntimeException('Support rollback write fence could not read autocommit state.');
        }
        $autocommitValue = (int) ($autocommit->autocommit ?? -1);
        if (! in_array($autocommitValue, [0, 1], true)) {
            throw new RuntimeException('Support rollback write fence found an invalid autocommit state.');
        }
        $restoreAutocommit = $autocommitValue === 1;

        $lockSql = 'LOCK TABLES '.implode(', ', array_map(
            static fn (string $candidate): string => '`'.$candidate.'` WRITE',
            $existingTables,
        ));
        $locked = false;
        try {
            if ($restoreAutocommit) {
                $connection->statement('SET autocommit = 0');
            }
            $connection->statement($lockSql);
            $locked = true;

            $this->assertRollbackSafeForLockedTables($connection, $existingTables);
            $this->assertNoUnexpectedIncomingForeignKeys($connection);

            if ($afterFinalPreflight !== null) {
                $afterFinalPreflight($table);
            }

            $this->assertRollbackSafeForLockedTables($connection, $existingTables);
            $this->assertNoUnexpectedIncomingForeignKeys($connection);
            $dropSql = match ($table) {
                'support_ticket_state_histories' => 'DROP TABLE `support_ticket_state_histories`',
                'support_ticket_messages' => 'DROP TABLE `support_ticket_messages`',
                'support_tickets' => 'DROP TABLE `support_tickets`',
                'support_ticket_categories' => 'DROP TABLE `support_ticket_categories`',
            };
            $connection->statement($dropSql);

            if ($afterDrop !== null) {
                $afterDrop($table);
            }
        } finally {
            if ($locked) {
                try {
                    $connection->statement('UNLOCK TABLES');
                } catch (Throwable $exception) {
                    $this->disconnect($connection);
                    throw new RuntimeException('Support rollback write-fence lock cleanup failed.', 0, $exception);
                }
            }

            if ($restoreAutocommit) {
                try {
                    $connection->statement('SET autocommit = 1');
                } catch (Throwable $exception) {
                    $this->disconnect($connection);
                    throw new RuntimeException('Support rollback write-fence autocommit restoration failed.', 0, $exception);
                }
            }
        }
    }

    /** @param list<string> $tables */
    private function assertRollbackSafeForLockedTables(Connection $connection, array $tables): void
    {
        foreach ($tables as $table) {
            if ($connection->table($table)->exists()) {
                throw new RuntimeException("Support ticket foundation cannot be removed while {$table} contains durable rows.");
            }
        }
    }

    private function assertRollbackFenceLockingPrerequisites(Connection $connection): void
    {
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Support rollback write fence requires no active runtime transaction.');
        }

        $locking = $connection->selectOne(
            'SELECT @@SESSION.innodb_table_locks AS innodb_table_locks',
            [],
            false,
        );
        if ($locking === null || (int) ($locking->innodb_table_locks ?? -1) !== 1) {
            throw new RuntimeException('Support rollback write fence requires @@SESSION.innodb_table_locks = 1.');
        }

        $wsrepRows = $connection->select("SHOW SESSION VARIABLES LIKE 'wsrep_on'", [], false);
        if (count($wsrepRows) > 1) {
            throw new RuntimeException('Support rollback write fence found ambiguous Galera/wsrep state.');
        }
        if ($wsrepRows !== []) {
            $wsrepOn = strtoupper(trim((string) ($wsrepRows[0]->Value ?? '')));
            if (! in_array($wsrepOn, ['OFF', '0'], true)) {
                if (! in_array($wsrepOn, ['ON', '1'], true)) {
                    throw new RuntimeException('Support rollback write fence found an invalid Galera/wsrep state.');
                }

                throw new RuntimeException('Support rollback write fence is not supported while Galera/wsrep is enabled.');
            }
        }

        $providerRows = $connection->select("SHOW GLOBAL VARIABLES LIKE 'wsrep_provider'", [], false);
        if (count($providerRows) > 1) {
            throw new RuntimeException('Support rollback write fence found ambiguous Galera provider state.');
        }
        if ($providerRows !== []) {
            $provider = strtolower(trim((string) ($providerRows[0]->Value ?? '')));
            if (! in_array($provider, ['', 'none'], true)) {
                throw new RuntimeException('Support rollback write fence is not supported with a loaded Galera provider.');
            }
        }
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('support_ticket_categories')) {
            Schema::create('support_ticket_categories', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('code', 64)->unique();
                $table->string('name_fa', 191);
                $table->string('name_en', 191);
                $table->string('route_role_code', 191)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);
                $table->index(['is_active', 'sort_order'], 'support_ticket_category_active_sort_idx');
            });
        }

        if (! Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('tracking_number', 20)->unique();
                $table->unsignedBigInteger('requester_user_id');
                $table->unsignedBigInteger('category_id');
                $table->string('state', 32);
                $table->unsignedBigInteger('state_version');
                $table->string('priority', 16)->default('normal');
                $table->unsignedBigInteger('assigned_user_id')->nullable();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->unsignedBigInteger('payment_intent_id')->nullable();
                $table->unsignedBigInteger('service_subscription_id')->nullable();
                $table->string('title', 200);
                $table->string('close_reason', 500)->nullable();
                $table->dateTime('resolved_at', 6)->nullable();
                $table->dateTime('closed_at', 6)->nullable();
                $table->dateTime('reopen_until', 6)->nullable();
                $table->unsignedBigInteger('last_transition_actor_user_id');
                $table->string('last_transition_reason_code', 64);
                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);

                $table->foreign('requester_user_id', 'support_ticket_requester_fk')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('category_id', 'support_ticket_category_fk')->references('id')->on('support_ticket_categories')->restrictOnDelete();
                $table->foreign('assigned_user_id', 'support_ticket_assignee_fk')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('order_id', 'support_ticket_order_fk')->references('id')->on('orders')->restrictOnDelete();
                $table->foreign('payment_intent_id', 'support_ticket_payment_intent_fk')->references('id')->on('payment_intents')->restrictOnDelete();
                $table->foreign('service_subscription_id', 'support_ticket_service_subscription_fk')->references('id')->on('service_subscriptions')->restrictOnDelete();
                $table->foreign('last_transition_actor_user_id', 'support_ticket_transition_actor_fk')->references('id')->on('users')->restrictOnDelete();

                $table->index(['requester_user_id', 'created_at'], 'support_ticket_requester_created_idx');
                $table->index(['state', 'priority', 'created_at'], 'support_ticket_queue_idx');
                $table->index(['assigned_user_id', 'state', 'created_at'], 'support_ticket_assignee_state_idx');
                $table->index('order_id', 'support_ticket_order_idx');
                $table->index('payment_intent_id', 'support_ticket_payment_idx');
                $table->index('service_subscription_id', 'support_ticket_service_idx');
            });
        }

        if (! Schema::hasTable('support_ticket_messages')) {
            Schema::create('support_ticket_messages', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('ticket_id');
                $table->unsignedBigInteger('actor_user_id');
                $table->string('kind', 32);
                $table->text('body');
                $table->string('idempotency_key', 128);
                $table->boolean('customer_visible');
                $table->dateTime('created_at', 6);
                $table->foreign('ticket_id', 'support_ticket_message_ticket_fk')->references('id')->on('support_tickets')->restrictOnDelete();
                $table->foreign('actor_user_id', 'support_ticket_message_actor_fk')->references('id')->on('users')->restrictOnDelete();
                $table->unique(['ticket_id', 'idempotency_key'], 'support_ticket_message_idempotency_unique');
                $table->index(['ticket_id', 'created_at'], 'support_ticket_message_history_idx');
            });
        }

        if (! Schema::hasTable('support_ticket_state_histories')) {
            Schema::create('support_ticket_state_histories', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('ticket_id');
                $table->string('from_state', 32)->nullable();
                $table->string('to_state', 32);
                $table->unsignedBigInteger('from_version')->nullable();
                $table->unsignedBigInteger('to_version');
                $table->unsignedBigInteger('actor_user_id');
                $table->string('reason_code', 64);
                $table->dateTime('created_at', 6);
                $table->foreign('ticket_id', 'support_ticket_history_ticket_fk')->references('id')->on('support_tickets')->restrictOnDelete();
                $table->foreign('actor_user_id', 'support_ticket_history_actor_fk')->references('id')->on('users')->restrictOnDelete();
                $table->unique(['ticket_id', 'to_version'], 'support_ticket_state_history_version_unique');
                $table->index(['ticket_id', 'created_at'], 'support_ticket_state_history_idx');
            });
        }
    }

    private function ensureConstraints(): void
    {
        $this->ensureConstraint('support_tickets', 'support_ticket_state_chk', "CHECK (`state` IN ('new','awaiting_support','awaiting_customer','investigating','resolved','closed'))");
        $this->ensureConstraint('support_tickets', 'support_ticket_state_version_chk', 'CHECK (`state_version` >= 1)');
        $this->ensureConstraint('support_tickets', 'support_ticket_priority_chk', "CHECK (`priority` IN ('low','normal','high','urgent'))");
        $this->ensureConstraint('support_tickets', 'support_ticket_tracking_chk', 'CHECK (CHAR_LENGTH(`tracking_number`) = 20)');
        $this->ensureConstraint('support_tickets', 'support_ticket_transition_reason_chk', "CHECK (`last_transition_reason_code` REGEXP '^[a-z][a-z0-9_.-]{1,63}$')");
        $this->ensureConstraint('support_ticket_messages', 'support_ticket_message_kind_chk', "CHECK (`kind` IN ('customer_reply','support_reply','internal_note'))");
        $this->ensureConstraint('support_ticket_state_histories', 'support_ticket_history_to_state_chk', "CHECK (`to_state` IN ('new','awaiting_support','awaiting_customer','investigating','resolved','closed'))");
        $this->ensureConstraint('support_ticket_state_histories', 'support_ticket_history_from_state_chk', "CHECK (`from_state` IS NULL OR `from_state` IN ('new','awaiting_support','awaiting_customer','investigating','resolved','closed'))");
        $this->ensureConstraint('support_ticket_state_histories', 'support_ticket_history_version_chk', 'CHECK (`to_version` >= 1 AND (`from_version` IS NULL OR `from_version` >= 1))');
    }

    private function createGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_tickets_insert_guard
BEFORE INSERT ON support_tickets
FOR EACH ROW
BEGIN
    IF NEW.state <> 'new'
       OR NEW.state_version <> 1
       OR NEW.last_transition_actor_user_id <> NEW.requester_user_id
       OR NEW.last_transition_reason_code <> 'ticket_created'
       OR NEW.close_reason IS NOT NULL
       OR NEW.resolved_at IS NOT NULL
       OR NEW.closed_at IS NOT NULL
       OR NEW.reopen_until IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket initial lifecycle shape is invalid.';
    END IF;

    IF NEW.order_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM orders WHERE id = NEW.order_id AND user_id = NEW.requester_user_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket Order reference is not owned by requester.';
    END IF;

    IF NEW.payment_intent_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM payment_intents WHERE id = NEW.payment_intent_id AND user_id = NEW.requester_user_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket Payment Intent reference is not owned by requester.';
    END IF;

    IF NEW.service_subscription_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM service_subscriptions WHERE id = NEW.service_subscription_id AND user_id = NEW.requester_user_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket Service reference is not owned by requester.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_tickets_update_guard
BEFORE UPDATE ON support_tickets
FOR EACH ROW
BEGIN
    IF NOT (NEW.tracking_number <=> OLD.tracking_number)
       OR NOT (NEW.requester_user_id <=> OLD.requester_user_id)
       OR NOT (NEW.category_id <=> OLD.category_id)
       OR NOT (NEW.order_id <=> OLD.order_id)
       OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NOT (NEW.service_subscription_id <=> OLD.service_subscription_id)
       OR NOT (NEW.title <=> OLD.title)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket durable identity is immutable.';
    END IF;

    IF NEW.updated_at < OLD.updated_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket updated_at cannot move backwards.';
    END IF;

    IF NEW.state = OLD.state THEN
        IF NEW.state_version <> OLD.state_version
           OR NEW.last_transition_actor_user_id <> OLD.last_transition_actor_user_id
           OR NEW.last_transition_reason_code <> OLD.last_transition_reason_code
           OR NOT (NEW.close_reason <=> OLD.close_reason)
           OR NOT (NEW.resolved_at <=> OLD.resolved_at)
           OR NOT (NEW.closed_at <=> OLD.closed_at)
           OR NOT (NEW.reopen_until <=> OLD.reopen_until) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket lifecycle metadata cannot change without a state transition.';
        END IF;
    ELSE
        IF NEW.state_version <> OLD.state_version + 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket transition must advance state version exactly once.';
        END IF;

        IF NEW.last_transition_reason_code NOT REGEXP '^[a-z][a-z0-9_.-]{1,63}$' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket transition reason is invalid.';
        END IF;

        IF NOT (
            (OLD.state = 'new' AND NEW.state IN ('awaiting_support','awaiting_customer','investigating','resolved','closed'))
            OR (OLD.state = 'awaiting_support' AND NEW.state IN ('awaiting_customer','investigating','resolved','closed'))
            OR (OLD.state = 'awaiting_customer' AND NEW.state IN ('awaiting_support','investigating','resolved','closed'))
            OR (OLD.state = 'investigating' AND NEW.state IN ('awaiting_support','awaiting_customer','resolved','closed'))
            OR (OLD.state = 'resolved' AND NEW.state IN ('awaiting_support','closed'))
            OR (OLD.state = 'closed' AND NEW.state = 'awaiting_support')
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket state transition is invalid.';
        END IF;

        IF OLD.state = 'closed'
           AND (OLD.reopen_until IS NULL OR CURRENT_TIMESTAMP(6) > OLD.reopen_until) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket reopen window has expired.';
        END IF;

        IF NEW.state = 'resolved' AND NEW.resolved_at IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Resolved support ticket requires resolved_at.';
        END IF;

        IF NEW.state NOT IN ('resolved','closed') AND NEW.resolved_at IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Non-terminal support ticket cannot retain resolved_at.';
        END IF;

        IF NEW.state = 'closed' THEN
            IF NEW.close_reason IS NULL
               OR CHAR_LENGTH(TRIM(NEW.close_reason)) = 0
               OR NEW.closed_at IS NULL
               OR NEW.reopen_until IS NULL
               OR NEW.reopen_until <= NEW.closed_at THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Closed support ticket lifecycle shape is invalid.';
            END IF;
        ELSEIF NEW.close_reason IS NOT NULL OR NEW.closed_at IS NOT NULL OR NEW.reopen_until IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Open support ticket cannot retain closure metadata.';
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_tickets_delete_guard
BEFORE DELETE ON support_tickets
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support tickets are durable and cannot be deleted.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_tickets_initial_history
AFTER INSERT ON support_tickets
FOR EACH ROW
BEGIN
    INSERT INTO support_ticket_state_histories (
        ticket_id, from_state, to_state, from_version, to_version,
        actor_user_id, reason_code, created_at
    ) VALUES (
        NEW.id, NULL, NEW.state, NULL, NEW.state_version,
        NEW.last_transition_actor_user_id, NEW.last_transition_reason_code, NEW.created_at
    );
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_tickets_transition_history
AFTER UPDATE ON support_tickets
FOR EACH ROW
BEGIN
    IF NEW.state <> OLD.state THEN
        INSERT INTO support_ticket_state_histories (
            ticket_id, from_state, to_state, from_version, to_version,
            actor_user_id, reason_code, created_at
        ) VALUES (
            NEW.id, OLD.state, NEW.state, OLD.state_version, NEW.state_version,
            NEW.last_transition_actor_user_id, NEW.last_transition_reason_code, NEW.updated_at
        );
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_ticket_messages_update_guard
BEFORE UPDATE ON support_ticket_messages
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket messages are append-only.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_ticket_messages_delete_guard
BEFORE DELETE ON support_ticket_messages
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket messages cannot be deleted.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_ticket_state_histories_insert_guard
BEFORE INSERT ON support_ticket_state_histories
FOR EACH ROW
BEGIN
    DECLARE valid_history_count INT DEFAULT 0;

    IF NEW.to_version = 1 THEN
        SELECT COUNT(*) INTO valid_history_count
        FROM support_tickets ticket_row
        WHERE ticket_row.id = NEW.ticket_id
          AND ticket_row.state = 'new'
          AND ticket_row.state_version = 1
          AND NEW.from_state IS NULL
          AND NEW.from_version IS NULL
          AND NEW.to_state = 'new'
          AND NEW.actor_user_id = ticket_row.requester_user_id
          AND NEW.actor_user_id = ticket_row.last_transition_actor_user_id
          AND NEW.reason_code = 'ticket_created'
          AND NEW.reason_code = ticket_row.last_transition_reason_code;
    ELSE
        SELECT COUNT(*) INTO valid_history_count
        FROM support_tickets ticket_row
        INNER JOIN support_ticket_state_histories previous_history
            ON previous_history.ticket_id = ticket_row.id
           AND previous_history.to_version = NEW.from_version
        WHERE ticket_row.id = NEW.ticket_id
          AND ticket_row.state = NEW.to_state
          AND ticket_row.state_version = NEW.to_version
          AND NEW.from_version = NEW.to_version - 1
          AND previous_history.to_state = NEW.from_state
          AND NEW.actor_user_id = ticket_row.last_transition_actor_user_id
          AND NEW.reason_code = ticket_row.last_transition_reason_code;
    END IF;

    IF valid_history_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket state history insertion is not authorized.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_ticket_state_histories_update_guard
BEFORE UPDATE ON support_ticket_state_histories
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket state history is append-only.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_ticket_state_histories_delete_guard
BEFORE DELETE ON support_ticket_state_histories
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket state history cannot be deleted.';
END
SQL);
    }

    private function dropGuards(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach ([
            'DROP TRIGGER IF EXISTS support_tickets_transition_history',
            'DROP TRIGGER IF EXISTS support_tickets_initial_history',
            'DROP TRIGGER IF EXISTS support_tickets_delete_guard',
            'DROP TRIGGER IF EXISTS support_tickets_update_guard',
            'DROP TRIGGER IF EXISTS support_tickets_insert_guard',
            'DROP TRIGGER IF EXISTS support_ticket_messages_update_guard',
            'DROP TRIGGER IF EXISTS support_ticket_messages_delete_guard',
            'DROP TRIGGER IF EXISTS support_ticket_state_histories_insert_guard',
            'DROP TRIGGER IF EXISTS support_ticket_state_histories_update_guard',
            'DROP TRIGGER IF EXISTS support_ticket_state_histories_delete_guard',
        ] as $statement) {
            DB::unprepared($statement);
        }
    }

    private function ensureConstraint(string $table, string $constraint, string $definition): void
    {
        if (! $this->constraintExists($table, $constraint)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` {$definition}");
        }
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function assertRecognizedPartialSurface(): void
    {
        $dependencies = [
            'support_tickets' => ['support_ticket_categories'],
            'support_ticket_messages' => ['support_tickets'],
            'support_ticket_state_histories' => ['support_tickets'],
        ];
        foreach ($dependencies as $table => $requiredTables) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($requiredTables as $requiredTable) {
                if (! Schema::hasTable($requiredTable)) {
                    throw new RuntimeException('Support ticket foundation contains an unrecognized partial table dependency state.');
                }
            }
        }

        foreach ($this->requiredColumns() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new RuntimeException("Support ticket foundation contains an unrecognized partial {$table} schema.");
                }
            }
        }
    }

    /** @return array<string,list<string>> */
    private function requiredColumns(): array
    {
        return [
            'support_ticket_categories' => ['id', 'code', 'name_fa', 'name_en', 'route_role_code', 'sort_order', 'is_active', 'created_at', 'updated_at'],
            'support_tickets' => ['id', 'tracking_number', 'requester_user_id', 'category_id', 'state', 'state_version', 'priority', 'assigned_user_id', 'order_id', 'payment_intent_id', 'service_subscription_id', 'title', 'close_reason', 'resolved_at', 'closed_at', 'reopen_until', 'last_transition_actor_user_id', 'last_transition_reason_code', 'created_at', 'updated_at'],
            'support_ticket_messages' => ['id', 'ticket_id', 'actor_user_id', 'kind', 'body', 'idempotency_key', 'customer_visible', 'created_at'],
            'support_ticket_state_histories' => ['id', 'ticket_id', 'from_state', 'to_state', 'from_version', 'to_version', 'actor_user_id', 'reason_code', 'created_at'],
        ];
    }

    private function isReady(): bool
    {
        foreach ($this->requiredColumns() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                return false;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return false;
                }
            }
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return true;
        }

        foreach ([
            ['support_tickets', 'support_ticket_state_chk'],
            ['support_tickets', 'support_ticket_state_version_chk'],
            ['support_tickets', 'support_ticket_priority_chk'],
            ['support_tickets', 'support_ticket_tracking_chk'],
            ['support_tickets', 'support_ticket_transition_reason_chk'],
            ['support_ticket_messages', 'support_ticket_message_kind_chk'],
            ['support_ticket_state_histories', 'support_ticket_history_to_state_chk'],
            ['support_ticket_state_histories', 'support_ticket_history_from_state_chk'],
            ['support_ticket_state_histories', 'support_ticket_history_version_chk'],
        ] as [$table, $constraint]) {
            if (! $this->constraintExists($table, $constraint)) {
                return false;
            }
        }

        if (! $this->referenceForeignKeysReady()) {
            return false;
        }

        $expectedTriggers = [
            'support_ticket_messages_delete_guard',
            'support_ticket_messages_update_guard',
            'support_ticket_state_histories_delete_guard',
            'support_ticket_state_histories_insert_guard',
            'support_ticket_state_histories_update_guard',
            'support_tickets_delete_guard',
            'support_tickets_initial_history',
            'support_tickets_insert_guard',
            'support_tickets_transition_history',
            'support_tickets_update_guard',
        ];
        sort($expectedTriggers);
        $actualTriggers = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('EVENT_OBJECT_TABLE', self::TABLES)
            ->pluck('TRIGGER_NAME')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();
        sort($actualTriggers, SORT_STRING);

        return $actualTriggers === $expectedTriggers;
    }

    private function referenceForeignKeysReady(): bool
    {
        $expected = [
            'support_ticket_assignee_fk' => ['support_tickets', 'assigned_user_id', 'users', 'id'],
            'support_ticket_category_fk' => ['support_tickets', 'category_id', 'support_ticket_categories', 'id'],
            'support_ticket_history_actor_fk' => ['support_ticket_state_histories', 'actor_user_id', 'users', 'id'],
            'support_ticket_history_ticket_fk' => ['support_ticket_state_histories', 'ticket_id', 'support_tickets', 'id'],
            'support_ticket_message_actor_fk' => ['support_ticket_messages', 'actor_user_id', 'users', 'id'],
            'support_ticket_message_ticket_fk' => ['support_ticket_messages', 'ticket_id', 'support_tickets', 'id'],
            'support_ticket_order_fk' => ['support_tickets', 'order_id', 'orders', 'id'],
            'support_ticket_payment_intent_fk' => ['support_tickets', 'payment_intent_id', 'payment_intents', 'id'],
            'support_ticket_requester_fk' => ['support_tickets', 'requester_user_id', 'users', 'id'],
            'support_ticket_service_subscription_fk' => ['support_tickets', 'service_subscription_id', 'service_subscriptions', 'id'],
            'support_ticket_transition_actor_fk' => ['support_tickets', 'last_transition_actor_user_id', 'users', 'id'],
        ];

        $rows = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('CONSTRAINT_NAME', array_keys($expected))
            ->get(['CONSTRAINT_NAME', 'TABLE_NAME', 'COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME']);
        $actual = [];
        foreach ($rows as $row) {
            $actual[(string) $row->CONSTRAINT_NAME] = [
                (string) $row->TABLE_NAME,
                (string) $row->COLUMN_NAME,
                (string) $row->REFERENCED_TABLE_NAME,
                (string) $row->REFERENCED_COLUMN_NAME,
            ];
        }
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);

        return $actual === $expected;
    }

    private function assertRollbackSafe(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Support ticket foundation cannot be removed while {$table} contains durable rows.");
            }
        }
    }

    private function assertNoUnexpectedIncomingForeignKeys(?Connection $connection = null): void
    {
        $connection ??= DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        $allowed = [
            'support_tickets|support_ticket_categories',
            'support_ticket_messages|support_tickets',
            'support_ticket_state_histories|support_tickets',
        ];
        $rows = $connection->table('information_schema.KEY_COLUMN_USAGE')
            ->where('REFERENCED_TABLE_SCHEMA', $connection->getDatabaseName())
            ->whereIn('REFERENCED_TABLE_NAME', self::TABLES)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->get(['TABLE_SCHEMA', 'TABLE_NAME', 'REFERENCED_TABLE_NAME']);
        foreach ($rows as $row) {
            $childSchema = (string) ($row->TABLE_SCHEMA ?? '');
            $edge = (string) $row->TABLE_NAME.'|'.(string) $row->REFERENCED_TABLE_NAME;
            if ($childSchema !== $connection->getDatabaseName() || ! in_array($edge, $allowed, true)) {
                throw new RuntimeException('Support ticket foundation has an unexpected incoming foreign-key dependency.');
            }
        }
    }

    private function withInstallationLock(callable $callback): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            $callback();

            return;
        }

        $row = $connection->selectOne('SELECT GET_LOCK(?, 15) AS acquired', [self::LOCK_NAME], false);
        if ($row === null || (int) ($row->acquired ?? 0) !== 1) {
            throw new RuntimeException('Support ticket foundation installation lock could not be acquired.');
        }

        $connection->setReconnector(static function (Connection $connection): never {
            throw new RuntimeException('Support ticket foundation database session was lost while the installation lock was held.');
        });

        try {
            try {
                $callback();
            } finally {
                try {
                    $released = $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME], false);
                } catch (Throwable $exception) {
                    $this->disconnect($connection);
                    throw new RuntimeException('Support ticket foundation installation lock cleanup failed.', 0, $exception);
                }

                if ($released === null || (int) ($released->released ?? 0) !== 1) {
                    $this->disconnect($connection);
                    throw new RuntimeException('Support ticket foundation installation lock cleanup failed.');
                }
            }
        } finally {
            $this->restoreDefaultReconnector($connection);
        }
    }

    private function restoreDefaultReconnector(Connection $connection): void
    {
        $database = app(DatabaseManager::class);
        $connection->setReconnector(static function (Connection $connection) use ($database): void {
            $name = $connection->getNameWithReadWriteType();
            if (! is_string($name) || $name === '') {
                throw new RuntimeException('Support ticket database connection name is unavailable for reconnect.');
            }

            $reconnected = $database->reconnect($name);
            if (! $reconnected instanceof Connection) {
                throw new RuntimeException('Support ticket database connection could not be restored.');
            }

            $connection->setPdo($reconnected->getRawPdo());
        });
    }

    private function disconnect(Connection $connection): void
    {
        try {
            $connection->disconnect();
        } catch (Throwable) {
            $connection->setPdo(null);
            $connection->setReadPdo(null);
            $connection->setDirectPdo(null);
        }
    }
};

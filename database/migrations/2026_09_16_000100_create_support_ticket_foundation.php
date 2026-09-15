<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SUP-001 SUP-002 DAT-003 QUA-001 */
    public function up(): void
    {
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

        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tracking_number', 20)->unique();
            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('support_ticket_categories')->restrictOnDelete();
            $table->string('state', 32);
            $table->string('priority', 16)->default('normal');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('payment_intent_id')->nullable();
            $table->unsignedBigInteger('service_subscription_id')->nullable();
            $table->string('title', 200);
            $table->string('close_reason', 500)->nullable();
            $table->dateTime('resolved_at', 6)->nullable();
            $table->dateTime('closed_at', 6)->nullable();
            $table->dateTime('reopen_until', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['requester_user_id', 'created_at'], 'support_ticket_requester_created_idx');
            $table->index(['state', 'priority', 'created_at'], 'support_ticket_queue_idx');
            $table->index(['assigned_user_id', 'state', 'created_at'], 'support_ticket_assignee_state_idx');
            $table->index('order_id', 'support_ticket_order_idx');
            $table->index('payment_intent_id', 'support_ticket_payment_idx');
            $table->index('service_subscription_id', 'support_ticket_service_idx');
        });

        Schema::create('support_ticket_messages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('ticket_id')->constrained('support_tickets')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 32);
            $table->text('body');
            $table->string('idempotency_key', 128);
            $table->boolean('customer_visible');
            $table->dateTime('created_at', 6);
            $table->unique(['ticket_id', 'idempotency_key'], 'support_ticket_message_idempotency_unique');
            $table->index(['ticket_id', 'created_at'], 'support_ticket_message_history_idx');
        });

        Schema::create('support_ticket_state_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('ticket_id')->constrained('support_tickets')->restrictOnDelete();
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->dateTime('created_at', 6);
            $table->index(['ticket_id', 'created_at'], 'support_ticket_state_history_idx');
        });

        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_ticket_state_chk CHECK (`state` IN ('new','awaiting_support','awaiting_customer','investigating','resolved','closed'))");
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_ticket_priority_chk CHECK (`priority` IN ('low','normal','high','urgent'))");
        DB::statement('ALTER TABLE support_tickets ADD CONSTRAINT support_ticket_tracking_chk CHECK (CHAR_LENGTH(`tracking_number`) = 20)');
        DB::statement("ALTER TABLE support_ticket_messages ADD CONSTRAINT support_ticket_message_kind_chk CHECK (`kind` IN ('customer_reply','support_reply','internal_note'))");
        DB::statement("ALTER TABLE support_ticket_state_histories ADD CONSTRAINT support_ticket_history_to_state_chk CHECK (`to_state` IN ('new','awaiting_support','awaiting_customer','investigating','resolved','closed'))");
        DB::statement("ALTER TABLE support_ticket_state_histories ADD CONSTRAINT support_ticket_history_from_state_chk CHECK (`from_state` IS NULL OR `from_state` IN ('new','awaiting_support','awaiting_customer','investigating','resolved','closed'))");

        $this->createImmutableHistoryGuards();
    }

    public function down(): void
    {
        if (Schema::hasTable('support_tickets') && DB::table('support_tickets')->exists()) {
            throw new RuntimeException('Support ticket foundation contains durable tickets and cannot be rolled back destructively.');
        }

        foreach ([
            'DROP TRIGGER IF EXISTS support_tickets_delete_guard',
            'DROP TRIGGER IF EXISTS support_ticket_messages_update_guard',
            'DROP TRIGGER IF EXISTS support_ticket_messages_delete_guard',
            'DROP TRIGGER IF EXISTS support_ticket_state_histories_update_guard',
            'DROP TRIGGER IF EXISTS support_ticket_state_histories_delete_guard',
        ] as $statement) {
            DB::unprepared($statement);
        }

        Schema::dropIfExists('support_ticket_state_histories');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('support_ticket_categories');
    }

    private function createImmutableHistoryGuards(): void
    {
        DB::unprepared(implode("\n", [
            'CREATE TRIGGER support_tickets_delete_guard',
            'BEFORE DELETE ON support_tickets',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support tickets are durable and cannot be deleted.';",
            'END',
        ]));

        foreach ([
            ['support_ticket_messages_update_guard', 'UPDATE', 'support_ticket_messages', 'Support ticket messages are append-only.'],
            ['support_ticket_messages_delete_guard', 'DELETE', 'support_ticket_messages', 'Support ticket messages cannot be deleted.'],
            ['support_ticket_state_histories_update_guard', 'UPDATE', 'support_ticket_state_histories', 'Support ticket state history is append-only.'],
            ['support_ticket_state_histories_delete_guard', 'DELETE', 'support_ticket_state_histories', 'Support ticket state history cannot be deleted.'],
        ] as [$name, $operation, $table, $message]) {
            DB::unprepared(implode("\n", [
                'CREATE TRIGGER '.$name,
                'BEFORE '.$operation.' ON '.$table,
                'FOR EACH ROW',
                'BEGIN',
                "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".$message."';",
                'END',
            ]));
        }
    }
};
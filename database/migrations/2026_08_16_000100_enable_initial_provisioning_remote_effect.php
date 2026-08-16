<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PAY-003 PRV-001 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        $this->extendProvisioningOperation();
        $this->extendServiceSubscription();
        $this->createRemoteEffectEvents();
        $this->createRemoteEffectMutationGuards();
        $this->createRemoteEffectEventGuards();
        $this->createRefundEffectFence();
    }

    public function down(): void
    {
        if (DB::table('provisioning_operations')->whereIn('state', ['running', 'succeeded', 'uncertain_remote_result', 'needs_review'])->exists()
            || (Schema::hasTable('provisioning_remote_effect_events') && DB::table('provisioning_remote_effect_events')->exists())
        ) {
            throw new RuntimeException('Cannot roll back initial provisioning remote-effect authority after execution evidence exists.');
        }

        $previousFinancialInvalidation = require __DIR__.'/2026_08_14_001163_harden_provisioning_financial_invalidation.php';
        if (! is_object($previousFinancialInvalidation) || ! method_exists($previousFinancialInvalidation, 'up')) {
            throw new RuntimeException('Previous provisioning financial invalidation migration is unavailable.');
        }
        $previousFinancialInvalidation->up();

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_update_guard
BEFORE UPDATE ON provisioning_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning Operation mutation is not enabled by current queue authority.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_update_guard
BEFORE UPDATE ON service_subscriptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription identity is immutable in current provisioning authority.';
END
SQL);

        Schema::dropIfExists('provisioning_remote_effect_events');

        Schema::table('service_subscriptions', function (Blueprint $table): void {
            foreach (['route_selection_id', 'service_target_id', 'remote_service_id', 'provisioned_at'] as $column) {
                if (Schema::hasColumn('service_subscriptions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('provisioning_operations', function (Blueprint $table): void {
            foreach ([
                'effect_fence_key', 'route_hold_expires_at', 'route_selection_id', 'service_target_id',
                'capacity_reservation_id', 'capacity_reservation_key', 'remote_username', 'target_reference',
                'attempt_count', 'last_result_code', 'last_result_message', 'remote_service_id',
                'remote_effect_started_at', 'remote_effect_completed_at',
            ] as $column) {
                if (Schema::hasColumn('provisioning_operations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function extendProvisioningOperation(): void
    {
        Schema::table('provisioning_operations', function (Blueprint $table): void {
            if (! Schema::hasColumn('provisioning_operations', 'effect_fence_key')) {
                $table->string('effect_fence_key', 64)->nullable()->after('correlation_id');
            }
            if (! Schema::hasColumn('provisioning_operations', 'route_hold_expires_at')) {
                $table->dateTime('route_hold_expires_at', 6)->nullable()->after('effect_fence_key');
            }
            if (! Schema::hasColumn('provisioning_operations', 'route_selection_id')) {
                $table->unsignedBigInteger('route_selection_id')->nullable()->after('route_hold_expires_at');
            }
            if (! Schema::hasColumn('provisioning_operations', 'service_target_id')) {
                $table->unsignedBigInteger('service_target_id')->nullable()->after('route_selection_id');
            }
            if (! Schema::hasColumn('provisioning_operations', 'capacity_reservation_id')) {
                $table->unsignedBigInteger('capacity_reservation_id')->nullable()->after('service_target_id');
            }
            if (! Schema::hasColumn('provisioning_operations', 'capacity_reservation_key')) {
                $table->string('capacity_reservation_key', 128)->nullable()->after('capacity_reservation_id');
            }
            if (! Schema::hasColumn('provisioning_operations', 'remote_username')) {
                $table->string('remote_username', 191)->nullable()->after('capacity_reservation_key');
            }
            if (! Schema::hasColumn('provisioning_operations', 'target_reference')) {
                $table->string('target_reference', 191)->nullable()->after('remote_username');
            }
            if (! Schema::hasColumn('provisioning_operations', 'attempt_count')) {
                $table->unsignedInteger('attempt_count')->default(0)->after('target_reference');
            }
            if (! Schema::hasColumn('provisioning_operations', 'last_result_code')) {
                $table->string('last_result_code', 64)->nullable()->after('attempt_count');
            }
            if (! Schema::hasColumn('provisioning_operations', 'last_result_message')) {
                $table->string('last_result_message', 512)->nullable()->after('last_result_code');
            }
            if (! Schema::hasColumn('provisioning_operations', 'remote_service_id')) {
                $table->string('remote_service_id', 191)->nullable()->after('last_result_message');
            }
            if (! Schema::hasColumn('provisioning_operations', 'remote_effect_started_at')) {
                $table->dateTime('remote_effect_started_at', 6)->nullable()->after('remote_service_id');
            }
            if (! Schema::hasColumn('provisioning_operations', 'remote_effect_completed_at')) {
                $table->dateTime('remote_effect_completed_at', 6)->nullable()->after('remote_effect_started_at');
            }
        });
    }

    private function extendServiceSubscription(): void
    {
        Schema::table('service_subscriptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('service_subscriptions', 'route_selection_id')) {
                $table->unsignedBigInteger('route_selection_id')->nullable()->after('creation_correlation_id');
            }
            if (! Schema::hasColumn('service_subscriptions', 'service_target_id')) {
                $table->unsignedBigInteger('service_target_id')->nullable()->after('route_selection_id');
            }
            if (! Schema::hasColumn('service_subscriptions', 'remote_service_id')) {
                $table->string('remote_service_id', 191)->nullable()->after('service_target_id');
            }
            if (! Schema::hasColumn('service_subscriptions', 'provisioned_at')) {
                $table->dateTime('provisioned_at', 6)->nullable()->after('remote_service_id');
            }
        });
    }

    private function createRemoteEffectEvents(): void
    {
        if (Schema::hasTable('provisioning_remote_effect_events')) {
            return;
        }

        Schema::create('provisioning_remote_effect_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('provisioning_operation_id')
                ->constrained('provisioning_operations', indexName: 'prov_remote_effect_operation_fk')
                ->restrictOnDelete();
            $table->string('event_type', 32);
            $table->unsignedBigInteger('state_version');
            $table->unsignedBigInteger('route_selection_id')->nullable();
            $table->unsignedBigInteger('service_target_id')->nullable();
            $table->string('remote_service_id', 191)->nullable();
            $table->string('result_code', 64)->nullable();
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(
                ['provisioning_operation_id', 'event_type', 'state_version'],
                'prov_remote_effect_event_unique',
            );
        });
    }

    private function createRemoteEffectMutationGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_update_guard
BEFORE UPDATE ON provisioning_operations
FOR EACH ROW
BEGIN
    DECLARE identity_unchanged BOOLEAN DEFAULT FALSE;
    DECLARE claim_transition BOOLEAN DEFAULT FALSE;
    DECLARE bind_transition BOOLEAN DEFAULT FALSE;
    DECLARE final_transition BOOLEAN DEFAULT FALSE;

    SET identity_unchanged =
        NEW.id = OLD.id
        AND BINARY NEW.public_id = BINARY OLD.public_id
        AND BINARY NEW.operation_key = BINARY OLD.operation_key
        AND BINARY NEW.operation_type = BINARY OLD.operation_type
        AND NEW.order_id = OLD.order_id
        AND NEW.order_item_id = OLD.order_item_id
        AND NEW.service_subscription_id = OLD.service_subscription_id
        AND NEW.user_id = OLD.user_id
        AND BINARY NEW.correlation_id = BINARY OLD.correlation_id
        AND NEW.created_at = OLD.created_at;

    IF COALESCE(@app_provisioning_authority, '') <> 'initial_remote_effect_v1'
       OR COALESCE(@app_provisioning_operation_key, '') <> OLD.operation_key
       OR COALESCE(@app_provisioning_correlation_id, '') <> OLD.correlation_id
       OR NOT identity_unchanged THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect mutation authority is invalid.';
    END IF;

    SET claim_transition =
        OLD.state IN ('queued', 'retry_scheduled')
        AND NEW.state = 'running'
        AND NEW.state_version = OLD.state_version + 1
        AND NEW.attempt_count = OLD.attempt_count + 1
        AND NEW.effect_fence_key IS NOT NULL
        AND (OLD.effect_fence_key IS NULL OR BINARY NEW.effect_fence_key = BINARY OLD.effect_fence_key)
        AND NEW.route_hold_expires_at IS NOT NULL
        AND (OLD.route_selection_id IS NULL OR NEW.route_hold_expires_at = OLD.route_hold_expires_at)
        AND (NEW.route_selection_id <=> OLD.route_selection_id)
        AND (NEW.service_target_id <=> OLD.service_target_id)
        AND (NEW.capacity_reservation_id <=> OLD.capacity_reservation_id)
        AND (NEW.capacity_reservation_key <=> OLD.capacity_reservation_key)
        AND (NEW.remote_username <=> OLD.remote_username)
        AND (NEW.target_reference <=> OLD.target_reference)
        AND (NEW.last_result_code <=> OLD.last_result_code)
        AND (NEW.last_result_message <=> OLD.last_result_message)
        AND (NEW.remote_service_id <=> OLD.remote_service_id)
        AND NEW.remote_effect_started_at IS NOT NULL
        AND (OLD.remote_effect_started_at IS NULL OR NEW.remote_effect_started_at = OLD.remote_effect_started_at)
        AND (NEW.remote_effect_completed_at <=> OLD.remote_effect_completed_at);

    SET bind_transition =
        OLD.state = 'running'
        AND NEW.state = 'running'
        AND NEW.state_version = OLD.state_version
        AND NEW.attempt_count = OLD.attempt_count
        AND BINARY NEW.effect_fence_key = BINARY OLD.effect_fence_key
        AND NEW.route_hold_expires_at = OLD.route_hold_expires_at
        AND NEW.route_selection_id IS NOT NULL
        AND NEW.service_target_id IS NOT NULL
        AND NEW.capacity_reservation_id IS NOT NULL
        AND NEW.capacity_reservation_key IS NOT NULL
        AND NEW.remote_username IS NOT NULL
        AND NEW.target_reference IS NOT NULL
        AND (OLD.route_selection_id IS NULL OR NEW.route_selection_id = OLD.route_selection_id)
        AND (OLD.service_target_id IS NULL OR NEW.service_target_id = OLD.service_target_id)
        AND (OLD.capacity_reservation_id IS NULL OR NEW.capacity_reservation_id = OLD.capacity_reservation_id)
        AND (OLD.capacity_reservation_key IS NULL OR BINARY NEW.capacity_reservation_key = BINARY OLD.capacity_reservation_key)
        AND (OLD.remote_username IS NULL OR BINARY NEW.remote_username = BINARY OLD.remote_username)
        AND (OLD.target_reference IS NULL OR BINARY NEW.target_reference = BINARY OLD.target_reference)
        AND (NEW.last_result_code <=> OLD.last_result_code)
        AND (NEW.last_result_message <=> OLD.last_result_message)
        AND (NEW.remote_service_id <=> OLD.remote_service_id)
        AND NEW.remote_effect_started_at = OLD.remote_effect_started_at
        AND (NEW.remote_effect_completed_at <=> OLD.remote_effect_completed_at);

    SET final_transition =
        OLD.state = 'running'
        AND NEW.state IN ('succeeded', 'retry_scheduled', 'uncertain_remote_result', 'needs_review', 'failed_final')
        AND NEW.state_version = OLD.state_version + 1
        AND NEW.attempt_count = OLD.attempt_count
        AND BINARY NEW.effect_fence_key = BINARY OLD.effect_fence_key
        AND NEW.route_hold_expires_at = OLD.route_hold_expires_at
        AND NEW.route_selection_id = OLD.route_selection_id
        AND NEW.service_target_id = OLD.service_target_id
        AND NEW.capacity_reservation_id = OLD.capacity_reservation_id
        AND BINARY NEW.capacity_reservation_key = BINARY OLD.capacity_reservation_key
        AND BINARY NEW.remote_username = BINARY OLD.remote_username
        AND BINARY NEW.target_reference = BINARY OLD.target_reference
        AND NEW.last_result_code IS NOT NULL
        AND NEW.remote_effect_started_at = OLD.remote_effect_started_at
        AND NEW.remote_effect_completed_at IS NOT NULL
        AND (NEW.state <> 'succeeded' OR NEW.remote_service_id IS NOT NULL);

    IF NOT claim_transition AND NOT bind_transition AND NOT final_transition THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect transition is not allowed.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_update_guard
BEFORE UPDATE ON service_subscriptions
FOR EACH ROW
BEGIN
    IF COALESCE(@app_provisioning_authority, '') <> 'initial_remote_effect_v1'
       OR COALESCE(@app_provisioning_operation_key, '') = ''
       OR COALESCE(@app_provisioning_correlation_id, '') <> OLD.creation_correlation_id
       OR NEW.id <> OLD.id
       OR BINARY NEW.public_id <> BINARY OLD.public_id
       OR NEW.order_id <> OLD.order_id
       OR NEW.order_item_id <> OLD.order_item_id
       OR NEW.user_id <> OLD.user_id
       OR BINARY NEW.creation_correlation_id <> BINARY OLD.creation_correlation_id
       OR NEW.created_at <> OLD.created_at
       OR NEW.route_selection_id IS NULL
       OR NEW.service_target_id IS NULL
       OR NEW.remote_service_id IS NULL
       OR NEW.provisioned_at IS NULL
       OR (OLD.route_selection_id IS NOT NULL AND NEW.route_selection_id <> OLD.route_selection_id)
       OR (OLD.service_target_id IS NOT NULL AND NEW.service_target_id <> OLD.service_target_id)
       OR (OLD.remote_service_id IS NOT NULL AND BINARY NEW.remote_service_id <> BINARY OLD.remote_service_id)
       OR (OLD.provisioned_at IS NOT NULL AND NEW.provisioned_at <> OLD.provisioned_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription remote binding mutation is not allowed.';
    END IF;
END
SQL);
    }

    private function createRemoteEffectEventGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_remote_effect_events_insert_guard
BEFORE INSERT ON provisioning_remote_effect_events
FOR EACH ROW
BEGIN
    DECLARE valid_operation_count INT DEFAULT 0;

    IF COALESCE(@app_provisioning_authority, '') <> 'initial_remote_effect_v1' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect event authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_operation_count
    FROM provisioning_operations operation_row
    WHERE operation_row.id = NEW.provisioning_operation_id
      AND BINARY operation_row.operation_key = BINARY COALESCE(@app_provisioning_operation_key, '')
      AND BINARY operation_row.correlation_id = BINARY COALESCE(@app_provisioning_correlation_id, '');

    IF valid_operation_count <> 1
       OR NEW.event_type NOT IN ('claimed', 'route_bound', 'succeeded', 'retry_scheduled', 'uncertain_remote_result', 'needs_review', 'failed_final') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect event is inconsistent.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_remote_effect_events_update_guard
BEFORE UPDATE ON provisioning_remote_effect_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect events are immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_remote_effect_events_delete_guard
BEFORE DELETE ON provisioning_remote_effect_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect events are non-deletable.';
END
SQL);
    }

    private function createRefundEffectFence(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER purchase_refunds_provisioning_invalidation
AFTER INSERT ON purchase_refunds
FOR EACH ROW
BEGIN
    DECLARE locked_order_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE running_operation_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE existing_invalidation_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE existing_purchase_refund_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_existing_refund_count INT DEFAULT 0;

    SELECT id INTO locked_order_id
    FROM orders
    WHERE purchase_settlement_id = NEW.purchase_settlement_id
      AND payment_intent_id = NEW.payment_intent_id
    LIMIT 1
    FOR UPDATE;

    IF locked_order_id IS NOT NULL THEN
        SELECT id INTO running_operation_id
        FROM provisioning_operations
        WHERE order_id = locked_order_id
          AND operation_type = 'initial_provision'
          AND state = 'running'
        LIMIT 1
        FOR UPDATE;

        IF running_operation_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning remote-effect fence is active; retry refund after reconciliation.';
        END IF;
    END IF;

    SELECT id, purchase_refund_id
    INTO existing_invalidation_id, existing_purchase_refund_id
    FROM provisioning_financial_invalidations
    WHERE purchase_settlement_id = NEW.purchase_settlement_id
      AND payment_intent_id = NEW.payment_intent_id
    LIMIT 1
    FOR UPDATE;

    IF existing_invalidation_id IS NOT NULL THEN
        SELECT COUNT(*) INTO valid_existing_refund_count
        FROM purchase_refunds
        WHERE id = existing_purchase_refund_id
          AND purchase_settlement_id = NEW.purchase_settlement_id
          AND payment_intent_id = NEW.payment_intent_id;

        IF valid_existing_refund_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing provisioning financial invalidation binding is inconsistent.';
        END IF;
    ELSE
        INSERT INTO provisioning_financial_invalidations (
            order_id, purchase_settlement_id, payment_intent_id, purchase_refund_id, created_at
        ) VALUES (
            locked_order_id, NEW.purchase_settlement_id, NEW.payment_intent_id, NEW.id, CURRENT_TIMESTAMP(6)
        );
    END IF;
END
SQL);
    }
};

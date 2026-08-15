<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-008 QUA-004 */
    public function up(): void
    {
        if (! Schema::hasTable('service_subscriptions')) {
            DB::statement(<<<'SQL'
CREATE TABLE service_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    creation_correlation_id VARCHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY service_subscriptions_public_id_unique (public_id),
    UNIQUE KEY service_subscriptions_order_item_id_unique (order_item_id),
    KEY service_subscriptions_order_created_idx (order_id, created_at),
    KEY service_subscriptions_user_created_idx (user_id, created_at),
    CONSTRAINT service_subscriptions_order_id_foreign FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
    CONSTRAINT service_subscriptions_order_item_id_foreign FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE RESTRICT,
    CONSTRAINT service_subscriptions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT service_subscriptions_bootstrap_block_chk CHECK (0 = 1)
) ENGINE=InnoDB
SQL);
        }

        if (! Schema::hasTable('provisioning_operations')) {
            DB::statement(<<<'SQL'
CREATE TABLE provisioning_operations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    operation_key VARCHAR(191) NOT NULL,
    operation_type VARCHAR(32) NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    service_subscription_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    state VARCHAR(32) NOT NULL,
    state_version BIGINT UNSIGNED NOT NULL,
    correlation_id VARCHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY provisioning_operations_public_id_unique (public_id),
    UNIQUE KEY provisioning_operations_operation_key_unique (operation_key),
    UNIQUE KEY provisioning_operations_item_type_unique (order_item_id, operation_type),
    KEY provisioning_operations_order_created_idx (order_id, created_at),
    KEY provisioning_operations_state_created_idx (state, created_at),
    CONSTRAINT provisioning_operations_order_id_foreign FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
    CONSTRAINT provisioning_operations_order_item_id_foreign FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE RESTRICT,
    CONSTRAINT provisioning_operations_service_subscription_id_foreign FOREIGN KEY (service_subscription_id) REFERENCES service_subscriptions (id) ON DELETE RESTRICT,
    CONSTRAINT provisioning_operations_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT provisioning_operations_bootstrap_block_chk CHECK (0 = 1)
) ENGINE=InnoDB
SQL);
        }

        if (! Schema::hasTable('provisioning_operation_histories')) {
            DB::statement(<<<'SQL'
CREATE TABLE provisioning_operation_histories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provisioning_operation_id BIGINT UNSIGNED NOT NULL,
    from_state VARCHAR(32) NULL,
    to_state VARCHAR(32) NOT NULL,
    from_version BIGINT UNSIGNED NULL,
    to_version BIGINT UNSIGNED NOT NULL,
    actor_type VARCHAR(16) NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    reason_code VARCHAR(64) NOT NULL,
    correlation_id VARCHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY provisioning_operation_history_version_unique (provisioning_operation_id, to_version),
    KEY provisioning_operation_history_created_idx (provisioning_operation_id, created_at),
    CONSTRAINT prov_op_hist_operation_fk FOREIGN KEY (provisioning_operation_id) REFERENCES provisioning_operations (id) ON DELETE RESTRICT,
    CONSTRAINT provisioning_operation_histories_bootstrap_block_chk CHECK (0 = 1)
) ENGINE=InnoDB
SQL);
        }
    }

    public function down(): void
    {
        foreach (['provisioning_operation_histories', 'provisioning_operations', 'service_subscriptions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Cannot roll back intrinsic provisioning bootstrap barriers while authority rows exist.');
            }
        }

        Schema::dropIfExists('provisioning_operation_histories');
        Schema::dropIfExists('provisioning_operations');
        Schema::dropIfExists('service_subscriptions');
    }
};

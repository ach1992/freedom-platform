CREATE TABLE service_delivery_effects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    service_delivery_attempt_id BIGINT UNSIGNED NOT NULL,
    service_subscription_id BIGINT UNSIGNED NOT NULL,
    telegram_account_id BIGINT UNSIGNED NOT NULL,
    telegram_bot_id BIGINT UNSIGNED NOT NULL,
    telegram_user_id BIGINT UNSIGNED NOT NULL,
    state VARCHAR(24) NOT NULL,
    state_version BIGINT UNSIGNED NOT NULL,
    provider_boundary_started_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    telegram_message_id BIGINT UNSIGNED NULL,
    result_code VARCHAR(64) NULL,
    retry_after_seconds INT UNSIGNED NULL,
    blocking_service_subscription_id BIGINT UNSIGNED AS (
        CASE
            WHEN state IN ('sending','uncertain') OR retry_after_seconds IS NOT NULL THEN service_subscription_id
            ELSE NULL
        END
    ) PERSISTENT,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY service_delivery_effects_public_id_unique (public_id),
    UNIQUE KEY service_delivery_effects_attempt_unique (service_delivery_attempt_id),
    UNIQUE KEY service_delivery_effects_blocking_service_unique (blocking_service_subscription_id),
    KEY service_delivery_effects_service_created_idx (service_subscription_id, created_at),
    KEY service_delivery_effects_state_updated_idx (state, updated_at),
    CONSTRAINT service_delivery_effects_telegram_account_fk FOREIGN KEY (telegram_account_id) REFERENCES telegram_accounts (id) ON DELETE RESTRICT,
    CONSTRAINT service_delivery_effects_public_id_chk CHECK (public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'),
    CONSTRAINT service_delivery_effects_state_chk CHECK (state IN ('prepared','sending','succeeded','uncertain','failed_final')),
    CONSTRAINT service_delivery_effects_state_version_chk CHECK (state_version >= 1),
    CONSTRAINT service_delivery_effects_telegram_bot_chk CHECK (telegram_bot_id >= 1),
    CONSTRAINT service_delivery_effects_telegram_user_chk CHECK (telegram_user_id >= 1),
    CONSTRAINT service_delivery_effects_message_chk CHECK (telegram_message_id IS NULL OR telegram_message_id >= 1),
    CONSTRAINT service_delivery_effects_result_chk CHECK (result_code IS NULL OR result_code REGEXP '^[a-z0-9_.:-]{1,64}$'),
    CONSTRAINT service_delivery_effects_retry_after_chk CHECK (retry_after_seconds IS NULL OR retry_after_seconds BETWEEN 1 AND 86400)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin

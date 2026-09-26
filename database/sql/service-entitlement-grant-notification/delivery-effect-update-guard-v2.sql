CREATE OR REPLACE TRIGGER service_delivery_notification_effect_update_capability_guard
BEFORE UPDATE ON service_delivery_effects
FOR EACH ROW
BEGIN
    DECLARE attempt_purpose VARCHAR(16) DEFAULT NULL;
    DECLARE valid_notification_freshness_count INT DEFAULT 0;

    SELECT purpose INTO attempt_purpose
    FROM service_delivery_attempts
    WHERE id = OLD.service_delivery_attempt_id
    LIMIT 1;

    IF attempt_purpose = 'notification' AND NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Notification delivery effect update requires the operational database capability.';
    END IF;

    IF attempt_purpose = 'notification'
       AND OLD.state = 'prepared'
       AND NEW.state = 'sending' THEN
        SELECT COUNT(*) INTO valid_notification_freshness_count
        FROM service_notification_delivery_bindings binding_row
        JOIN service_notification_states state_row ON state_row.id = binding_row.service_notification_state_id
        JOIN service_subscriptions service_row ON service_row.id = state_row.service_subscription_id
        WHERE binding_row.service_delivery_attempt_id = OLD.service_delivery_attempt_id
          AND state_row.state = 'triggered'
          AND (
              state_row.notification_type <> 'expiry'
              OR (
                  NEW.provider_boundary_started_at IS NOT NULL
                  AND EXISTS (
                      SELECT 1
                      FROM service_sync_snapshots snapshot_row
                      WHERE snapshot_row.service_subscription_id = state_row.service_subscription_id
                        AND snapshot_row.remote_disposition = 'present'
                        AND snapshot_row.remote_expires_at IS NOT NULL
                        AND snapshot_row.local_lifecycle_version = service_row.lifecycle_version
                        AND snapshot_row.local_remote_identity_generation = service_row.remote_identity_generation
                        AND snapshot_row.local_mutation_generation = service_row.mutation_generation
                        AND state_row.expiry_snapshot_max_age_seconds BETWEEN 60 AND 86400
                        AND snapshot_row.observed_at <= NEW.provider_boundary_started_at
                        AND snapshot_row.observed_at >= TIMESTAMPADD(SECOND, -state_row.expiry_snapshot_max_age_seconds, NEW.provider_boundary_started_at)
                        AND BINARY SHA2(CONCAT_WS('|',
                            'service-notification-expiry-cycle-v1',
                            service_row.id,
                            service_row.remote_identity_generation,
                            service_row.mutation_generation,
                            service_row.lifecycle_version,
                            DATE_FORMAT(snapshot_row.remote_expires_at, '%Y-%m-%d %H:%i:%s.%f')
                        ), 256) = BINARY state_row.cycle_key_hash
                        AND NOT EXISTS (
                            SELECT 1
                            FROM service_sync_snapshots newer_snapshot
                            WHERE newer_snapshot.service_subscription_id = snapshot_row.service_subscription_id
                              AND newer_snapshot.local_lifecycle_version = snapshot_row.local_lifecycle_version
                              AND newer_snapshot.local_remote_identity_generation = snapshot_row.local_remote_identity_generation
                              AND newer_snapshot.local_mutation_generation = snapshot_row.local_mutation_generation
                              AND (
                                  newer_snapshot.observed_at > snapshot_row.observed_at
                                  OR (newer_snapshot.observed_at = snapshot_row.observed_at AND newer_snapshot.id > snapshot_row.id)
                              )
                        )
                  )
              )
          );

        IF valid_notification_freshness_count = 0 THEN
            SELECT COUNT(*) INTO valid_notification_freshness_count
            FROM service_entitlement_grant_notification_bindings grant_binding
            JOIN service_entitlement_grant_items grant_item
              ON grant_item.id = grant_binding.service_entitlement_grant_item_id
            JOIN service_entitlement_grant_batches grant_batch
              ON grant_batch.id = grant_item.service_entitlement_grant_batch_id
            JOIN provisioning_operations grant_operation
              ON grant_operation.id = grant_item.provisioning_operation_id
            JOIN service_delivery_attempts grant_attempt
              ON grant_attempt.id = grant_binding.service_delivery_attempt_id
            JOIN service_subscriptions grant_service
              ON grant_service.id = grant_item.service_subscription_id
            WHERE grant_binding.service_delivery_attempt_id = OLD.service_delivery_attempt_id
              AND grant_item.state IN ('queued','succeeded')
              AND grant_batch.notify_customers = 1
              AND grant_operation.service_subscription_id = grant_item.service_subscription_id
              AND grant_operation.operation_type IN ('grant_data','grant_days','grant_data_days')
              AND grant_operation.state = 'succeeded'
              AND grant_operation.remote_effect_started_at IS NOT NULL
              AND grant_operation.remote_effect_completed_at IS NOT NULL
              AND grant_operation.last_result_code IS NOT NULL
              AND grant_attempt.service_subscription_id = grant_item.service_subscription_id
              AND BINARY grant_attempt.purpose = BINARY 'notification'
              AND grant_attempt.target_remote_identity_generation = grant_service.remote_identity_generation
              AND grant_attempt.target_lifecycle_version = grant_service.lifecycle_version
              AND grant_service.remote_deleted_at IS NULL
              AND grant_service.lifecycle_state IN ('active','suspended');
        END IF;

        IF valid_notification_freshness_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Notification delivery provider freshness authority is invalid.';
        END IF;
    END IF;
END

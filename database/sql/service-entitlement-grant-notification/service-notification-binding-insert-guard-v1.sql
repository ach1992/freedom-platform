CREATE OR REPLACE TRIGGER service_notification_bindings_insert_guard
BEFORE INSERT ON service_notification_delivery_bindings
FOR EACH ROW
BEGIN
    DECLARE valid_binding_count INT DEFAULT 0;
    DECLARE valid_freshness_count INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_notification_authority, '') <> 'service_notification_bind_v1'
       OR NEW.service_notification_state_id <> COALESCE(@app_service_notification_state_id, 0)
       OR NEW.service_delivery_attempt_id <> COALESCE(@app_service_notification_attempt_id, 0)
       OR NEW.retry_ordinal <> COALESCE(@app_service_notification_retry_ordinal, 65535)
       OR BINARY NEW.presentation_hash <> BINARY LOWER(SHA2(NEW.presentation_text, 256)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_binding_count
    FROM service_notification_states state_row
    JOIN service_delivery_attempts attempt_row ON attempt_row.id = NEW.service_delivery_attempt_id
    WHERE state_row.id = NEW.service_notification_state_id
      AND state_row.service_subscription_id = COALESCE(@app_service_notification_service_id, 0)
      AND state_row.state = 'triggered'
      AND state_row.latest_delivery_attempt_id = attempt_row.id
      AND state_row.latest_retry_ordinal = NEW.retry_ordinal
      AND attempt_row.service_subscription_id = state_row.service_subscription_id
      AND BINARY attempt_row.purpose = BINARY 'notification'
      AND BINARY attempt_row.correlation_id = BINARY COALESCE(@app_service_notification_correlation_id, '');

    IF valid_binding_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding must match the current notification Delivery Attempt.';
    END IF;

    SELECT COUNT(*) INTO valid_freshness_count
    FROM service_notification_states state_row
    JOIN service_subscriptions service_row ON service_row.id = state_row.service_subscription_id
    WHERE state_row.id = NEW.service_notification_state_id
      AND (
          state_row.notification_type <> 'expiry'
          OR EXISTS (
              SELECT 1
              FROM service_sync_snapshots snapshot_row
              WHERE snapshot_row.service_subscription_id = state_row.service_subscription_id
                AND snapshot_row.remote_disposition = 'present'
                AND snapshot_row.remote_expires_at IS NOT NULL
                AND snapshot_row.local_lifecycle_version = service_row.lifecycle_version
                AND snapshot_row.local_remote_identity_generation = service_row.remote_identity_generation
                AND snapshot_row.local_mutation_generation = service_row.mutation_generation
                AND state_row.expiry_snapshot_max_age_seconds BETWEEN 60 AND 86400
                AND snapshot_row.observed_at <= NEW.created_at
                AND snapshot_row.observed_at >= TIMESTAMPADD(SECOND, -state_row.expiry_snapshot_max_age_seconds, NEW.created_at)
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
      );

    IF valid_freshness_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding freshness authority is invalid.';
    END IF;
END

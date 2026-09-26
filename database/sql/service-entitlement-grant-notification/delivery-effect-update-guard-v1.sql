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

        IF valid_notification_freshness_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Notification delivery provider freshness authority is invalid.';
        END IF;
    END IF;
END

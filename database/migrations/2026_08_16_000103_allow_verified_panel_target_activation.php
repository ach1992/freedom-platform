<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement PRV-001 PRV-002 PRV-003 DAT-003 SEC-002 QUA-001 */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER panel_targets_insert_guard
BEFORE INSERT ON panel_service_targets
FOR EACH ROW
BEGIN
    IF NEW.state = 'active' AND NOT (
        NEW.capability_status = 'verified'
        AND NEW.capability_evidence_hash IS NOT NULL
        AND NEW.capability_verified_at IS NOT NULL
        AND NEW.verified_connection_version IS NOT NULL
        AND EXISTS (
            SELECT 1
            FROM panel_connections connection_row
            WHERE connection_row.id = NEW.panel_connection_id
              AND connection_row.state = 'active'
              AND connection_row.last_test_status = 'success'
              AND connection_row.last_tested_at IS NOT NULL
              AND connection_row.last_panel_version IS NOT NULL
              AND connection_row.last_capabilities_hash IS NOT NULL
              AND connection_row.version = NEW.verified_connection_version
        )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service target activation requires verified adapter evidence for the current active connection version.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER panel_targets_update_guard
BEFORE UPDATE ON panel_service_targets
FOR EACH ROW
BEGIN
    IF NEW.state = 'active' AND NOT (
        NEW.capability_status = 'verified'
        AND NEW.capability_evidence_hash IS NOT NULL
        AND NEW.capability_verified_at IS NOT NULL
        AND NEW.verified_connection_version IS NOT NULL
        AND EXISTS (
            SELECT 1
            FROM panel_connections connection_row
            WHERE connection_row.id = NEW.panel_connection_id
              AND connection_row.state = 'active'
              AND connection_row.last_test_status = 'success'
              AND connection_row.last_tested_at IS NOT NULL
              AND connection_row.last_panel_version IS NOT NULL
              AND connection_row.last_capabilities_hash IS NOT NULL
              AND connection_row.version = NEW.verified_connection_version
        )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service target activation requires verified adapter evidence for the current active connection version.';
    END IF;

    IF OLD.state IN ('active', 'maintenance') AND (
        NOT (OLD.panel_connection_id <=> NEW.panel_connection_id)
        OR NOT (OLD.kind <=> NEW.kind)
        OR NOT (OLD.encrypted_configuration <=> NEW.encrypted_configuration)
        OR NOT (OLD.configuration_hash <=> NEW.configuration_hash)
        OR NOT (OLD.configuration_key_version <=> NEW.configuration_key_version)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operational service target configuration is immutable.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER panel_targets_insert_guard
BEFORE INSERT ON panel_service_targets
FOR EACH ROW
BEGIN
    IF NEW.state = 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service target activation requires verified adapter evidence.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER panel_targets_update_guard
BEFORE UPDATE ON panel_service_targets
FOR EACH ROW
BEGIN
    IF NEW.state = 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service target activation requires verified adapter evidence.';
    END IF;

    IF OLD.state IN ('active', 'maintenance') AND (
        NOT (OLD.panel_connection_id <=> NEW.panel_connection_id)
        OR NOT (OLD.kind <=> NEW.kind)
        OR NOT (OLD.encrypted_configuration <=> NEW.encrypted_configuration)
        OR NOT (OLD.configuration_hash <=> NEW.configuration_hash)
        OR NOT (OLD.configuration_key_version <=> NEW.configuration_key_version)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operational service target configuration is immutable.';
    END IF;
END
SQL);
    }
};

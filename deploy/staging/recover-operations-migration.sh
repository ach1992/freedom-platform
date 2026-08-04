#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

STATE=/root/freedom-bootstrap/application-secrets.env
DATABASE=freedom_platform
OPERATIONS_MIGRATION=2026_08_03_000200_create_operations_foundation_tables
IDENTITY_MIGRATION=2026_08_04_000300_create_identity_access_foundation_tables

exec 9>/root/freedom-bootstrap/migration-recovery.lock
flock -w 300 9 || {
    echo 'Migration recovery lock timeout.' >&2
    exit 75
}

test -s "$STATE"
# shellcheck disable=SC1090
. "$STATE"

mariadb_ready=0
for _attempt in $(seq 1 60); do
    if mariadb-admin --protocol=socket ping --silent >/dev/null 2>&1; then
        mariadb_ready=1
        break
    fi
    sleep 1
done

test "$mariadb_ready" -eq 1

migration_table_exists=$(mariadb --protocol=socket -N -uroot information_schema -e \
    "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='$DATABASE' AND TABLE_NAME='migrations';")

if [ "$migration_table_exists" -eq 0 ]; then
    echo 'foundation_migration_recovery=not_required_no_migration_table'
    exit 0
fi

migration_is_recorded() {
    local migration=$1

    test "$(mariadb --protocol=socket -N -uroot "$DATABASE" -e \
        "SELECT COUNT(*) FROM migrations WHERE migration='$migration';")" -ne 0
}

recover_operations_migration() {
    if migration_is_recorded "$OPERATIONS_MIGRATION"; then
        echo 'operations_migration_recovery=not_required_recorded'
        return
    fi

    local partial_count
    partial_count=$(mariadb --protocol=socket -N -uroot information_schema -e \
        "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='$DATABASE' AND TABLE_NAME IN ('alerts','worker_heartbeats','scheduled_task_runs','audit_logs');")

    if [ "$partial_count" -eq 0 ]; then
        echo 'operations_migration_recovery=not_required_no_partial_tables'
        return
    fi

    mariadb --protocol=socket -uroot "$DATABASE" <<'SQL'
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS alerts;
DROP TABLE IF EXISTS worker_heartbeats;
DROP TABLE IF EXISTS scheduled_task_runs;
DROP TABLE IF EXISTS audit_logs;
SET FOREIGN_KEY_CHECKS=1;
SQL

    local remaining
    remaining=$(mariadb --protocol=socket -N -uroot information_schema -e \
        "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='$DATABASE' AND TABLE_NAME IN ('alerts','worker_heartbeats','scheduled_task_runs','audit_logs');")

    test "$remaining" -eq 0
    echo "operations_migration_recovery=dropped_partial_tables_$partial_count"
}

recover_identity_migration() {
    if migration_is_recorded "$IDENTITY_MIGRATION"; then
        echo 'identity_migration_recovery=not_required_recorded'
        return
    fi

    local partial_count
    partial_count=$(mariadb --protocol=socket -N -uroot information_schema -e \
        "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='$DATABASE' AND TABLE_NAME IN ('telegram_accounts','customer_tiers','customer_profiles','administrators','customer_status_histories','customer_tier_histories','customer_tags','customer_tag_assignments','phone_numbers','otp_challenges','roles','permissions','role_permissions','administrator_role_assignments','administrator_permission_overrides','sensitive_action_approvals','agent_applications','agent_application_histories','agent_profiles');")

    if [ "$partial_count" -eq 0 ]; then
        echo 'identity_migration_recovery=not_required_no_partial_tables'
        return
    fi

    mariadb --protocol=socket -uroot "$DATABASE" <<'SQL'
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS agent_profiles;
DROP TABLE IF EXISTS agent_application_histories;
DROP TABLE IF EXISTS agent_applications;
DROP TABLE IF EXISTS sensitive_action_approvals;
DROP TABLE IF EXISTS administrator_permission_overrides;
DROP TABLE IF EXISTS administrator_role_assignments;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS otp_challenges;
DROP TABLE IF EXISTS phone_numbers;
DROP TABLE IF EXISTS customer_tag_assignments;
DROP TABLE IF EXISTS customer_tags;
DROP TABLE IF EXISTS customer_tier_histories;
DROP TABLE IF EXISTS customer_status_histories;
DROP TABLE IF EXISTS administrators;
DROP TABLE IF EXISTS customer_profiles;
DROP TABLE IF EXISTS customer_tiers;
DROP TABLE IF EXISTS telegram_accounts;
SET FOREIGN_KEY_CHECKS=1;
SQL

    local remaining
    remaining=$(mariadb --protocol=socket -N -uroot information_schema -e \
        "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='$DATABASE' AND TABLE_NAME IN ('telegram_accounts','customer_tiers','customer_profiles','administrators','customer_status_histories','customer_tier_histories','customer_tags','customer_tag_assignments','phone_numbers','otp_challenges','roles','permissions','role_permissions','administrator_role_assignments','administrator_permission_overrides','sensitive_action_approvals','agent_applications','agent_application_histories','agent_profiles');")

    test "$remaining" -eq 0
    echo "identity_migration_recovery=dropped_partial_tables_$partial_count"
}

recover_operations_migration
recover_identity_migration

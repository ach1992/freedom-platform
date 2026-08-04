#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

STATE=/root/freedom-bootstrap/application-secrets.env
MIGRATION=2026_08_03_000200_create_operations_foundation_tables
DATABASE=freedom_platform

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
    echo 'operations_migration_recovery=not_required_no_migration_table'
    exit 0
fi

migration_recorded=$(mariadb --protocol=socket -N -uroot "$DATABASE" -e \
    "SELECT COUNT(*) FROM migrations WHERE migration='$MIGRATION';")

if [ "$migration_recorded" -ne 0 ]; then
    echo 'operations_migration_recovery=not_required_recorded'
    exit 0
fi

partial_count=$(mariadb --protocol=socket -N -uroot information_schema -e \
    "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='$DATABASE' AND TABLE_NAME IN ('alerts','worker_heartbeats','scheduled_task_runs','audit_logs');")

if [ "$partial_count" -eq 0 ]; then
    echo 'operations_migration_recovery=not_required_no_partial_tables'
    exit 0
fi

mariadb --protocol=socket -uroot "$DATABASE" <<'SQL'
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS alerts;
DROP TABLE IF EXISTS worker_heartbeats;
DROP TABLE IF EXISTS scheduled_task_runs;
DROP TABLE IF EXISTS audit_logs;
SET FOREIGN_KEY_CHECKS=1;
SQL

remaining=$(mariadb --protocol=socket -N -uroot information_schema -e \
    "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='$DATABASE' AND TABLE_NAME IN ('alerts','worker_heartbeats','scheduled_task_runs','audit_logs');")

test "$remaining" -eq 0
echo "operations_migration_recovery=dropped_partial_tables_$partial_count"

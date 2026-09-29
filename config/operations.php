<?php

declare(strict_types=1);

return [
    'worker_heartbeat' => [
        'enabled' => filter_var(env('WORKER_HEARTBEAT_ENABLED', false), FILTER_VALIDATE_BOOL),
        'worker_id' => env('WORKER_NAME'),
        'queue_group' => env(
            'WORKER_QUEUE_GROUP',
            'critical,default,provisioning,notifications,broadcast',
        ),
        'interval_seconds' => (int) env('WORKER_HEARTBEAT_INTERVAL_SECONDS', 30),
        'stale_after_seconds' => (int) env('WORKER_HEARTBEAT_STALE_AFTER_SECONDS', 480),
        'release_version' => env('APP_VERSION'),
    ],

    'backup' => [
        'enabled' => filter_var(env('BACKUP_ENABLED', false), FILTER_VALIDATE_BOOL),
        'root' => env('BACKUP_ROOT', storage_path('backups')),
        'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),
        'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
        'database_interval_minutes' => (int) env('BACKUP_DATABASE_INTERVAL_MINUTES', 10),
        'daily_time' => env('BACKUP_DAILY_TIME', '02:30'),
        'frequent_overlap_minutes' => (int) env('BACKUP_FREQUENT_OVERLAP_MINUTES', 30),
        'daily_overlap_minutes' => (int) env('BACKUP_DAILY_OVERLAP_MINUTES', 180),
        'dump_binary' => env('BACKUP_MARIADB_DUMP_BINARY', '/usr/bin/mariadb-dump'),
        'process_timeout_seconds' => (int) env('BACKUP_PROCESS_TIMEOUT_SECONDS', 900),
        'config_files' => [
            'environment' => base_path('.env'),
        ],
        'private_directories' => [
            'application' => storage_path('app/private'),
        ],
    ],
];

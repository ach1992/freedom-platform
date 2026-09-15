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
];

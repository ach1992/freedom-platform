<?php

declare(strict_types=1);

return [
    'interval_minutes' => (int) env('SERVICE_SYNC_INTERVAL_MINUTES', 5),
    'batch_limit' => (int) env('SERVICE_SYNC_BATCH_LIMIT', 50),
    'lease_seconds' => (int) env('SERVICE_SYNC_LEASE_SECONDS', 120),
    'severity' => [
        'missing_remote' => env('SERVICE_SYNC_SEVERITY_MISSING_REMOTE', 'critical'),
        'expired_local_active_remote' => env('SERVICE_SYNC_SEVERITY_EXPIRED_ACTIVE', 'warning'),
        'lifecycle_mismatch' => env('SERVICE_SYNC_SEVERITY_LIFECYCLE_MISMATCH', 'warning'),
        'remote_identity_mismatch' => env('SERVICE_SYNC_SEVERITY_IDENTITY_MISMATCH', 'critical'),
        'unexpected_entitlement' => env('SERVICE_SYNC_SEVERITY_UNEXPECTED_ENTITLEMENT', 'warning'),
    ],
];

<?php

declare(strict_types=1);

return [
    'lock_path' => env('INSTALLER_LOCK_PATH', storage_path('app/installer.lock')),
    'access_file' => env('INSTALLER_ACCESS_FILE', storage_path('app/installer/access.json')),
    'minimum_token_ttl_minutes' => 5,
    'maximum_token_ttl_minutes' => 60,
    'unlock_session_ttl_minutes' => 15,
];

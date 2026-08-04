<?php

declare(strict_types=1);

return [
    'lock_path' => env('INSTALLER_LOCK_PATH', storage_path('app/installer.lock')),
    'access_file' => env('INSTALLER_ACCESS_FILE', storage_path('app/installer/access.json')),
    'minimum_token_ttl_minutes' => 5,
    'maximum_token_ttl_minutes' => 60,
    'unlock_session_ttl_minutes' => 15,

    'php_runtimes' => [
        'cli_binary' => env('INSTALLER_PHP_CLI_BINARY', '/www/server/php/84/bin/php'),
        'lsphp_binary' => env('INSTALLER_PHP_LSPHP_BINARY', '/usr/local/lsws/lsphp84/bin/lsphp'),
        'required_extensions' => [
            'common' => [
                'bcmath',
                'curl',
                'dom',
                'fileinfo',
                'intl',
                'json',
                'mbstring',
                'openssl',
                'pdo',
                'pdo_mysql',
                'redis',
                'sodium',
                'tokenizer',
                'xml',
            ],
            'cli' => ['pcntl'],
            'lsphp' => [],
        ],
    ],

    'environment' => [
        'paths' => [
            storage_path(),
            base_path('bootstrap/cache'),
        ],
        'minimum_free_bytes' => (int) env('INSTALLER_MINIMUM_FREE_BYTES', 1_073_741_824),
        'expected_owner' => env('INSTALLER_EXPECTED_OWNER', 'www'),
        'expected_group' => env('INSTALLER_EXPECTED_GROUP', 'www'),
        'outbound_urls' => [
            'https://repo.packagist.org/packages.json',
            'https://api.telegram.org',
        ],
        'outbound_allowed_hosts' => [
            'repo.packagist.org',
            'api.telegram.org',
        ],
        'connect_timeout_seconds' => 3,
        'timeout_seconds' => 5,
    ],
];

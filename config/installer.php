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
];

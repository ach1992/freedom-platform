<?php

declare(strict_types=1);

return [
    'lock_path' => env('INSTALLER_LOCK_PATH', storage_path('app/installer.lock')),
    'access_file' => env('INSTALLER_ACCESS_FILE', storage_path('app/installer/access.json')),
    'bootstrap_journal_path' => env(
        'INSTALLER_BOOTSTRAP_JOURNAL_PATH',
        storage_path('app/installer/bootstrap-journal.json'),
    ),
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
                'gd',
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

    'finalization' => [
        'php_binary' => env('INSTALLER_PHP_CLI_BINARY', '/www/server/php/84/bin/php'),
        'artisan_path' => base_path('artisan'),
        'working_directory' => base_path(),
        'timeout_seconds' => (int) env('INSTALLER_FINALIZATION_TIMEOUT_SECONDS', 300),
    ],

    'environment' => [
        'file_path' => env('INSTALLER_ENVIRONMENT_PATH', base_path('.env')),
        'snapshot_path' => env(
            'INSTALLER_ENVIRONMENT_SNAPSHOT_PATH',
            storage_path('app/installer/environment.snapshot'),
        ),
        // Deployment-only secrets that may be transient process inputs but must
        // never contain a configured value in the persisted application .env.
        'non_persistable_keys' => [
            'TELEGRAM_LIFECYCLE_DB_PASSWORD',
        ],
        'allowed_keys' => [
            'APP_NAME',
            'APP_ENV',
            'APP_KEY',
            'APP_DEBUG',
            'APP_URL',
            'APP_VERSION',
            'APP_TIMEZONE',
            'BUSINESS_TIMEZONE',
            'APP_LOCALE',
            'APP_FALLBACK_LOCALE',
            'DB_CONNECTION',
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
            'TELEGRAM_METADATA_DB_URL',
            'TELEGRAM_METADATA_DB_USERNAME',
            'TELEGRAM_METADATA_DB_PASSWORD',
            'REDIS_CLIENT',
            'REDIS_HOST',
            'REDIS_PASSWORD',
            'REDIS_PORT',
            'REDIS_DB',
            'REDIS_CACHE_DB',
            'REDIS_QUEUE_RETRY_AFTER',
            'CACHE_STORE',
            'CACHE_PREFIX',
            'QUEUE_CONNECTION',
            'SESSION_DRIVER',
            'SESSION_ENCRYPT',
            'SESSION_SECURE_COOKIE',
            'TELEGRAM_BOT_TOKEN',
            'TELEGRAM_WEBHOOK_SECRET',
            'OWNER_TELEGRAM_ID',
            'REPORT_CHAT_ID',
        ],
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

<?php

declare(strict_types=1);

return [
    'title' => 'Secure installer',
    'token' => 'One-time installer token',
    'unlock' => 'Unlock installer',
    'invalid_token' => 'The installer token is invalid, expired, or already used.',
    'preflight' => 'Environment preflight',
    'passed' => 'Passed',
    'failed' => 'Failed',
    'enabled' => 'Enabled',
    'disabled' => 'Disabled',
    'none' => 'None',
    'not_available' => 'Not available',
    'php_runtimes' => 'PHP runtimes',
    'binary' => 'Binary',
    'version' => 'Version',
    'sapi' => 'SAPI',
    'ini_file' => 'Loaded php.ini',
    'timezone' => 'Timezone',
    'missing_extensions' => 'Missing required extensions',
    'disabled_functions' => 'Disabled functions',
    'opcache' => 'OPcache',
    'runtime_status' => 'Runtime status',
    'error_code' => 'Error code',
    'checks' => [
        'php_runtimes' => 'CLI PHP and LSPHP',
        'https' => 'HTTPS',
        'database' => 'Database connectivity',
        'redis' => 'Authenticated Redis connectivity',
        'storage' => 'Writable storage',
        'utc' => 'UTC application timezone',
    ],
    'runtime' => [
        'cli' => 'CLI PHP',
        'lsphp' => 'OpenLiteSpeed LSPHP',
    ],
];

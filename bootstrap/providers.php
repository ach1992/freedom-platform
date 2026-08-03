<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\FoundationServiceProvider;
use App\Modules\Installer\Infrastructure\InstallerServiceProvider;

return [
    AppServiceProvider::class,
    FoundationServiceProvider::class,
    InstallerServiceProvider::class,
];

<?php

declare(strict_types=1);

use App\Modules\Installer\Infrastructure\InstallerServiceProvider;
use App\Modules\Operations\Infrastructure\OperationsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FoundationServiceProvider;

return [
    AppServiceProvider::class,
    FoundationServiceProvider::class,
    InstallerServiceProvider::class,
    OperationsServiceProvider::class,
];

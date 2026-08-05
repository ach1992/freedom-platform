<?php

declare(strict_types=1);

use App\Modules\AccessControl\Infrastructure\AccessControlServiceProvider;
use App\Modules\Agents\Infrastructure\AgentsServiceProvider;
use App\Modules\Identity\Infrastructure\IdentityServiceProvider;
use App\Modules\Installer\Infrastructure\InstallerServiceProvider;
use App\Modules\Operations\Infrastructure\OperationsServiceProvider;
use App\Modules\Telegram\Infrastructure\TelegramServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FoundationServiceProvider;

return [
    AppServiceProvider::class,
    FoundationServiceProvider::class,
    AccessControlServiceProvider::class,
    AgentsServiceProvider::class,
    IdentityServiceProvider::class,
    InstallerServiceProvider::class,
    OperationsServiceProvider::class,
    TelegramServiceProvider::class,
];

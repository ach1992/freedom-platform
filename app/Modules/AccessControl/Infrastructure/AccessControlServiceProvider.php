<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Infrastructure;

use App\Modules\AccessControl\Application\AccessMutationAudit;
use App\Modules\AccessControl\Application\AdministratorAccessService;
use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\AccessControl\Application\OwnerTransferService;
use App\Modules\AccessControl\Application\SensitiveActionApprovalService;
use App\Modules\AccessControl\Application\SensitiveApprovalAudit;
use App\Modules\AccessControl\Domain\PermissionResolver;
use App\Shared\Application\Clock;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

final class AccessControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            PermissionResolver::class,
            static fn (): PermissionResolver => new PermissionResolver,
        );

        $this->app->singleton(
            AdministratorPermissionAuthorizer::class,
            fn (Application $application): AdministratorPermissionAuthorizer => new AdministratorPermissionAuthorizer(
                $application->make(DatabaseManager::class),
                $application->make(PermissionResolver::class),
            ),
        );

        $this->app->singleton(
            AccessMutationAudit::class,
            fn (Application $application): AccessMutationAudit => new AccessMutationAudit(
                $application->make(DatabaseManager::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            SensitiveApprovalAudit::class,
            fn (Application $application): SensitiveApprovalAudit => new SensitiveApprovalAudit(
                $application->make(DatabaseManager::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            AdministratorAccessService::class,
            fn (Application $application): AdministratorAccessService => new AdministratorAccessService(
                $application->make(DatabaseManager::class),
                $application->make(AdministratorPermissionAuthorizer::class),
                $application->make(AccessMutationAudit::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            SensitiveActionApprovalService::class,
            fn (Application $application): SensitiveActionApprovalService => new SensitiveActionApprovalService(
                $application->make(DatabaseManager::class),
                $application->make(AdministratorPermissionAuthorizer::class),
                $application->make(SensitiveApprovalAudit::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            OwnerTransferService::class,
            function (Application $application): OwnerTransferService {
                $key = $application->make(Repository::class)->get('app.key');

                return new OwnerTransferService(
                    $application->make(DatabaseManager::class),
                    $application->make(AdministratorPermissionAuthorizer::class),
                    $application->make(AccessMutationAudit::class),
                    $application->make(Clock::class),
                    is_string($key) ? $key : '',
                );
            },
        );
    }
}

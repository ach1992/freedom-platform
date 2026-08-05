<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Catalog\Application\CatalogMutationAudit;
use App\Modules\Catalog\Application\CatalogMutationExecutor;
use App\Modules\Catalog\Application\ProductCategoryService;
use App\Modules\Catalog\Application\ProductService;
use App\Modules\Catalog\Application\ProductVariantService;
use App\Shared\Application\Clock;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            CatalogMutationAudit::class,
            fn (Application $application): CatalogMutationAudit => new CatalogMutationAudit(
                $application->make(DatabaseManager::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            CatalogMutationExecutor::class,
            fn (Application $application): CatalogMutationExecutor => new CatalogMutationExecutor(
                $application->make(DatabaseManager::class),
                $application->make(AdministratorPermissionAuthorizer::class),
                $application->make(CatalogMutationAudit::class),
            ),
        );

        $this->app->singleton(
            ProductCategoryService::class,
            fn (Application $application): ProductCategoryService => new ProductCategoryService(
                $application->make(CatalogMutationExecutor::class),
                $application->make(CatalogMutationAudit::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            ProductService::class,
            fn (Application $application): ProductService => new ProductService(
                $application->make(CatalogMutationExecutor::class),
                $application->make(CatalogMutationAudit::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            ProductVariantService::class,
            fn (Application $application): ProductVariantService => new ProductVariantService(
                $application->make(CatalogMutationExecutor::class),
                $application->make(CatalogMutationAudit::class),
                $application->make(Clock::class),
            ),
        );
    }
}

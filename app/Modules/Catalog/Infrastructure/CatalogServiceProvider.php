<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Catalog\Application\CatalogMutationAudit;
use App\Modules\Catalog\Application\CatalogMutationExecutor;
use App\Modules\Catalog\Application\CustomPlanArithmetic;
use App\Modules\Catalog\Application\CustomPlanCalculator;
use App\Modules\Catalog\Application\CustomPlanEligibility;
use App\Modules\Catalog\Application\CustomPlanOperationalVerifier;
use App\Modules\Catalog\Application\CustomPlanPolicyService;
use App\Modules\Catalog\Application\CustomPlanUsernameNormalizer;
use App\Modules\Catalog\Application\PlanOfferingActorEligibility;
use App\Modules\Catalog\Application\PlanOfferingRoutePolicyService;
use App\Modules\Catalog\Application\PlanOfferingRouteSelector;
use App\Modules\Catalog\Application\PlanOfferingService;
use App\Modules\Catalog\Application\ProductCategoryService;
use App\Modules\Catalog\Application\ProductService;
use App\Modules\Catalog\Application\ProductVariantService;
use App\Modules\Catalog\Application\RouteOperationalVerifier;
use App\Modules\Catalog\Application\ServiceUsernameAvailability;
use App\Modules\Catalog\Application\TrialEligibility;
use App\Modules\Catalog\Application\TrialMembershipVerifier;
use App\Modules\Catalog\Application\TrialPolicyService;
use App\Modules\Catalog\Application\TrialReservationService;
use App\Modules\Catalog\Application\TrialRouteSelector;
use App\Modules\Panels\Application\TargetCapacityAllocator;
use App\Shared\Application\Clock;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RouteOperationalVerifier::class, DatabaseRouteOperationalVerifier::class);
        $this->app->bind(CustomPlanOperationalVerifier::class, DatabaseCustomPlanOperationalVerifier::class);
        $this->app->bind(ServiceUsernameAvailability::class, DatabaseServiceUsernameAvailability::class);
        $this->app->bind(TrialMembershipVerifier::class, UnavailableTrialMembershipVerifier::class);

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

        $this->app->singleton(
            PlanOfferingService::class,
            fn (Application $application): PlanOfferingService => new PlanOfferingService(
                $application->make(CatalogMutationExecutor::class),
                $application->make(CatalogMutationAudit::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            PlanOfferingRoutePolicyService::class,
            fn (Application $application): PlanOfferingRoutePolicyService => new PlanOfferingRoutePolicyService(
                $application->make(CatalogMutationExecutor::class),
                $application->make(CatalogMutationAudit::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            PlanOfferingRouteSelector::class,
            fn (Application $application): PlanOfferingRouteSelector => new PlanOfferingRouteSelector(
                $application->make(DatabaseManager::class),
                $application->make(PlanOfferingActorEligibility::class),
                $application->make(RouteOperationalVerifier::class),
                $application->make(TargetCapacityAllocator::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            CustomPlanPolicyService::class,
            fn (Application $application): CustomPlanPolicyService => new CustomPlanPolicyService(
                $application->make(CatalogMutationExecutor::class),
                $application->make(CatalogMutationAudit::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            CustomPlanCalculator::class,
            fn (Application $application): CustomPlanCalculator => new CustomPlanCalculator(
                $application->make(DatabaseManager::class),
                $application->make(CustomPlanEligibility::class),
                $application->make(CustomPlanUsernameNormalizer::class),
                $application->make(ServiceUsernameAvailability::class),
                $application->make(CustomPlanArithmetic::class),
                $application->make(CustomPlanOperationalVerifier::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            TrialPolicyService::class,
            fn (Application $application): TrialPolicyService => new TrialPolicyService(
                $application->make(CatalogMutationExecutor::class),
                $application->make(CatalogMutationAudit::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            TrialRouteSelector::class,
            fn (Application $application): TrialRouteSelector => new TrialRouteSelector(
                $application->make(RouteOperationalVerifier::class),
                $application->make(TargetCapacityAllocator::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            TrialReservationService::class,
            fn (Application $application): TrialReservationService => new TrialReservationService(
                $application->make(DatabaseManager::class),
                $application->make(TrialEligibility::class),
                $application->make(TrialMembershipVerifier::class),
                $application->make(TrialRouteSelector::class),
                $application->make(TargetCapacityAllocator::class),
                $application->make(AdministratorPermissionAuthorizer::class),
                $application->make(Clock::class),
            ),
        );
    }
}

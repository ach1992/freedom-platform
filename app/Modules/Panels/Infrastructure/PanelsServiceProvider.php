<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Panels\Application\Contracts\MarzbanGatewayFactory;
use App\Modules\Panels\Application\Contracts\PasarGuardGatewayFactory;
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelApprovalGate;
use App\Modules\Panels\Application\PanelConnectionService;
use App\Modules\Panels\Application\PanelCreateCoordinator;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Panels\Application\PanelInventoryService;
use App\Modules\Panels\Application\PanelMutationAudit;
use App\Modules\Panels\Application\PanelMutationExecutor;
use App\Modules\Panels\Application\PanelPayloadHasher;
use App\Modules\Panels\Application\PanelServiceCanonicalizer;
use App\Modules\Panels\Application\RemoteIdentityResolver;
use App\Modules\Panels\Application\SensitivePanelApprovalGate;
use App\Modules\Panels\Application\TargetCapacityAllocator;
use App\Modules\Panels\Application\TargetCapacityService;
use App\Shared\Application\Clock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class PanelsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PanelApprovalGate::class, SensitivePanelApprovalGate::class);
        $this->app->bind(MarzbanGatewayFactory::class, UnavailableMarzbanGatewayFactory::class);
        $this->app->bind(PasarGuardGatewayFactory::class, UnavailablePasarGuardGatewayFactory::class);

        $this->app->singleton(PanelServiceCanonicalizer::class);
        $this->app->singleton(PanelCredentialPolicy::class);
        $this->app->singleton(RemoteIdentityResolver::class);
        $this->app->singleton(PanelCreateCoordinator::class);
        $this->app->singleton(FakePanelAdapterFactory::class);
        $this->app->singleton(MarzbanAdapterFactory::class);
        $this->app->singleton(PasarGuardAdapterFactory::class);
        $this->app->singleton(
            PanelAdapterRegistry::class,
            fn (Application $application): PanelAdapterRegistry => new PanelAdapterRegistry(
                [
                    $application->make(FakePanelAdapterFactory::class),
                    $application->make(MarzbanAdapterFactory::class),
                    $application->make(PasarGuardAdapterFactory::class),
                ],
                $application->make(PanelCredentialPolicy::class),
            ),
        );

        $this->app->singleton(
            PanelPayloadHasher::class,
            static function (Appplication $application): PanelPayloadHasher {
                $configured = $application->make(ConfigRepository::class)->get('app.key');
                if (! is_string($configured) || $configured === '') {
                    throw new RuntimeException('Application key is unavailable for panel mutation hashing.');
                }

                $key = $configured;
                if (str_starts_with($configured, 'base64:')) {
                    $decoded = base64_decode(substr($configured, 7), true);
                    if ($decoded === false || $decoded === '') {
                        throw new RuntimeException('Application key is invalid for panel mutation hashing.');
                    }
                    $key = $decoded;
                }

                return new PanelPayloadHasher($key);
            },
        );

        $this->app->singleton(
            PanelMutationAudit::class,
            fn (Application $application): PanelMutationAudit => new PanelMutationAudit(
                $application->make(DatabaseManager::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            PanelMutationExecutor::class,
            fn (Application $application): PanelMutationExecutor => new PanelMutationExecutor(
                $application->make(DatabaseManager::class),
                $application->make(AdministratorPermissionAuthorizer::class),
                $application->make(PanelMutationAudit::class),
            ),
        );

        $this->app->singleton(
            PanelConnectionService::class,
            fn (Application $application): PanelConnectionService => new PanelConnectionService(
                $application->make(PanelMutationExecutor::class),
                $application->make(PanelMutationAudit::class),
                $application->make(PanelApprovalGate::class),
                $application->make(PanelPayloadHasher::class),
                $application->make(StringEncrypter::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            PanelInventoryService::class,
            fn (Application $application): PanelInventoryService => new PanelInventoryService(
                $application->make(PanelMutationExecutor::class),
                $application->make(PanelMutationAudit::class),
                $application->make(PanelApprovalGate::class),
                $application->make(PanelPayloadHasher::class),
                $application->make(StringEncrypter::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            TargetCapacityService::class,
            fn (Application $application): TargetCapacityService => new TargetCapacityService(
                $application->make(PanelMutationExecutor::class),
                $application->make(PanelMutationAudit::class),
                $application->make(PanelPayloadHasher::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            TargetCapacityAllocator::class,
            fn (Application $application): TargetCapacityAllocator => new TargetCapacityAllocator(
                $application->make(DatabaseManager::class),
                $application->make(PanelPayloadHasher::class),
                $application->make(Clock::class),
            ),
        );
    }
}

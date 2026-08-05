<?php

declare(strict_types=1);

namespace App\Modules\Agents\Infrastructure;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\AccessControl\Domain\PermissionResolver;
use App\Modules\Agents\Application\AgentApplicationService;
use App\Modules\Agents\Application\AgentMutationAudit;
use App\Modules\Agents\Application\AgentProfileService;
use App\Shared\Application\Clock;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class AgentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            AdministratorPermissionAuthorizer::class,
            fn (Application $application): AdministratorPermissionAuthorizer => new AdministratorPermissionAuthorizer(
                $application->make(DatabaseManager::class),
                new PermissionResolver,
            ),
        );

        $this->app->singleton(
            AgentMutationAudit::class,
            fn (Application $application): AgentMutationAudit => new AgentMutationAudit(
                $application->make(DatabaseManager::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            AgentApplicationService::class,
            function (Application $application): AgentApplicationService {
                $configuration = self::configuration($application);

                return new AgentApplicationService(
                    $application->make(DatabaseManager::class),
                    $application->make(AdministratorPermissionAuthorizer::class),
                    $application->make(AgentMutationAudit::class),
                    $application->make(Clock::class),
                    self::nonNegativeInteger($configuration['reapplication_cooldown_days'] ?? 30, 30),
                    self::pricingProfileCode($configuration['default_pricing_profile_code'] ?? 'default'),
                );
            },
        );

        $this->app->singleton(
            AgentProfileService::class,
            fn (Application $application): AgentProfileService => new AgentProfileService(
                $application->make(DatabaseManager::class),
                $application->make(AdministratorPermissionAuthorizer::class),
                $application->make(AgentMutationAudit::class),
                $application->make(Clock::class),
            ),
        );
    }

    /** @return array<string, mixed> */
    private static function configuration(Application $application): array
    {
        $configuration = $application->make(Repository::class)->get('agents');

        return is_array($configuration) ? $configuration : [];
    }

    private static function nonNegativeInteger(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        $normalized = (int) $value;

        return $normalized >= 0 ? $normalized : $default;
    }

    private static function pricingProfileCode(mixed $value): string
    {
        $normalized = is_string($value) ? trim($value) : 'default';

        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $normalized) !== 1) {
            throw new RuntimeException('Default agent pricing profile code is invalid.');
        }

        return $normalized;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use RuntimeException;

final readonly class ReportMetricVisibilityPolicy
{
    /** @var array<string, non-empty-list<string>> */
    private const METRIC_PERMISSION_PREFIXES = [
        'users.' => ['identity.customers.view'],
        'orders.' => ['administration.search.orders'],
        'sales.' => ['administration.search.payments'],
        'wallet.' => ['wallet.corrections.create', 'refunds.approve'],
        'gateways.' => ['administration.search.payments'],
        'catalog.' => ['catalog.view'],
        'services.' => ['administration.search.services'],
        'agents.' => ['agents.accounts.manage'],
        'tickets.' => ['support.tickets.manage'],
        'referrals.' => ['promotions.rules.manage'],
        'broadcasts.' => ['telegram.broadcasts.manage'],
        'failures.' => ['services.repair'],
        'outbox.' => ['services.repair'],
        'panels.' => ['servers.view'],
    ];

    public function __construct(private AdministratorUserPermissionAuthorizer $authorizer) {}

    public function allowsMetricCode(int $userId, string $metricCode): bool
    {
        foreach ($this->permissionsFor($metricCode) as $permission) {
            if ($this->authorizer->allowsUser($userId, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `reports.view` opens the reporting surface; it never substitutes for the
     * underlying domain permission that already controls the represented data.
     *
     * @param  list<ReportMetric>  $metrics
     * @return list<ReportMetric>
     */
    public function filterForUser(int $userId, array $metrics): array
    {
        /** @var array<string, bool> $permissionCache */
        $permissionCache = [];
        $visible = [];

        foreach ($metrics as $metric) {
            $permissions = $this->permissionsFor($metric->code);
            foreach ($permissions as $permission) {
                $allowed = $permissionCache[$permission]
                    ??= $this->authorizer->allowsUser($userId, $permission);
                if ($allowed) {
                    $visible[] = $metric;

                    continue 2;
                }
            }
        }

        return $visible;
    }

    /** @return non-empty-list<string> */
    private function permissionsFor(string $metricCode): array
    {
        foreach (self::METRIC_PERMISSION_PREFIXES as $prefix => $permissions) {
            if (str_starts_with($metricCode, $prefix)) {
                return $permissions;
            }
        }

        throw new RuntimeException('Reporting metric has no reviewed domain-visibility permission mapping.');
    }
}

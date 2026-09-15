<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use InvalidArgumentException;

final readonly class PlanOfferingRoutePolicyDefinition
{
    /** @var list<PlanOfferingRouteDefinition> */
    public array $routes;

    /** @param list<PlanOfferingRouteDefinition> $routes */
    public function __construct(array $routes)
    {
        if ($routes === []) {
            throw new InvalidArgumentException('Offering route policy requires at least one route.');
        }

        usort(
            $routes,
            static fn (PlanOfferingRouteDefinition $left, PlanOfferingRouteDefinition $right): int => $left->priority <=> $right->priority,
        );

        $targets = [];
        $primaryCount = 0;
        foreach ($routes as $index => $route) {
            if ($route->priority !== $index) {
                throw new InvalidArgumentException('Offering route priorities must be contiguous from zero.');
            }
            if (isset($targets[$route->serviceTargetId])) {
                throw new InvalidArgumentException('Offering route targets must be unique.');
            }
            $targets[$route->serviceTargetId] = true;
            $primaryCount += $route->type === PlanOfferingRouteType::Primary ? 1 : 0;
        }
        if ($primaryCount !== 1 || $routes[0]->type !== PlanOfferingRouteType::Primary) {
            throw new InvalidArgumentException('Offering route policy requires exactly one primary route.');
        }

        $this->routes = $routes;
    }

    /** @return array{routes: list<array<string, bool|int|string|null>>} */
    public function payload(): array
    {
        return [
            'routes' => array_map(
                static fn (PlanOfferingRouteDefinition $route): array => $route->payload(),
                $this->routes,
            ),
        ];
    }
}

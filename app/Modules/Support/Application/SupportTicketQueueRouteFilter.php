<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

final readonly class SupportTicketQueueRouteFilter
{
    /** @param list<string> $roleCodes */
    public function __construct(
        public int $actorUserId,
        public bool $allRoutes,
        public array $roleCodes,
    ) {}
}

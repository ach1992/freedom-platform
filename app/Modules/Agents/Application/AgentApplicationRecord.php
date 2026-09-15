<?php

declare(strict_types=1);

namespace App\Modules\Agents\Application;

final readonly class AgentApplicationRecord
{
    public function __construct(
        public int $customerId,
        public string $state,
        public ?int $claimedByAdministratorId,
    ) {}
}

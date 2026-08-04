<?php

declare(strict_types=1);

namespace App\Modules\Agents\Domain;

enum AgentStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function canTransitionTo(self $next): bool
    {
        return $this !== $next;
    }
}

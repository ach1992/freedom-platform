<?php

declare(strict_types=1);

namespace App\Modules\Agents\Domain;

enum AgentStatus: string
{
    case Active = 'active';
    case Limited = 'limited';
    case Suspended = 'suspended';

    public function canTransitionTo(self $target): bool
    {
        return $this !== $target;
    }
}

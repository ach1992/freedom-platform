<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use DomainException;

final class InvalidStateTransition extends DomainException
{
    public static function between(string $workflow, string $from, string $to): self
    {
        return new self("Invalid {$workflow} transition from {$from} to {$to}.");
    }
}

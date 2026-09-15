<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

use DomainException;

enum SalesServerVisibility: string
{
    case Hidden = 'hidden';
    case Listed = 'listed';

    public function assertCompatibleWith(PanelResourceState $state): void
    {
        if ($this === self::Listed && $state !== PanelResourceState::Active) {
            throw new DomainException('Only an active sales server may be listed.');
        }
    }
}

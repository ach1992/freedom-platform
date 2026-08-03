<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

enum GiftCardAuthority: string
{
    case ValidationOnly = 'validation_only';
    case Reserved = 'reserved';
    case Captured = 'captured';
}

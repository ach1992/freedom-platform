<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain;

enum RefundDestination: string
{
    case Wallet = 'wallet';
    case ManualExternal = 'manual_external';
}

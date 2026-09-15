<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain;

enum WalletHoldStatus: string
{
    case Active = 'active';
    case Captured = 'captured';
    case Released = 'released';
}

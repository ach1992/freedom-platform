<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain;

enum WalletReconciliationStatus: string
{
    case Initial = 'initial';
    case Matched = 'matched';
    case Refreshed = 'refreshed';
}

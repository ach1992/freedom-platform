<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain;

enum LedgerDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}

<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain;

enum WalletCorrectionDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}

<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain;

enum WalletTransferStatus: string
{
    case PendingConfirmation = 'pending_confirmation';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}

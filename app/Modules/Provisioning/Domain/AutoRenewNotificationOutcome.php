<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

enum AutoRenewNotificationOutcome: string
{
    case Success = 'success';
    case InsufficientWallet = 'insufficient_wallet';
    case PriceChangeBlocked = 'price_change_blocked';
    case Failure = 'failure';
}

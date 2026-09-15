<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

enum AutoRenewAttemptState: string
{
    case Pending = 'pending';
    case PriceChangeBlocked = 'price_change_blocked';
    case InsufficientWallet = 'insufficient_wallet';
    case RetryPending = 'retry_pending';
    case Settled = 'settled';
    case MutationQueued = 'mutation_queued';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::PriceChangeBlocked, self::Succeeded, self::Failed], true);
    }
}

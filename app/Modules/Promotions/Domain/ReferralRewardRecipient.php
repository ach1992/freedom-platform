<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Domain;

enum ReferralRewardRecipient: string
{
    case Inviter = 'inviter';
    case Referred = 'referred';
    case Both = 'both';

    /** @return list<string> */
    public function roles(): array
    {
        return match ($this) {
            self::Inviter => ['inviter'],
            self::Referred => ['referred'],
            self::Both => ['inviter', 'referred'],
        };
    }
}

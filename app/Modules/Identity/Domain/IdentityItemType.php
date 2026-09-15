<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

enum IdentityItemType: string
{
    case NationalId = 'national_id';
    case BankCard = 'bank_card';
    case FullName = 'full_name';

    public function isGloballyUnique(): bool
    {
        return $this !== self::FullName;
    }
}

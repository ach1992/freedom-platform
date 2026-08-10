<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Domain;

enum BenefitCodeAudience: string
{
    case Customers = 'customers';
    case Agents = 'agents';
    case Both = 'both';

    public function allows(string $accountType): bool
    {
        return $this === self::Both
            || ($this === self::Customers && $accountType === 'customer')
            || ($this === self::Agents && $accountType === 'agent');
    }
}

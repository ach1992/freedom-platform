<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Domain;

enum PromotionAudience: string
{
    case Customers = 'customers';
    case Agents = 'agents';
    case Both = 'both';

    public function allows(string $accountType): bool
    {
        return match ($this) {
            self::Customers => $accountType === 'customer',
            self::Agents => $accountType === 'agent',
            self::Both => in_array($accountType, ['customer', 'agent'], true),
        };
    }
}

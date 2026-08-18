<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain;

enum OrderSourceType: string
{
    case Purchase = 'purchase';
    case Trial = 'trial';
    case Gift = 'gift';
    case ServiceCode = 'service_code';
    case BenefitCode = 'benefit_code';
    case AdminGrant = 'admin_grant';

    public function requiresFinancialSettlement(): bool
    {
        return $this === self::Purchase;
    }

    public function isZeroCostAuthorization(): bool
    {
        return ! $this->requiresFinancialSettlement();
    }
}

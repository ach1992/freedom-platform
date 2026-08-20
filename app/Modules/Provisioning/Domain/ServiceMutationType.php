<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

enum ServiceMutationType: string
{
    case ResetUsage = 'reset_usage';
    case Suspend = 'suspend';
    case Activate = 'activate';
    case Delete = 'delete';
    case RotateSubscriptionLink = 'rotate_subscription_link';
    case Renew = 'renew';
    case AddData = 'add_data';
    case AddDays = 'add_days';
    case AddDataDays = 'add_data_days';

    public function panelCapability(): string
    {
        return match ($this) {
            self::Renew, self::AddDays => 'update_expiry',
            self::AddData => 'add_data_allowance',
            self::AddDataDays => 'update_expiry',
            default => $this->value,
        };
    }

    public function isPaidEntitlement(): bool
    {
        return in_array($this, [self::Renew, self::AddData, self::AddDays, self::AddDataDays], true);
    }

    /** @return list<string> */
    public function panelCapabilities(): array
    {
        return match ($this) {
            self::AddDataDays => ['update_expiry', 'add_data_allowance', 'atomic_service_entitlements'],
            default => [$this->panelCapability()],
        };
    }
}

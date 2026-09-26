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
    case GrantData = 'grant_data';
    case GrantDays = 'grant_days';
    case GrantDataDays = 'grant_data_days';
    case Reconfigure = 'reconfigure';

    public function panelCapability(): string
    {
        return match ($this) {
            self::Renew, self::AddDays, self::GrantDays => 'update_expiry',
            self::AddData, self::GrantData => 'add_data_allowance',
            self::AddDataDays, self::GrantDataDays => throw new \LogicException('Combined Service mutations require the full panel capability set.'),
            self::Reconfigure => 'reconfigure_service',
            default => $this->value,
        };
    }

    public function isPaidEntitlement(): bool
    {
        return in_array($this, [self::Renew, self::AddData, self::AddDays, self::AddDataDays], true);
    }

    public function isPaidCommercialMutation(): bool
    {
        return $this->isPaidEntitlement() || $this === self::Reconfigure;
    }

    public function isAdministrativeEntitlementGrant(): bool
    {
        return in_array($this, [self::GrantData, self::GrantDays, self::GrantDataDays], true);
    }

    public function isEntitlementMutation(): bool
    {
        return $this->isPaidEntitlement() || $this->isAdministrativeEntitlementGrant();
    }

    /** @return list<string> */
    public function panelCapabilities(): array
    {
        return match ($this) {
            self::AddDataDays, self::GrantDataDays => ['update_expiry', 'add_data_allowance', 'atomic_service_entitlements'],
            self::Reconfigure => ['reconfigure_service'],
            default => [$this->panelCapability()],
        };
    }
}

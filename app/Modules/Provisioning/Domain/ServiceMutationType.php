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

    public function panelCapability(): string
    {
        return $this->value;
    }
}

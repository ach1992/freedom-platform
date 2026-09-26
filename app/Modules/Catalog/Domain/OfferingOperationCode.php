<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum OfferingOperationCode: string
{
    case Renew = 'renew';
    case AddData = 'add_data';
    case AddDays = 'add_days';
    case AddDataDays = 'add_data_days';
    case ResetUsage = 'reset_usage';
    case Suspend = 'suspend';
    case Activate = 'activate';
    case RotateSubscriptionLink = 'rotate_subscription_link';
    case RefreshDetails = 'refresh_details';
    case Delete = 'delete';
    case ClearIpSessions = 'clear_ip_sessions';
    case ChangeProtocol = 'change_protocol';
    case ChangeLocation = 'change_location';
    case ChangePlan = 'change_plan';
}

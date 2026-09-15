<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

enum ServiceDeliveryPurpose: string
{
    case Initial = 'initial';
    case Resend = 'resend';
    case Notification = 'notification';
}

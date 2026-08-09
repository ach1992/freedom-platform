<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

enum PaymentConfigurationState: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Archived = 'archived';
}

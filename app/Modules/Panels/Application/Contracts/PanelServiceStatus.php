<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

enum PanelServiceStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Disabled = 'disabled';
    case Unknown = 'unknown';
}

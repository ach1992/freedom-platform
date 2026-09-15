<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

enum TargetCapacityState: string
{
    case Disabled = 'disabled';
    case Enabled = 'enabled';
}

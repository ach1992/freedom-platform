<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

enum AutoRenewPriceChangeMode: string
{
    case Stop = 'stop';
    case Continue = 'continue';
    case WithinLimit = 'within_limit';
}

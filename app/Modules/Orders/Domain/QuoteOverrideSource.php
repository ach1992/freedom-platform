<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain;

enum QuoteOverrideSource: string
{
    case None = 'none';
    case Account = 'account';
    case Tier = 'tier';
    case Agent = 'agent';
}

<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum ProductVisibility: string
{
    case Hidden = 'hidden';
    case Visible = 'visible';
}

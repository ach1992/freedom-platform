<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

enum PanelNetworkPolicy: string
{
    case PublicOnly = 'public_only';
    case PrivateAllowed = 'private_allowed';
}

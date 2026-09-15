<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

enum PanelProviderType: string
{
    case Fake = 'fake';
    case Marzban = 'marzban';
    case PasarGuard = 'pasarguard';
}

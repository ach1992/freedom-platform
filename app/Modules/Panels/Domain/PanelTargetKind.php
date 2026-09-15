<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

enum PanelTargetKind: string
{
    case Inbound = 'inbound';
    case Group = 'group';
    case Template = 'template';
    case Host = 'host';
}

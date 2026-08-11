<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

use App\Modules\Panels\Application\PanelAdapterSession;

interface PasarGuardGatewayFactory
{
    public function make(PanelAdapterSession $session): PasarGuardGateway;
}

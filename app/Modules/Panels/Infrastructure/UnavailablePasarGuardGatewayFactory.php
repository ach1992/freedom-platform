<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\PasarGuardGateway;
use App\Modules\Panels\Application\Contracts\PasarGuardGatewayFactory;
use App\Modules\Panels\Application\PanelAdapterSession;

final class UnavailablePasarGuardGatewayFactory implements PasarGuardGatewayFactory
{
    public function make(PanelAdapterSession $session): PasarGuardGateway
    {
        return new UnavailablePanelGateway;
    }
}

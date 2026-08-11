<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\MarzbanGateway;
use App\Modules\Panels\Application\Contracts\MarzbanGatewayFactory;
use App\Modules\Panels\Application\PanelAdapterSession;

final class UnavailableMarzbanGatewayFactory implements MarzbanGatewayFactory
{
    public function make(PanelAdapterSession $session): MarzbanGateway
    {
        return new UnavailablePanelGateway;
    }
}

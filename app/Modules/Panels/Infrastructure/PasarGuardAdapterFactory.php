<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelAdapterFactory;
use App\Modules\Panels\Application\Contracts\PasarGuardGatewayFactory;
use App\Modules\Panels\Application\PanelAdapterSession;
use App\Modules\Panels\Domain\PanelProviderType;

final readonly class PasarGuardAdapterFactory implements PanelAdapterFactory
{
    public function __construct(private PasarGuardGatewayFactory $gateways) {}

    public function providerType(): PanelProviderType
    {
        return PanelProviderType::PasarGuard;
    }

    public function make(PanelAdapterSession $session): PanelAdapter
    {
        return new PasarGuardAdapter($this->gateways->make($session));
    }
}

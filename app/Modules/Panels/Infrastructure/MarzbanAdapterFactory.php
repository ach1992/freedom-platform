<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\MarzbanGatewayFactory;
use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelAdapterFactory;
use App\Modules\Panels\Application\PanelAdapterSession;
use App\Modules\Panels\Domain\PanelProviderType;

final readonly class MarzbanAdapterFactory implements PanelAdapterFactory
{
    public function __construct(private MarzbanGatewayFactory $gateways) {}

    public function providerType(): PanelProviderType { return PanelProviderType::Marzban; }

    public function make(PanelAdapterSession $session): PanelAdapter
    {
        return new MarzbanAdapter($this->gateways->make($session));
    }
}

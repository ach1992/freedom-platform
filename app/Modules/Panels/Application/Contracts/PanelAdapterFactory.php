<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

use App\Modules\Panels\Application\PanelAdapterSession;
use App\Modules\Panels\Domain\PanelProviderType;

interface PanelAdapterFactory
{
    public function providerType(): PanelProviderType;

    public function make(PanelAdapterSession $session): PanelAdapter;
}

<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelAdapterFactory;
use App\Modules\Panels\Application\PanelAdapterSession;
use App\Modules\Panels\Application\PanelServiceCanonicalizer;
use App\Modules\Panels\Domain\PanelProviderType;

final readonly class FakePanelAdapterFactory implements PanelAdapterFactory
{
    public function __construct(private PanelServiceCanonicalizer $canonicalizer) {}

    public function providerType(): PanelProviderType
    {
        return PanelProviderType::Fake;
    }

    public function make(PanelAdapterSession $session): PanelAdapter
    {
        return new FakePanelAdapter($this->canonicalizer);
    }
}

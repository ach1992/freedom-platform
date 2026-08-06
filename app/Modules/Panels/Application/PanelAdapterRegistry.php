<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelAdapterFactory;
use App\Modules\Panels\Domain\PanelProviderType;
use InvalidArgumentException;

final class PanelAdapterRegistry
{
    public function __construct(
        iterable $factories,
        private readonly PanelCredentialPolicy $credentials,
    ) {
        $this->register($factories);
    }

    /** @var array<string, PanelAdapterFactory> */
    private array $factories = [];

    /** @param iterable<PanelAdapterFactory> $factories */
    private function register(iterable $factories): void
    {
        foreach ($factories as $factory) {
            $key = $factory->providerType()->value;
            if (isset($this->factories[$key])) {
                throw new InvalidArgumentException('Duplicate panel adapter factory registration.');
            }
            $this->factories[$key] = $factory;
        }
    }

    public function make(PanelProviderType $provider, PanelAdapterSession $session): PanelAdapter
    {
        $this->credentials->assertSatisfied($provider, $session->credentials);

        $factory = $this->factories[$provider->value]
            ?? throw new InvalidArgumentException('Panel adapter factory is not registered.');

        return $factory->make($session);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Domain\PanelCredentials;
use App\Modules\Panels\Domain\PanelProviderType;
use InvalidArgumentException;

final class PanelCredentialPolicy
{
    public function assertSatisfied(PanelProviderType $provider, PanelCredentials $credentials): void
    {
        $values = $credentials->values;

        match ($provider) {
            PanelProviderType::Fake => null,
            PanelProviderType::Marzban => $this->requireUsernameAndPassword($values, 'Marzban'),
            PanelProviderType::PasarGuard => $this->requireTokenOrCredentials($values),
        };
    }

    /** @param array<string, string> $values */
    private function requireUsernameAndPassword(array $values, string $provider): void
    {
        if (! isset($values['username'], $values['password'])) {
            throw new InvalidArgumentException($provider.' credentials require username and password.');
        }
    }

    /** @param array<string, string> $values */
    private function requireTokenOrCredentials(array $values): void
    {
        if (isset($values['api_token'])) {
            return;
        }

        $this->requireUsernameAndPassword($values, 'PasarGuard');
    }
}

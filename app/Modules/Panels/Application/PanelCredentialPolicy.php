<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Domain\PanelCredentials;
use App\Modules\Panels\Domain\PanelProviderType;
use InvalidArgumentException;

final class PanelCredentialPolicy
{
    private const PASARGUARD_API_KEY_PATTERN = '/\Apg_key_[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}\z/';

    public function assertSatisfied(PanelProviderType $provider, PanelCredentials $credentials): void
    {
        $values = $credentials->values;

        if ($provider === PanelProviderType::Fake) {
            return;
        }

        if ($provider === PanelProviderType::Marzban) {
            $this->requireUsernameAndPassword($values, 'Marzban');

            return;
        }

        $this->requirePasarGuardCredentials($values);
    }

    /** @param array<string, string> $values */
    private function requireUsernameAndPassword(array $values, string $provider): void
    {
        if (! isset($values['username'], $values['password'])) {
            throw new InvalidArgumentException($provider.' credentials require username and password.');
        }
    }

    /** @param array<string, string> $values */
    private function requirePasarGuardCredentials(array $values): void
    {
        $apiKey = $values['api_key'] ?? $values['api_token'] ?? null;
        if ($apiKey !== null) {
            if (preg_match(self::PASARGUARD_API_KEY_PATTERN, $apiKey) !== 1) {
                throw new InvalidArgumentException('PasarGuard API key is invalid.');
            }

            return;
        }

        $this->requireUsernameAndPassword($values, 'PasarGuard');
    }
}

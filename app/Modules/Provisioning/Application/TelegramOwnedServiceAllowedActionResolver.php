<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Modules\Telegram\Application\TelegramOwnedServiceAction;

final readonly class TelegramOwnedServiceAllowedActionResolver
{
    /** @var array<string,string> */
    private const PACKAGE_TYPE_BY_ACTION = [
        'renew' => 'renewal',
        'add_data' => 'add_data',
        'add_days' => 'add_days',
        'add_data_days' => 'add_data_days',
    ];

    /**
     * @param  array<string,array{customer_enabled:bool,required_capability_code:?string}>  $policies
     * @param  list<string>  $packageTypes
     * @param  list<string>  $verifiedCapabilities
     * @return list<TelegramOwnedServiceAction>
     */
    public function resolve(
        string $lifecycleState,
        bool $fullyProvisioned,
        array $policies,
        array $packageTypes,
        array $verifiedCapabilities,
    ): array {
        if (! $fullyProvisioned || ! in_array($lifecycleState, ['active', 'suspended'], true)) {
            return [];
        }

        $packages = array_fill_keys($packageTypes, true);
        $capabilities = array_fill_keys($verifiedCapabilities, true);
        $allowed = [];

        foreach (TelegramOwnedServiceAction::ordered() as $action) {
            $policy = $policies[$action->value] ?? null;
            if ($policy === null || ! $policy['customer_enabled']) {
                continue;
            }

            $packageType = self::PACKAGE_TYPE_BY_ACTION[$action->value] ?? null;
            if ($packageType !== null && ! isset($packages[$packageType])) {
                continue;
            }

            $mutation = ServiceMutationType::from($action->value);
            $requiredCapabilities = $mutation->panelCapabilities();
            $policyCapability = $policy['required_capability_code'];
            if ($policyCapability !== null) {
                $requiredCapabilities[] = $policyCapability;
            }

            $available = true;
            foreach (array_unique($requiredCapabilities) as $requiredCapability) {
                if (! isset($capabilities[$requiredCapability])) {
                    $available = false;
                    break;
                }
            }
            if ($available) {
                $allowed[] = $action;
            }
        }

        return $allowed;
    }
}

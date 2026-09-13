<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerTrialClaimOptions
{
    /**
     * @param  list<TelegramCustomerTrialRouteOption>  $routeOptions
     * @param  list<TelegramCustomerTrialProtocolOption>  $protocolOptions
     * @param  list<TelegramCustomerTrialFallbackDisclosure>  $fallbackDisclosures
     */
    public function __construct(
        public string $offeringSelectionToken,
        public string $serverSelectionMode,
        public string $protocolSelectionMode,
        public array $routeOptions,
        public array $protocolOptions,
        public bool $fallbackAllowed,
        public array $fallbackDisclosures,
    ) {
        if (preg_match('/\A[0-9a-f]{40}\z/', $offeringSelectionToken) !== 1) {
            throw new InvalidArgumentException('Telegram Trial Offering selection token is invalid.');
        }
        if (! in_array($serverSelectionMode, ['system_selects', 'customer_selects', 'hybrid'], true)) {
            throw new InvalidArgumentException('Telegram Trial server selection mode is invalid.');
        }
        if (! in_array($protocolSelectionMode, ['fixed', 'system_selects', 'customer_selects'], true)) {
            throw new InvalidArgumentException('Telegram Trial protocol selection mode is invalid.');
        }
        if ($serverSelectionMode === 'customer_selects' && $routeOptions === []) {
            throw new InvalidArgumentException('Telegram Trial customer route selection has no options.');
        }
        if ($protocolSelectionMode === 'customer_selects' && $protocolOptions === []) {
            throw new InvalidArgumentException('Telegram Trial customer protocol selection has no options.');
        }
    }
}

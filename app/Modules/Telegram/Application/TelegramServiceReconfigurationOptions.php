<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramServiceReconfigurationOptions
{
    /**
     * @param list<TelegramServiceReconfigurationRouteOption> $routeOptions
     * @param list<TelegramServiceReconfigurationProtocolOption> $protocolOptions
     */
    public function __construct(
        public string $offeringSelectionToken,
        public string $serverSelectionMode,
        public string $protocolSelectionMode,
        public array $routeOptions,
        public array $protocolOptions,
    ) {
        if (preg_match('/\A[0-9a-f]{40}\z/', $offeringSelectionToken) !== 1
            || ! in_array($serverSelectionMode, ['system_selects', 'customer_selects', 'hybrid'], true)
            || ! in_array($protocolSelectionMode, ['fixed', 'system_selects', 'customer_selects'], true)) {
            throw new InvalidArgumentException('Telegram Service reconfiguration selection options are invalid.');
        }
        foreach ($routeOptions as $option) {
            if (! $option instanceof TelegramServiceReconfigurationRouteOption) {
                throw new InvalidArgumentException('Telegram Service reconfiguration route option list is invalid.');
            }
        }
        foreach ($protocolOptions as $option) {
            if (! $option instanceof TelegramServiceReconfigurationProtocolOption) {
                throw new InvalidArgumentException('Telegram Service reconfiguration protocol option list is invalid.');
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramOwnedServiceAutoRenewSnapshot
{
    /** @param list<TelegramOwnedServiceAutoRenewPackage> $packages */
    public function __construct(
        public string $servicePublicId,
        public bool $enabled,
        public ?string $configuredPackageCode,
        public ?int $acceptedPriceIrr,
        public ?int $configurationVersion,
        public array $packages,
    ) {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $servicePublicId) !== 1 || $packages === []) {
            throw new InvalidArgumentException('Telegram Service auto-renew snapshot is invalid.');
        }
        if ($enabled && ($configuredPackageCode === null || $acceptedPriceIrr === null || $configurationVersion === null)) {
            throw new InvalidArgumentException('Enabled Telegram Service auto-renew snapshot is incomplete.');
        }
        if ($configuredPackageCode !== null
            && ! in_array($configuredPackageCode, array_map(static fn (TelegramOwnedServiceAutoRenewPackage $package): string => $package->code, $packages), true)) {
            throw new InvalidArgumentException('Configured Telegram Service auto-renew package is not currently available.');
        }
    }

    public function package(string $code): ?TelegramOwnedServiceAutoRenewPackage
    {
        foreach ($this->packages as $package) {
            if (hash_equals($package->code, $code)) {
                return $package;
            }
        }

        return null;
    }
}

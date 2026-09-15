<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramOwnedServiceListItem
{
    public function __construct(
        public string $selectionToken,
        public string $publicId,
        public string $lifecycleState,
        public string $planNameFa,
        public ?string $planNameEn,
        public string $serverNameFa,
        public ?string $serverNameEn,
        public ?string $provisionedAt,
    ) {
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
            throw new InvalidArgumentException('Telegram owned Service selection token is invalid.');
        }
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1) {
            throw new InvalidArgumentException('Telegram owned Service public identifier is invalid.');
        }
        if (! in_array($lifecycleState, ['active', 'suspended', 'retired'], true)) {
            throw new InvalidArgumentException('Telegram owned Service lifecycle state is invalid.');
        }
        foreach ([$planNameFa, $serverNameFa] as $requiredLabel) {
            if ($requiredLabel === '' || ! mb_check_encoding($requiredLabel, 'UTF-8')) {
                throw new InvalidArgumentException('Telegram owned Service presentation label is invalid.');
            }
        }
        foreach ([$planNameEn, $serverNameEn] as $optionalLabel) {
            if ($optionalLabel !== null && ($optionalLabel === '' || ! mb_check_encoding($optionalLabel, 'UTF-8'))) {
                throw new InvalidArgumentException('Telegram owned Service optional presentation label is invalid.');
            }
        }
    }
}

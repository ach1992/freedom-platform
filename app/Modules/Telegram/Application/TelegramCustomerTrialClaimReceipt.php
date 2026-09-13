<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerTrialClaimReceipt
{
    public function __construct(
        public string $orderPublicId,
        public string $serviceSubscriptionPublicId,
        public string $provisioningOperationPublicId,
        public int $dataBytes,
        public int $durationDays,
        public string $serverNameFa,
        public ?string $serverNameEn,
        public string $protocolNameFa,
        public ?string $protocolNameEn,
        public bool $fallbackUsed,
        public ?string $fallbackDisclosureFa,
        public ?string $fallbackDisclosureEn,
        public bool $replayed = false,
    ) {
        foreach ([$orderPublicId, $serviceSubscriptionPublicId, $provisioningOperationPublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Telegram Trial claim public identity is invalid.');
            }
        }
        if ($dataBytes < 1 || $durationDays < 1) {
            throw new InvalidArgumentException('Telegram Trial claim entitlement is invalid.');
        }
        foreach ([$serverNameFa, $protocolNameFa] as $label) {
            if ($label === '' || ! mb_check_encoding($label, 'UTF-8')) {
                throw new InvalidArgumentException('Telegram Trial claim label is invalid.');
            }
        }
        foreach ([$serverNameEn, $protocolNameEn, $fallbackDisclosureFa, $fallbackDisclosureEn] as $label) {
            if ($label !== null && ($label === '' || ! mb_check_encoding($label, 'UTF-8'))) {
                throw new InvalidArgumentException('Telegram Trial claim optional label is invalid.');
            }
        }
        if (! $fallbackUsed && ($fallbackDisclosureFa !== null || $fallbackDisclosureEn !== null)) {
            throw new InvalidArgumentException('Telegram Trial non-fallback claim cannot carry fallback disclosure.');
        }
    }
}

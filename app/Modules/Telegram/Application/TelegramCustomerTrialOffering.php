<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerTrialOffering
{
    public function __construct(
        public string $selectionToken,
        public string $offeringCode,
        public string $categoryNameFa,
        public ?string $categoryNameEn,
        public string $productNameFa,
        public ?string $productNameEn,
        public ?string $variantNameFa,
        public ?string $variantNameEn,
        public string $serviceModeLabelFa,
        public ?string $serviceModeLabelEn,
        public int $trialDataBytes,
        public int $trialDurationDays,
        public string $phoneVerificationPolicy,
        public bool $membershipRequired,
    ) {
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
            throw new InvalidArgumentException('Telegram Trial offering selection token is invalid.');
        }
        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $offeringCode) !== 1) {
            throw new InvalidArgumentException('Telegram Trial offering public code is invalid.');
        }
        foreach ([$categoryNameFa, $productNameFa, $serviceModeLabelFa] as $label) {
            if ($label === '' || ! mb_check_encoding($label, 'UTF-8')) {
                throw new InvalidArgumentException('Telegram Trial offering label is invalid.');
            }
        }
        foreach ([$categoryNameEn, $productNameEn, $variantNameFa, $variantNameEn, $serviceModeLabelEn] as $label) {
            if ($label !== null && ($label === '' || ! mb_check_encoding($label, 'UTF-8'))) {
                throw new InvalidArgumentException('Telegram Trial offering optional label is invalid.');
            }
        }
        if ($trialDataBytes < 1 || $trialDurationDays < 1) {
            throw new InvalidArgumentException('Telegram Trial offering policy facts are invalid.');
        }
        if (! in_array($phoneVerificationPolicy, ['none', 'telegram_contact_only', 'sms_otp_only', 'either', 'both'], true)) {
            throw new InvalidArgumentException('Telegram Trial phone-verification policy is invalid.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseGiftCardType
{
    public function __construct(
        public string $typeCode,
        public string $displayName,
        public string $brand,
        public ?string $region,
        public string $faceCurrency,
        public string $submissionMode,
        public string $verificationMode,
        public string $configurationHash,
    ) {
        if (preg_match('/\A[A-Za-z0-9:_.-]{2,64}\z/', $typeCode) !== 1
            || trim($displayName) === ''
            || mb_strlen($displayName) > 128
            || preg_match('/[\x00-\x1F\x7F]/', $displayName) === 1
            || trim($brand) === ''
            || mb_strlen($brand) > 64
            || preg_match('/[\x00-\x1F\x7F]/', $brand) === 1
            || ($region !== null && (trim($region) === '' || mb_strlen($region) > 64 || preg_match('/[\x00-\x1F\x7F]/', $region) === 1))
            || preg_match('/\A[A-Z]{3}\z/', $faceCurrency) !== 1
            || ! in_array($submissionMode, ['image_only', 'code_only', 'either', 'both'], true)
            || ! in_array($verificationMode, [
                'manual_only',
                'automatic_only',
                'automatic_then_manual',
                'automatic_with_manual_approval_above_limit',
                'manual_fallback_on_provider_failure',
            ], true)
            || preg_match('/\A[0-9a-f]{64}\z/', $configurationHash) !== 1) {
            throw new InvalidArgumentException('Telegram Gift Card type projection is invalid.');
        }
    }
}

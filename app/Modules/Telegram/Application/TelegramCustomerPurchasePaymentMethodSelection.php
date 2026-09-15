<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerPurchasePaymentMethodSelection
{
    public function __construct(
        public string $decisionPublicId,
        public string $sourceQuotePublicId,
        public string $methodCode,
    ) {
        foreach ([$decisionPublicId, $sourceQuotePublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Telegram purchase payment-method selection public identity is invalid.');
            }
        }
        if (preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $methodCode) !== 1) {
            throw new InvalidArgumentException('Telegram purchase payment-method selection code is invalid.');
        }
    }
}

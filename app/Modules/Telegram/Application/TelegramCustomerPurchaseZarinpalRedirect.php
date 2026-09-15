<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseZarinpalRedirect
{
    public function __construct(
        public string $requestPublicId,
        public string $paymentIntentPublicId,
        public string $state,
        public ?string $redirectUrl,
        public bool $replayed,
        public bool $manualReviewRequired,
    ) {
        foreach ([$requestPublicId, $paymentIntentPublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Telegram Zarinpal public identity is invalid.');
            }
        }
        if (! in_array($state, ['initiating', 'redirectable', 'uncertain', 'failed', 'verified', 'manual_review'], true)) {
            throw new InvalidArgumentException('Telegram Zarinpal request state is invalid.');
        }
        if ($state === 'redirectable') {
            if ($redirectUrl === null || $redirectUrl === '' || strlen($redirectUrl) > 512) {
                throw new InvalidArgumentException('Telegram Zarinpal redirect is unavailable.');
            }
        } elseif ($redirectUrl !== null) {
            throw new InvalidArgumentException('Telegram Zarinpal non-redirectable state cannot expose a redirect.');
        }
    }
}

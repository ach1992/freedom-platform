<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseUsdtSubmission
{
    public function __construct(
        public string $submissionPublicId,
        public string $authorityPublicId,
        public string $paymentIntentPublicId,
        public string $txid,
        public string $state,
        public bool $replayed,
    ) {
        foreach ([$submissionPublicId, $authorityPublicId, $paymentIntentPublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Telegram USDT submission public identity is invalid.');
            }
        }
        if (preg_match('/\A0x[a-f0-9]{64}\z/', $txid) !== 1 || $state !== 'submitted') {
            throw new InvalidArgumentException('Telegram USDT submission state is invalid.');
        }
    }
}

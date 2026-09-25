<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseGiftCardSubmission
{
    public function __construct(
        public string $submissionPublicId,
        public string $paymentIntentPublicId,
        public ?string $reviewPublicId,
        public string $typeCode,
        public string $maskedCode,
        public int $claimedFaceValue,
        public string $claimedCurrency,
        public string $state,
        public bool $replayed,
    ) {
        foreach ([$submissionPublicId, $paymentIntentPublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Telegram Gift Card submission public identity is invalid.');
            }
        }
        if ($reviewPublicId !== null && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $reviewPublicId) !== 1) {
            throw new InvalidArgumentException('Telegram Gift Card review public identity is invalid.');
        }
        if (preg_match('/\A[A-Za-z0-9:_.-]{2,64}\z/', $typeCode) !== 1
            || mb_strlen($maskedCode) > 32
            || ($maskedCode !== '' && preg_match('/[\x00-\x1F\x7F]/', $maskedCode) === 1)
            || $claimedFaceValue < 1
            || preg_match('/\A[A-Z]{3}\z/', $claimedCurrency) !== 1
            || ! in_array($state, ['submitted', 'pending_manual_review'], true)
            || ($state === 'submitted' && $reviewPublicId !== null)
            || ($state === 'pending_manual_review' && $reviewPublicId === null)) {
            throw new InvalidArgumentException('Telegram Gift Card submission projection is invalid.');
        }
    }
}

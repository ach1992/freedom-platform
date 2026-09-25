<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\RestrictedValue;
use DomainException;

final readonly class TelegramAlternativePaymentReviewEvidence
{
    public function __construct(
        public string $kind,
        public string $reviewPublicId,
        public string $associationType,
        public string $associationPublicId,
        public RestrictedValue $privateMediaReference,
        public RestrictedValue $contentSha256,
    ) {
        if (! in_array($kind, ['c2c', 'gift_card', 'usdt'], true)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $reviewPublicId) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $associationPublicId) !== 1) {
            throw new DomainException('Telegram alternative-payment evidence identity is invalid.');
        }

        $expectedAssociation = match ($kind) {
            'c2c' => 'c2c_manual_submission',
            'gift_card' => 'gift_card_submission',
            'usdt' => 'usdt_txid_submission',
        };
        if (! hash_equals($expectedAssociation, $associationType)) {
            throw new DomainException('Telegram alternative-payment evidence association is invalid.');
        }
    }
}

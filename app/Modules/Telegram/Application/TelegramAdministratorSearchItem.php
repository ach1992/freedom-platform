<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramAdministratorSearchItem
{
    private const KINDS = [
        'user',
        'order',
        'payment_intent',
        'purchase_settlement',
        'provider_transaction',
        'card_receipt',
        'gift_submission',
        'service',
    ];

    public function __construct(
        public string $kind,
        public string $publicId,
        public string $state,
        public ?string $ownerPublicId = null,
        public ?string $providerCode = null,
        public ?string $reference = null,
        public bool $referenceMasked = false,
        public ?int $amountIrr = null,
    ) {
        if (! in_array($kind, self::KINDS, true)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1
            || $state === ''
            || mb_strlen($state) > 64
            || ! mb_check_encoding($state, 'UTF-8')
            || ($ownerPublicId !== null && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $ownerPublicId) !== 1)
            || ($providerCode !== null && preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $providerCode) !== 1)
            || ($reference !== null && ($reference === '' || mb_strlen($reference) > 191 || ! mb_check_encoding($reference, 'UTF-8')))
            || ($amountIrr !== null && $amountIrr < 0)
        ) {
            throw new InvalidArgumentException('Telegram administrator search item is invalid.');
        }
    }

    public function identityKey(): string
    {
        return implode(':', [
            $this->kind,
            $this->publicId,
            $this->providerCode ?? '',
            $this->reference ?? '',
        ]);
    }
}

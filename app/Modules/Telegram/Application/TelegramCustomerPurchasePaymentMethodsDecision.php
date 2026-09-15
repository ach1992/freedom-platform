<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerPurchasePaymentMethodsDecision
{
    /** @param list<string> $methodCodes */
    public function __construct(
        public string $decisionPublicId,
        public string $sourceQuotePublicId,
        public string $sourceQuoteConfigurationHash,
        public string $configurationSnapshotHash,
        public array $methodCodes,
        public bool $replayed,
    ) {
        foreach ([$decisionPublicId, $sourceQuotePublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Telegram purchase payment-method public identity is invalid.');
            }
        }
        foreach ([$sourceQuoteConfigurationHash, $configurationSnapshotHash] as $hash) {
            if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
                throw new InvalidArgumentException('Telegram purchase payment-method snapshot identity is invalid.');
            }
        }
        if (count($methodCodes) > 32 || count($methodCodes) !== count(array_unique($methodCodes))) {
            throw new InvalidArgumentException('Telegram purchase payment-method list is invalid.');
        }
        foreach ($methodCodes as $methodCode) {
            if (preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $methodCode) !== 1) {
                throw new InvalidArgumentException('Telegram purchase payment-method code is invalid.');
            }
        }
    }
}

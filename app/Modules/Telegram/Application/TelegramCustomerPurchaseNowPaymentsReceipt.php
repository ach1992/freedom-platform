<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseNowPaymentsReceipt
{
    public function __construct(
        public string $authorityPublicId,
        public string $paymentIntentPublicId,
        public string $state,
        public string $rateSource,
        public string $rateIrr,
        public string $priceAmountUsd,
        public string $payCurrency,
        public ?string $providerPaymentId,
        public ?string $providerStatus,
        public ?string $providerPayAmount,
        public ?string $providerPayAddress,
        public ?string $settlementPublicId,
        public bool $replayed,
        public bool $manualReviewRequired,
    ) {
        foreach ([$authorityPublicId, $paymentIntentPublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Telegram NOWPayments public identity is invalid.');
            }
        }
        if ($settlementPublicId !== null && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $settlementPublicId) !== 1) {
            throw new InvalidArgumentException('Telegram NOWPayments settlement identity is invalid.');
        }
        if (! in_array($state, ['initiating', 'created', 'uncertain', 'manual_review', 'finished', 'failed', 'expired'], true)) {
            throw new InvalidArgumentException('Telegram NOWPayments state is invalid.');
        }
        foreach ([$rateIrr, $priceAmountUsd] as $decimal) {
            if (preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $decimal) !== 1) {
                throw new InvalidArgumentException('Telegram NOWPayments pricing snapshot is invalid.');
            }
        }
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{1,15}\z/', $payCurrency) !== 1) {
            throw new InvalidArgumentException('Telegram NOWPayments pay currency is invalid.');
        }
        if ($state === 'created'
            && ($providerPaymentId === null || $providerPayAmount === null || $providerPayAddress === null)) {
            throw new InvalidArgumentException('Telegram NOWPayments payment instructions are incomplete.');
        }
        if ($state === 'finished' && $settlementPublicId === null) {
            throw new InvalidArgumentException('Telegram NOWPayments finished state requires settlement authority.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseOffering
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
        public int $basePriceIrr,
        public int $durationDays,
        public ?int $dataAllowanceBytes,
        public ?int $deviceLimit,
        public string $accountType = 'customer',
    ) {
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
            throw new InvalidArgumentException('Telegram purchase offering selection token is invalid.');
        }
        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $offeringCode) !== 1) {
            throw new InvalidArgumentException('Telegram purchase offering public code is invalid.');
        }
        foreach ([$categoryNameFa, $productNameFa, $serviceModeLabelFa] as $label) {
            if ($label === '' || ! mb_check_encoding($label, 'UTF-8')) {
                throw new InvalidArgumentException('Telegram purchase offering label is invalid.');
            }
        }
        foreach ([$categoryNameEn, $productNameEn, $variantNameFa, $variantNameEn, $serviceModeLabelEn] as $label) {
            if ($label !== null && ($label === '' || ! mb_check_encoding($label, 'UTF-8'))) {
                throw new InvalidArgumentException('Telegram purchase offering optional label is invalid.');
            }
        }
        if ($basePriceIrr < 0 || $durationDays < 1) {
            throw new InvalidArgumentException('Telegram purchase offering commercial facts are invalid.');
        }
        if ($dataAllowanceBytes !== null && $dataAllowanceBytes < 1) {
            throw new InvalidArgumentException('Telegram purchase offering data allowance is invalid.');
        }
        if ($deviceLimit !== null && $deviceLimit < 1) {
            throw new InvalidArgumentException('Telegram purchase offering device limit is invalid.');
        }
        if (! in_array($accountType, ['customer', 'agent'], true)) {
            throw new InvalidArgumentException('Telegram purchase offering account type is invalid.');
        }
    }
}

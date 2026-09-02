<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseOffering
{
    public function __construct(
        public string $selectionToken,
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
    ) {
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
            throw new InvalidArgumentException('Telegram purchase offering selection token is invalid.');
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
    }
}

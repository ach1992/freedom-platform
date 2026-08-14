<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application;

final readonly class GiftCardTypeReceipt
{
    public function __construct(
        public int $typeId,
        public string $publicId,
        public string $typeCode,
        public string $brand,
        public ?string $region,
        public string $faceCurrency,
        public string $submissionMode,
        public string $verificationMode,
        public ?int $manualApprovalLimitFaceValue,
        public string $providerCode,
        public int $version,
        public string $configurationHash,
        public bool $active,
        public bool $replayed,
    ) {}
}

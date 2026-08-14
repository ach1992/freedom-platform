<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application\Contracts;

final readonly class GiftCardProviderRequest
{
    public function __construct(
        public string $operationKey,
        public string $submissionPublicId,
        public string $typeCode,
        public string $brand,
        public ?string $region,
        public string $faceCurrency,
        public int $faceValue,
        public ?string $code,
        public ?string $privateImageReference,
    ) {}
}

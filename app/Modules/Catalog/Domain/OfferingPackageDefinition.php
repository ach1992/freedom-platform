<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use InvalidArgumentException;

final readonly class OfferingPackageDefinition
{
    public string $code;
    public string $nameFa;
    public ?string $nameEn;

    public function __construct(
        string $code,
        public OfferingPackageType $type,
        string $nameFa,
        ?string $nameEn,
        public int $priceIrr,
        public ?int $durationDays,
        public ?int $dataBytes,
        public bool $discountEligible,
        public int $sortOrder,
    ) {
        $this->code = CatalogCode::fromInput($code)->value;
        $this->nameFa = CatalogText::requiredName($nameFa);
        $this->nameEn = CatalogText::optionalName($nameEn);

        if ($priceIrr < 0 || $sortOrder < 0) {
            throw new InvalidArgumentException('Package price and sort order must not be negative.');
        }

        if ($durationDays !== null && $durationDays < 1) {
            throw new InvalidArgumentException('Package duration must be positive.');
        }

        if ($dataBytes !== null && $dataBytes < 1) {
            throw new InvalidArgumentException('Package data allowance must be positive.');
        }

        $validShape = match ($type) {
            OfferingPackageType::Renewal => $durationDays !== null,
            OfferingPackageType::AddData => $durationDays === null && $dataBytes !== null,
            OfferingPackageType::AddDays => $durationDays !== null && $dataBytes === null,
            OfferingPackageType::AddDataDays => $durationDays !== null && $dataBytes !== null,
        };

        if (! $validShape) {
            throw new InvalidArgumentException('Package values do not match the package type.');
        }
    }
}

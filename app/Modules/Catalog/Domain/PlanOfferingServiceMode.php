<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use InvalidArgumentException;

final readonly class PlanOfferingServiceMode
{
    public string $code;
    public string $labelFa;
    public ?string $labelEn;

    public function __construct(string $code, string $labelFa, ?string $labelEn)
    {
        $this->code = CatalogCode::fromInput($code)->value;
        $this->labelFa = CatalogText::requiredName($labelFa);
        $this->labelEn = CatalogText::optionalName($labelEn);

        if (! in_array($this->code, ['shared', 'dedicated'], true)
            && ! str_starts_with($this->code, 'custom.')
        ) {
            throw new InvalidArgumentException('Custom service mode codes must use the custom. namespace.');
        }
    }
}

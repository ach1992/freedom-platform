<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use InvalidArgumentException;

final readonly class ProductSku
{
    private function __construct(public string $value) {}

    public static function fromInput(string $value): self
    {
        $normalized = trim($value);

        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException('Product SKU is invalid.');
        }

        return new self($normalized);
    }
}

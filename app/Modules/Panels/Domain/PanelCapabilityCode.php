<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

use InvalidArgumentException;

final readonly class PanelCapabilityCode
{
    private function __construct(public string $value) {}

    public static function fromInput(string $value): self
    {
        $normalized = strtolower(trim($value));
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException('Panel capability code is invalid.');
        }

        return new self($normalized);
    }
}

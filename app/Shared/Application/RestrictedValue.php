<?php

declare(strict_types=1);

namespace App\Shared\Application;

use InvalidArgumentException;

final readonly class RestrictedValue implements RestrictedData
{
    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('Restricted values cannot be empty.');
        }

        return new self($value);
    }

    /**
     * Reveal is intentionally explicit. Callers remain responsible for routing
     * the value only into the protected authority that requires plaintext.
     */
    public function reveal(): string
    {
        return $this->value;
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Application;

use InvalidArgumentException;

final readonly class SafeLogContext
{
    /** @param array<string, bool|float|int|string|null|RestrictedData> $values */
    private function __construct(private array $values) {}

    /**
     * Routine structured logs deliberately accept only flat scalar metadata or
     * an explicit RestrictedData marker. Provider payloads and arbitrary object
     * graphs must be normalized to identifiers/state before logging.
     *
     * @param  array<array-key, mixed>  $values
     */
    public static function from(array $values): self
    {
        $safe = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException('Log context keys must be non-empty strings.');
            }

            if (! is_scalar($value) && $value !== null && ! $value instanceof RestrictedData) {
                throw new InvalidArgumentException('Log context values must be scalar, null, or explicitly classified RestrictedData.');
            }

            $safe[$key] = $value;
        }

        /** @var array<string, bool|float|int|string|null|RestrictedData> $safe */
        return new self($safe);
    }

    /** @return array<string, bool|float|int|string|null|RestrictedData> */
    public function values(): array
    {
        return $this->values;
    }
}

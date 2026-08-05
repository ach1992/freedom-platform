<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

use InvalidArgumentException;

final readonly class PanelCredentials
{
    /** @param array<string, string> $values */
    private function __construct(
        public array $values,
        public string $canonicalJson,
    ) {}

    /** @param array<string, mixed> $values */
    public static function fromInput(array $values): self
    {
        if ($values === [] || array_is_list($values) || count($values) > 32) {
            throw new InvalidArgumentException('Panel credentials are invalid.');
        }

        $normalized = [];
        foreach ($values as $key => $value) {
            if (! is_string($key)
                || preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $key) !== 1
                || ! is_string($value)
                || trim($value) === ''
                || mb_strlen($value) > 4096
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
            ) {
                throw new InvalidArgumentException('Panel credentials are invalid.');
            }

            $normalized[$key] = $value;
        }
        ksort($normalized);

        return new self($normalized, json_encode($normalized, JSON_THROW_ON_ERROR));
    }
}

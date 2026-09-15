<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

final class CatalogPayloadHash
{
    /** @param array<string, mixed> $payload */
    public static function make(array $payload): string
    {
        return hash('sha256', json_encode(self::normalize($payload), JSON_THROW_ON_ERROR));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}

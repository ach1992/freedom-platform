<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use RuntimeException;

final readonly class PanelPayloadHasher
{
    public function __construct(private string $key)
    {
        if ($key === '') {
            throw new RuntimeException('Panel mutation HMAC key is unavailable.');
        }
    }

    /** @param array<string, mixed> $payload */
    public function mutation(array $payload): string
    {
        return hash_hmac(
            'sha256',
            "panels\0mutation\0".json_encode($this->normalize($payload), JSON_THROW_ON_ERROR),
            $this->key,
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function normalize(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalize($value);
            }
        }

        return $payload;
    }
}

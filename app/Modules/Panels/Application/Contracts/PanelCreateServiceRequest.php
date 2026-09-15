<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PanelCreateServiceRequest
{
    public string $operationId;

    public string $idempotencyKey;

    public string $username;

    public string $targetReference;

    public ?int $dataLimitBytes;

    /** @var array<string, scalar|null> */
    public array $validatedAttributes;

    /** @param array<array-key, mixed> $validatedAttributes */
    public function __construct(
        string $operationId,
        string $idempotencyKey,
        string $username,
        string $targetReference,
        ?int $dataLimitBytes,
        public ?DateTimeImmutable $expiresAt,
        array $validatedAttributes,
    ) {
        $this->operationId = self::requiredValue($operationId, 128, 'Panel operation identifier');
        $this->idempotencyKey = self::requiredValue($idempotencyKey, 128, 'Panel idempotency key');
        $this->username = self::requiredValue($username, 191, 'Panel username');
        $this->targetReference = self::requiredValue($targetReference, 191, 'Panel target reference');
        if ($dataLimitBytes !== null && $dataLimitBytes < 1) {
            throw new InvalidArgumentException('Panel data limit must be positive or unlimited.');
        }

        $this->dataLimitBytes = $dataLimitBytes;
        $this->validatedAttributes = self::normalizeAttributes($validatedAttributes);
    }

    private static function requiredValue(string $value, int $maximumLength, string $field): string
    {
        if ($value === ''
            || $value !== trim($value)
            || mb_strlen($value) > $maximumLength
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException($field.' is invalid.');
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, scalar|null>
     */
    private static function normalizeAttributes(array $attributes): array
    {
        $normalized = [];
        foreach ($attributes as $key => $value) {
            if (! is_string($key) || preg_match('/\A[a-z0-9_.-]{1,64}\z/', $key) !== 1) {
                throw new InvalidArgumentException('Panel validated attribute key is invalid.');
            }
            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('Panel validated attribute value is invalid.');
            }
            if (is_string($value)
                && (mb_strlen($value) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1)
            ) {
                throw new InvalidArgumentException('Panel validated attribute value is invalid.');
            }
            $normalized[$key] = $value;
        }
        ksort($normalized);

        return $normalized;
    }
}

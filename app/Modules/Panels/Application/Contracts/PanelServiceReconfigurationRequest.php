<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

use InvalidArgumentException;

final readonly class PanelServiceReconfigurationRequest
{
    public string $operationId;

    public string $idempotencyKey;

    public string $remoteId;

    public string $targetPlanOfferingCode;

    public string $targetReference;

    public string $targetProtocolProfileCode;

    /** @var array<string, scalar|null> */
    public array $validatedAttributes;

    /** @param array<array-key,mixed> $validatedAttributes */
    public function __construct(
        string $operationId,
        string $idempotencyKey,
        string $remoteId,
        string $targetPlanOfferingCode,
        string $targetReference,
        string $targetProtocolProfileCode,
        array $validatedAttributes,
    ) {
        $this->operationId = self::requiredValue($operationId, 128, 'Panel reconfiguration operation identifier');
        $this->idempotencyKey = self::requiredValue($idempotencyKey, 128, 'Panel reconfiguration idempotency key');
        $this->remoteId = self::requiredValue($remoteId, 512, 'Panel reconfiguration remote Service identifier');
        $this->targetPlanOfferingCode = self::identifier($targetPlanOfferingCode, 64, 'Panel reconfiguration Plan Offering code');
        $this->targetReference = self::requiredValue($targetReference, 191, 'Panel reconfiguration target reference');
        $this->targetProtocolProfileCode = self::identifier($targetProtocolProfileCode, 128, 'Panel reconfiguration protocol profile code');
        $this->validatedAttributes = self::normalizeAttributes($validatedAttributes);
    }

    private static function requiredValue(string $value, int $maximumLength, string $field): string
    {
        if ($value === '' || $value !== trim($value) || mb_strlen($value) > $maximumLength
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException($field.' is invalid.');
        }

        return $value;
    }

    private static function identifier(string $value, int $maximumLength, string $field): string
    {
        if (preg_match('/\A[a-z0-9_.-]{1,'.$maximumLength.'}\z/', $value) !== 1) {
            throw new InvalidArgumentException($field.' is invalid.');
        }

        return $value;
    }

    /**
     * @param  array<array-key,mixed>  $attributes
     * @return array<string,scalar|null>
     */
    private static function normalizeAttributes(array $attributes): array
    {
        $normalized = [];
        foreach ($attributes as $key => $value) {
            if (! is_string($key) || preg_match('/\A[a-z0-9_.-]{1,64}\z/', $key) !== 1) {
                throw new InvalidArgumentException('Panel reconfiguration validated attribute key is invalid.');
            }
            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('Panel reconfiguration validated attribute value is invalid.');
            }
            if (is_string($value)
                && (mb_strlen($value) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1)) {
                throw new InvalidArgumentException('Panel reconfiguration validated attribute value is invalid.');
            }
            $normalized[$key] = $value;
        }
        ksort($normalized);

        return $normalized;
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Application;

use InvalidArgumentException;
use JsonException;

final readonly class SafeOutboxPayload
{
    /**
     * @requirement PAY-003 OPS-001 SEC-008
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEY_PARTS = [
        'authorization',
        'credential',
        'gift_card_code',
        'national_id',
        'otp',
        'password',
        'private_key',
        'secret',
        'subscription_link',
        'token',
    ];

    /** @param  array<string, mixed>  $values */
    public function __construct(private array $values)
    {
        $this->validate($values);

        if (strlen($this->json()) > 65_536) {
            throw new InvalidArgumentException('Outbox payload exceeds the 64 KiB safety limit.');
        }
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    /** @throws JsonException */
    public function json(): string
    {
        return json_encode($this->canonicalize($this->values), JSON_THROW_ON_ERROR);
    }

    /** @throws JsonException */
    public function hash(): string
    {
        return hash('sha256', $this->json());
    }

    /** @param  array<array-key, mixed>  $values */
    private function validate(array $values): void
    {
        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            foreach (self::FORBIDDEN_KEY_PARTS as $part) {
                if (str_contains($normalizedKey, $part)) {
                    throw new InvalidArgumentException("Sensitive key is forbidden in outbox payload: {$normalizedKey}");
                }
            }

            if (is_array($value)) {
                $this->validate($value);
            } elseif (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Outbox payload values must be scalar, null, or arrays.');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     *
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values, SORT_STRING);
        }

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->canonicalize($value);
            }
        }

        return $values;
    }
}

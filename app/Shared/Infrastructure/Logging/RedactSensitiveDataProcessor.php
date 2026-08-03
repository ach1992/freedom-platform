<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\LogRecord;
use Throwable;

final class RedactSensitiveDataProcessor
{
    /** @requirement OPS-001 SEC-001 SEC-008 */
    private const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const SENSITIVE_KEY_PARTS = [
        'authorization',
        'bot_token',
        'card_pan',
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

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->sanitizeMessage($record->message),
            context: $this->sanitize($record->context),
            extra: $this->sanitize($record->extra),
        );
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    public function sanitize(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if ($this->isSensitiveKey($normalizedKey)) {
                $sanitized[$key] = self::REDACTED;

                continue;
            }

            $sanitized[$key] = match (true) {
                is_array($value) => $this->sanitize($value),
                $value instanceof Throwable => [
                    'class' => $value::class,
                    'code' => (string) $value->getCode(),
                ],
                is_string($value) => $this->sanitizeMessage($value),
                default => $value,
            };
        }

        return $sanitized;
    }

    public function sanitizeMessage(string $message): string
    {
        $patterns = [
            '/\b(Bearer)\s+[^\s,;]+/i' => '$1 '.self::REDACTED,
            '/\b(authorization|password|secret|token|api[_-]?key)\s*[:=]\s*[^\s,;]+/i' => '$1='.self::REDACTED,
            '/([?&](?:authorization|password|secret|token|api[_-]?key|code)=)[^&\s]+/i' => '$1'.self::REDACTED,
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $message);
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($key, $part)) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use InvalidArgumentException;

final readonly class KavenegarSmsConfiguration
{
    private function __construct(
        public string $apiKey,
        public ?string $sender,
        public int $timeoutSeconds,
    ) {}

    /** @param array<string, mixed> $configuration */
    public static function fromArray(array $configuration, int $defaultTimeoutSeconds = 15): self
    {
        $apiKey = $configuration['api_key'] ?? null;

        if (! is_string($apiKey)
            || preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $apiKey) !== 1
        ) {
            throw new InvalidArgumentException('Kavenegar API key is invalid.');
        }

        $sender = $configuration['sender'] ?? null;
        $sender = is_string($sender) ? trim($sender) : null;

        if ($sender === '') {
            $sender = null;
        }

        if ($sender !== null && preg_match('/\A\+?[0-9]{3,31}\z/', $sender) !== 1) {
            throw new InvalidArgumentException('Kavenegar sender is invalid.');
        }

        $timeoutSeconds = $configuration['timeout_seconds'] ?? $defaultTimeoutSeconds;

        if (! is_numeric($timeoutSeconds) || (int) $timeoutSeconds < 1 || (int) $timeoutSeconds > 30) {
            throw new InvalidArgumentException('Kavenegar timeout must be between 1 and 30 seconds.');
        }

        return new self($apiKey, $sender, (int) $timeoutSeconds);
    }

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array
    {
        return [
            'api_key' => '[REDACTED]',
            'sender' => $this->sender === null ? null : '[REDACTED]',
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }
}

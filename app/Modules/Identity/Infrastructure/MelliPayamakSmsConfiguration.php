<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use InvalidArgumentException;

final readonly class MelliPayamakSmsConfiguration
{
    private function __construct(
        public string $username,
        public string $password,
        public string $sender,
        public int $timeoutSeconds,
    ) {}

    /** @param array<string, mixed> $configuration */
    public static function fromArray(array $configuration, int $defaultTimeoutSeconds = 15): self
    {
        $username = self::printableString($configuration['username'] ?? null, 'username', 191, true);
        $password = self::printableString($configuration['password'] ?? null, 'password', 512, false);
        $sender = self::printableString($configuration['sender'] ?? null, 'sender', 32, true);
        $timeoutSeconds = self::timeout($configuration['timeout_seconds'] ?? $defaultTimeoutSeconds);

        if (preg_match('/\A\+?[0-9]{3,31}\z/', $sender) !== 1) {
            throw new InvalidArgumentException('Melli Payamak sender is invalid.');
        }

        return new self($username, $password, $sender, $timeoutSeconds);
    }

    /** @return array<string, int|string> */
    public function __debugInfo(): array
    {
        return [
            'username' => '[REDACTED]',
            'password' => '[REDACTED]',
            'sender' => '[REDACTED]',
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }

    private static function printableString(mixed $value, string $name, int $maximumLength, bool $trim): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("Melli Payamak {$name} is required.");
        }

        $normalized = $trim ? trim($value) : $value;

        if ($normalized === ''
            || mb_strlen($normalized) > $maximumLength
            || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1
        ) {
            throw new InvalidArgumentException("Melli Payamak {$name} is invalid.");
        }

        return $normalized;
    }

    private static function timeout(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 1 || (int) $value > 30) {
            throw new InvalidArgumentException('Melli Payamak timeout must be between 1 and 30 seconds.');
        }

        return (int) $value;
    }
}

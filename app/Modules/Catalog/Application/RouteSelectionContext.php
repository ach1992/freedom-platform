<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use InvalidArgumentException;

final readonly class RouteSelectionContext
{
    public string $commandKey;

    public string $sourceCode;

    public string $reasonCode;

    public function __construct(
        string $commandKey,
        public string $correlationId,
        string $sourceCode,
        string $reasonCode,
    ) {
        $this->commandKey = self::commandKey($commandKey);
        $this->sourceCode = self::code($sourceCode, 'Route selection source code');
        $this->reasonCode = self::code($reasonCode, 'Route selection reason code');
        if (preg_match('/\A[A-Za-z0-9:_-]{16,64}\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('Route selection correlation ID is invalid.');
        }
    }

    private static function commandKey(string $value): string
    {
        $normalized = trim($value);
        if (strlen($normalized) < 24
            || strlen($normalized) > 128
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_.-]+\z/', $normalized) !== 1
        ) {
            throw new InvalidArgumentException('Route selection command key is invalid.');
        }

        return $normalized;
    }

    private static function code(string $value, string $label): string
    {
        $normalized = strtolower(trim($value));
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }

        return $normalized;
    }
}

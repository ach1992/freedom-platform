<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use InvalidArgumentException;

final readonly class TrialContext
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
        $normalizedCommandKey = trim($commandKey);
        if (strlen($normalizedCommandKey) < 24
            || strlen($normalizedCommandKey) > 128
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_.-]+\z/', $normalizedCommandKey) !== 1
        ) {
            throw new InvalidArgumentException('Trial command key is invalid.');
        }
        if (preg_match('/\A[A-Za-z0-9:_-]{16,64}\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('Trial correlation ID is invalid.');
        }

        $this->commandKey = $normalizedCommandKey;
        $this->sourceCode = self::code($sourceCode, 'Trial source code');
        $this->reasonCode = self::code($reasonCode, 'Trial reason code');
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

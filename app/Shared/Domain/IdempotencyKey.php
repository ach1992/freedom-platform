<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use InvalidArgumentException;
use Stringable;

final readonly class IdempotencyKey implements Stringable
{
    /** @requirement PAY-003 ARCH-004 */
    private function __construct(private string $value) {}

    public static function fromParts(string $namespace, string ...$parts): self
    {
        if (preg_match('/\A[a-z][a-z0-9._-]{1,63}\z/', $namespace) !== 1) {
            throw new InvalidArgumentException('Invalid idempotency namespace.');
        }

        if ($parts === [] || in_array('', $parts, true)) {
            throw new InvalidArgumentException('Idempotency key parts must be non-empty.');
        }

        $canonical = '';

        foreach ($parts as $part) {
            $canonical .= pack('N', strlen($part)).$part;
        }

        return new self($namespace.':'.hash('sha256', $canonical));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

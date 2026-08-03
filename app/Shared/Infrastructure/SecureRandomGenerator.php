<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Application\RandomGenerator;
use InvalidArgumentException;

final class SecureRandomGenerator implements RandomGenerator
{
    public function bytes(int $length): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('Random byte length must be positive.');
        }

        return random_bytes($length);
    }

    public function integer(int $minimum, int $maximum): int
    {
        if ($minimum > $maximum) {
            throw new InvalidArgumentException('Random integer bounds are invalid.');
        }

        return random_int($minimum, $maximum);
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Application;

interface RandomGenerator
{
    public function bytes(int $length): string;

    public function integer(int $minimum, int $maximum): int;
}

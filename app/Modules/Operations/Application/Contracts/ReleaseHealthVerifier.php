<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface ReleaseHealthVerifier
{
    public function verify(string $releasePath): void;
}

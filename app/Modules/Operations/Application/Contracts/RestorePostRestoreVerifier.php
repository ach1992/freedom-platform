<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface RestorePostRestoreVerifier
{
    /** @return array<string, int|bool|string> */
    public function verify(?string $releasePath = null): array;
}

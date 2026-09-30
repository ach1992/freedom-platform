<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface RestoreRuntimeHealthVerifier
{
    public function verify(string $expectedAuthorityFingerprint, ?string $releasePath = null): void;
}

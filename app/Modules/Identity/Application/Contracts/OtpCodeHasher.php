<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Contracts;

use App\Modules\Identity\Application\OtpCodeHash;

interface OtpCodeHasher
{
    public function hash(string $challengeId, string $code): OtpCodeHash;

    public function verify(string $challengeId, string $code, string $expectedHash): bool;
}

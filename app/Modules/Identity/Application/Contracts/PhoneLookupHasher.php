<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Contracts;

use App\Modules\Identity\Application\PhoneLookupHash;
use App\Modules\Identity\Domain\IranianMobileNumber;

interface PhoneLookupHasher
{
    public function hash(IranianMobileNumber $number): PhoneLookupHash;

    public function hashOpaque(string $value): string;
}

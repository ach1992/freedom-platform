<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\Contracts\PhoneLookupHasher;
use App\Modules\Identity\Application\PhoneLookupHash;
use App\Modules\Identity\Domain\IranianMobileNumber;
use InvalidArgumentException;

final readonly class HmacPhoneLookupHasher implements PhoneLookupHasher
{
    public function __construct(
        private string $key,
        private int $keyVersion,
    ) {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('Phone lookup HMAC key must contain at least 32 bytes.');
        }

        if ($keyVersion < 1) {
            throw new InvalidArgumentException('Phone lookup HMAC key version must be positive.');
        }
    }

    public function hash(IranianMobileNumber $number): PhoneLookupHash
    {
        return new PhoneLookupHash(
            hash_hmac('sha256', $number->e164(), $this->key),
            $this->keyVersion,
        );
    }

    public function hashOpaque(string $value): string
    {
        if ($value === '') {
            throw new InvalidArgumentException('Opaque lookup value must not be empty.');
        }

        return hash_hmac('sha256', $value, $this->key);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use InvalidArgumentException;
use Stringable;

final readonly class IranianMobileNumber implements Stringable
{
    private string $e164;

    private function __construct(string $e164)
    {
        $this->e164 = $e164;
    }

    /** @requirement ONB-004 SEC-003 DAT-003 */
    public static function fromString(string $value): self
    {
        $digits = strtr(trim($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        $digits = preg_replace('/[\s\-()]+/u', '', $digits);

        if (! is_string($digits)) {
            throw new InvalidArgumentException('Iranian mobile number normalization failed.');
        }

        if (preg_match('/\A09[0-9]{9}\z/', $digits) === 1) {
            return new self('+98'.substr($digits, 1));
        }

        if (preg_match('/\A989[0-9]{9}\z/', $digits) === 1) {
            return new self('+'.$digits);
        }

        if (preg_match('/\A\+989[0-9]{9}\z/', $digits) === 1) {
            return new self($digits);
        }

        throw new InvalidArgumentException('A structurally valid Iranian mobile number is required.');
    }

    public function e164(): string
    {
        return $this->e164;
    }

    public function national(): string
    {
        return '0'.substr($this->e164, 3);
    }

    public function masked(): string
    {
        return substr($this->e164, 0, 5).'****'.substr($this->e164, -4);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->e164, $other->e164);
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}

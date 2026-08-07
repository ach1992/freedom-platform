<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain;

use DomainException;
use OverflowException;

final readonly class IrrMoney
{
    private function __construct(public int $amount)
    {
        if ($amount < 0) {
            throw new DomainException('IRR amount cannot be negative.');
        }
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromInt(int $amount): self
    {
        return new self($amount);
    }

    public static function positive(int $amount): self
    {
        if ($amount < 1) {
            throw new DomainException('IRR amount must be positive.');
        }

        return new self($amount);
    }

    public function add(self $other): self
    {
        if ($other->amount > PHP_INT_MAX - $this->amount) {
            throw new OverflowException('IRR amount overflow.');
        }

        return new self($this->amount + $other->amount);
    }

    public function subtract(self $other): self
    {
        if ($other->amount > $this->amount) {
            throw new DomainException('IRR amount cannot become negative.');
        }

        return new self($this->amount - $other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use InvalidArgumentException;
use JsonSerializable;

final readonly class Money implements JsonSerializable
{
    /** @requirement DAT-002 */
    private const IRR_PER_TOMAN = 10;

    private function __construct(
        private int $amount,
        private string $currency,
    ) {}

    public static function irr(int $amount): self
    {
        return new self($amount, 'IRR');
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function tomanDecimal(): string
    {
        if ($this->currency !== 'IRR') {
            throw new InvalidArgumentException('Only IRR can be converted to Toman.');
        }

        $absolute = abs($this->amount);
        $whole = intdiv($absolute, self::IRR_PER_TOMAN);
        $fraction = $absolute % self::IRR_PER_TOMAN;
        $sign = $this->amount < 0 ? '-' : '';

        return $fraction === 0
            ? $sign.(string) $whole
            : $sign.$whole.'.'.$fraction;
    }

    /** @return array{amount: int, currency: string} */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Money currencies must match.');
        }
    }
}

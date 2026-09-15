<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\QuoteOverrideSource;
use DateTimeImmutable;
use InvalidArgumentException;

/** @requirement BUY-002 DAT-002 QUA-001 */
final readonly class QuotePricingInput
{
    public ?string $overrideReferenceCode;

    public ?string $discountReferenceCode;

    public function __construct(
        public QuoteOverrideSource $overrideSource,
        ?string $overrideReferenceCode,
        public ?int $overridePriceIrr,
        ?string $discountReferenceCode,
        public int $discountIrr,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($overrideSource === QuoteOverrideSource::None) {
            if ($overrideReferenceCode !== null || $overridePriceIrr !== null) {
                throw new InvalidArgumentException('Quote without an override cannot contain override data.');
            }
            $this->overrideReferenceCode = null;
        } else {
            if ($overridePriceIrr === null || $overridePriceIrr < 0) {
                throw new InvalidArgumentException('Quote override price must be non-negative integer IRR.');
            }
            $this->overrideReferenceCode = self::reference($overrideReferenceCode, 'Quote override reference');
        }

        if ($discountIrr < 0) {
            throw new InvalidArgumentException('Quote discount must be non-negative integer IRR.');
        }
        if ($discountIrr === 0) {
            if ($discountReferenceCode !== null) {
                throw new InvalidArgumentException('Zero quote discount cannot contain a discount reference.');
            }
            $this->discountReferenceCode = null;
        } else {
            $this->discountReferenceCode = self::reference($discountReferenceCode, 'Quote discount reference');
        }
    }

    private static function reference(?string $value, string $label): string
    {
        if ($value === null) {
            throw new InvalidArgumentException($label.' is required.');
        }
        $normalized = strtolower(trim($value));
        if (preg_match('/\A[a-z0-9_.:-]{1,64}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }

        return $normalized;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Domain;

use InvalidArgumentException;

final readonly class BenefitCodeCampaignCode
{
    public function __construct(public string $value)
    {
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $value) !== 1) {
            throw new InvalidArgumentException('Benefit code campaign code is invalid.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

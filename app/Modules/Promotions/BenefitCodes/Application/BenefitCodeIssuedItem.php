<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Application;

final readonly class BenefitCodeIssuedItem
{
    public function __construct(
        public string $codePublicId,
        public string $displayMask,
        public ?string $fullCode,
    ) {}
}

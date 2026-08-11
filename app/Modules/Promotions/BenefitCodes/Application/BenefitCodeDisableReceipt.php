<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Application;

final readonly class BenefitCodeDisableReceipt
{
    public function __construct(
        public int $disableId,
        public string $disablePublicId,
        public string $codePublicId,
        public bool $replayed,
    ) {}
}

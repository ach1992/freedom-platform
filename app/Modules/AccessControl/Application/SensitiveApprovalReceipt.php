<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use App\Modules\AccessControl\Domain\SensitiveApprovalState;

final readonly class SensitiveApprovalReceipt
{
    public function __construct(
        public string $action,
        public string $approvalId,
        public SensitiveApprovalState $state,
        public bool $consumed,
        public bool $changed,
        public bool $replayed = false,
    ) {}
}

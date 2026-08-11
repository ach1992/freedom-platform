<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

interface PanelApprovalGate
{
    public function consume(
        string $approvalId,
        string $action,
        string $targetType,
        string $targetId,
        PanelChangeContext $context,
    ): void;
}

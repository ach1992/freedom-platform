<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\AccessControl\Application\SensitiveActionApprovalService;

final readonly class SensitivePanelApprovalGate implements PanelApprovalGate
{
    public function __construct(private SensitiveActionApprovalService $approvals) {}

    public function consume(
        string $approvalId,
        string $action,
        string $targetType,
        string $targetId,
        PanelChangeContext $context,
    ): void {
        $this->approvals->consume(
            $approvalId,
            $action,
            $targetType,
            $targetId,
            $context->accessContext(),
        );
    }
}

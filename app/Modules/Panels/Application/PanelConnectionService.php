<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Shared\Application\Clock;
use Illuminate\Contracts\Encryption\StringEncrypter;

final readonly class PanelConnectionService
{
    use PanelConnectionDefinitionOperations;
    use PanelConnectionLifecycleOperations;
    use PanelConnectionServiceSupport;

    private const TARGET_TYPE = 'panel_connection';

    public function __construct(
        private PanelMutationExecutor $executor,
        private PanelMutationAudit $audit,
        private PanelApprovalGate $approvals,
        private PanelPayloadHasher $hasher,
        private StringEncrypter $encrypter,
        private Clock $clock,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Shared\Application\Clock;
use Illuminate\Contracts\Encryption\StringEncrypter;

final readonly class PanelInventoryService
{
    use PanelInventoryServiceSupport;
    use ProtocolProfileOperations;
    use SalesServerOperations;
    use ServiceTargetOperations;

    private const PROTOCOL_PROFILE_TARGET = 'panel_protocol_profile';

    private const SERVICE_TARGET = 'panel_service_target';

    private const SALES_SERVER_TARGET = 'sales_server';

    public function __construct(
        private PanelMutationExecutor $executor,
        private PanelMutationAudit $audit,
        private PanelApprovalGate $approvals,
        private PanelPayloadHasher $hasher,
        private StringEncrypter $encrypter,
        private Clock $clock,
    ) {}
}

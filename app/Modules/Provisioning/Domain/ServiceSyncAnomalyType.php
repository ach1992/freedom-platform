<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

enum ServiceSyncAnomalyType: string
{
    case MissingRemote = 'missing_remote';
    case ExpiredLocalActiveRemote = 'expired_local_active_remote';
    case LifecycleMismatch = 'lifecycle_mismatch';
    case RemoteIdentityMismatch = 'remote_identity_mismatch';
    case UnexpectedEntitlement = 'unexpected_entitlement';
}

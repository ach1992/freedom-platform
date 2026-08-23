<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

enum ServiceSyncResolutionAction: string
{
    case AdoptRemoteState = 'adopt_remote_state';
    case Reprovision = 'reprovision';
    case FlagManualReview = 'flag_manual_review';
    case Ignore = 'ignore';
}

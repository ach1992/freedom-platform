<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

enum ServiceDeliveryEffectState: string
{
    case Prepared = 'prepared';
    case Sending = 'sending';
    case Succeeded = 'succeeded';
    case Uncertain = 'uncertain';
    case FailedFinal = 'failed_final';
}

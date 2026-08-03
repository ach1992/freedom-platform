<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

enum PanelOperationOutcome: string
{
    case Success = 'success';
    case DefinitiveFailure = 'definitive_failure';
    case RetryableFailure = 'retryable_failure';
    case UncertainResult = 'uncertain_result';
}

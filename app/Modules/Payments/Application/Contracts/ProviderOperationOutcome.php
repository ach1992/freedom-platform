<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

enum ProviderOperationOutcome: string
{
    case Success = 'success';
    case DefinitiveFailure = 'definitive_failure';
    case RetryableFailure = 'retryable_failure';
    case UncertainResult = 'uncertain_result';
}

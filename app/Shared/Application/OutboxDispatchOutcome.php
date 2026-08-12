<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * The dispatcher owns durable transport transitions only. Domain adapters remain
 * responsible for mapping their provider-specific result to one of these values.
 */
enum OutboxDispatchOutcome: string
{
    case Success = 'success';
    case DefinitiveFailure = 'definitive_failure';
    case RetryableFailure = 'retryable_failure';
    case UncertainResult = 'uncertain_result';
}

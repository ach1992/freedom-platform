<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

enum TelegramMutationOutcome: string
{
    case Success = 'success';
    /** Provider conclusively rejected the request before any external mutation. */
    case DefinitiveNoEffectRetryable = 'definitive_no_effect_retryable';
    case DefinitiveFailure = 'definitive_failure';
    case RetryAfter = 'retry_after';
    case UncertainResult = 'uncertain_result';
}

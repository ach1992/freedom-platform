<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

enum TelegramMutationOutcome: string
{
    case Success = 'success';
    case RetryableFailure = 'retryable_failure';
    case DefinitiveFailure = 'definitive_failure';
    case RetryAfter = 'retry_after';
    case UncertainResult = 'uncertain_result';
}

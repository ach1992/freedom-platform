<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Domain;

enum TelegramDeliveryOperationState: string
{
    case Prepared = 'prepared';
    case Sending = 'sending';
    case Retryable = 'retryable';
    case Succeeded = 'succeeded';
    case FailedFinal = 'failed_final';
    case Uncertain = 'uncertain';
    case ReviewRequired = 'review_required';
}

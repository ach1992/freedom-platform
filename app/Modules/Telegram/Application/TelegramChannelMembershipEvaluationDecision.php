<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

enum TelegramChannelMembershipEvaluationDecision: string
{
    case NotRequired = 'not_required';
    case Satisfied = 'satisfied';
    case Unsatisfied = 'unsatisfied';
    case ManualReview = 'manual_review';
    case ConfigurationChanged = 'configuration_changed';
}

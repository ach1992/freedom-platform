<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramActionMembershipPreflight
{
    public function __construct(
        public TelegramChannelMembershipEvaluationDecision $decision,
        public string $configurationHash,
        public string $action,
        public string $accountType,
        public ?TelegramProtectedPresentationReference $joinReference,
    ) {
        if (preg_match('/\A[0-9a-f]{64}\z/', $configurationHash) !== 1) {
            throw new InvalidArgumentException('Telegram action membership configuration hash is invalid.');
        }
        if (! in_array($action, [
            'bot_entry', 'trial', 'purchase', 'gift_code_use', 'referral_reward',
            'ticket_creation', 'service_view', 'support_view',
        ], true)) {
            throw new InvalidArgumentException('Telegram action membership action is invalid.');
        }
        if (! in_array($accountType, ['customer', 'agent'], true)) {
            throw new InvalidArgumentException('Telegram action membership account type is invalid.');
        }
        if ($decision !== TelegramChannelMembershipEvaluationDecision::Unsatisfied && $joinReference !== null) {
            throw new InvalidArgumentException('Telegram action membership join reference is invalid for the decision.');
        }
    }

    public function allowsAction(): bool
    {
        return in_array($this->decision, [
            TelegramChannelMembershipEvaluationDecision::NotRequired,
            TelegramChannelMembershipEvaluationDecision::Satisfied,
        ], true);
    }
}

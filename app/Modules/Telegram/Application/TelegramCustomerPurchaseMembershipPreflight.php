<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseMembershipPreflight
{
    public function __construct(
        public TelegramChannelMembershipEvaluationDecision $decision,
        public string $membershipConfigurationHash,
        public string $offeringCode,
        public int $offeringVersion,
        public string $offeringConfigurationHash,
        public string $accountType,
        public ?TelegramProtectedPresentationReference $joinReference,
    ) {
        if (preg_match('/\A[0-9a-f]{64}\z/', $membershipConfigurationHash) !== 1
            || preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,63}\z/i', $offeringCode) !== 1
            || $offeringVersion < 1
            || preg_match('/\A[0-9a-f]{64}\z/', $offeringConfigurationHash) !== 1
            || ! in_array($accountType, ['customer', 'agent'], true)) {
            throw new InvalidArgumentException('Telegram purchase membership preflight identity is invalid.');
        }

        if ($decision === TelegramChannelMembershipEvaluationDecision::Unsatisfied) {
            if ($joinReference === null || ! $joinReference->isMembershipJoinPrompt()) {
                throw new InvalidArgumentException('Unsatisfied Telegram purchase membership requires a protected join reference.');
            }

            return;
        }

        if ($joinReference !== null) {
            throw new InvalidArgumentException('Telegram purchase membership join reference is only valid when unsatisfied.');
        }
    }

    public function allowsQuote(): bool
    {
        return in_array($this->decision, [
            TelegramChannelMembershipEvaluationDecision::NotRequired,
            TelegramChannelMembershipEvaluationDecision::Satisfied,
        ], true);
    }
}

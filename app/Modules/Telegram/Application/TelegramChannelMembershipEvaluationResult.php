<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramChannelMembershipEvaluationResult
{
    /** @param list<TelegramChannelMembershipChannelEvidence> $channels */
    public function __construct(
        public TelegramChannelMembershipRequirementPlan $plan,
        public TelegramChannelMembershipEvaluationDecision $decision,
        public ?int $telegramUserId,
        public array $channels,
    ) {
        if (! $plan->required) {
            if ($decision !== TelegramChannelMembershipEvaluationDecision::NotRequired
                || $telegramUserId !== null
                || $channels !== []) {
                throw new InvalidArgumentException('No-requirement Telegram membership evaluation is invalid.');
            }

            return;
        }

        if ($decision === TelegramChannelMembershipEvaluationDecision::NotRequired
            || $telegramUserId === null
            || $telegramUserId < 1
            || count($channels) !== count($plan->channels)) {
            throw new InvalidArgumentException('Required Telegram membership evaluation is incomplete.');
        }

        foreach ($channels as $index => $evidence) {
            $planned = $plan->channels[$index] ?? null;
            if (! $planned instanceof TelegramChannelMembershipRequirementChannel
                || $evidence->requiredChannelId !== $planned->requiredChannelId
                || ! hash_equals($evidence->channelKey, $planned->channelKey)) {
                throw new InvalidArgumentException('Telegram membership evaluation evidence does not match the resolved plan.');
            }
        }
    }
}

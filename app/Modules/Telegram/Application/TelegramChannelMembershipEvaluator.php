<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\ProtectedTelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use DomainException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramChannelMembershipEvaluator
{
    public function __construct(
        private DatabaseManager $database,
        private TelegramChannelMembershipRuleResolver $resolver,
        private TelegramMembershipLookup $membershipLookup,
        private ProtectedTelegramDeliveryRuntime $runtime,
    ) {}

    /** @requirement ONB-003 CHN-001 SEC-001 SEC-003 QUA-001 */
    public function evaluate(TelegramChannelMembershipResolutionRequest $request): TelegramChannelMembershipEvaluationResult
    {
        $plan = $this->resolver->resolve($request);
        if (! $plan->required) {
            return new TelegramChannelMembershipEvaluationResult(
                $plan,
                TelegramChannelMembershipEvaluationDecision::NotRequired,
                null,
                [],
            );
        }

        $account = $this->telegramAccount($request->userId);
        $telegramUserId = $account['telegram_user_id'];
        $evidence = [];

        foreach ($plan->channels as $channel) {
            if ($channel->state !== 'active') {
                $lookup = new TelegramMembershipLookupResult(
                    TelegramMembershipEvidence::Unavailable,
                    'telegram_membership_channel_inactive',
                );
            } else {
                $lookup = $this->membershipLookup->lookup($channel->telegramChatId, $telegramUserId);
            }

            $evidence[] = new TelegramChannelMembershipChannelEvidence(
                $channel->requiredChannelId,
                $channel->channelKey,
                $lookup->evidence,
                $lookup->resultCode,
            );
        }

        $currentPlan = $this->resolver->resolve($request);
        $currentAccount = $this->telegramAccount($request->userId);
        if ($currentAccount['id'] !== $account['id'] || $currentAccount['telegram_user_id'] !== $telegramUserId) {
            throw new DomainException('Telegram membership evaluation identity changed during provider lookup.');
        }

        if (! $this->samePlan($plan, $currentPlan)) {
            return new TelegramChannelMembershipEvaluationResult(
                $plan,
                TelegramChannelMembershipEvaluationDecision::ConfigurationChanged,
                $telegramUserId,
                $evidence,
            );
        }

        return new TelegramChannelMembershipEvaluationResult(
            $plan,
            $this->decision($plan, $evidence),
            $telegramUserId,
            $evidence,
        );
    }

    /** @return array{id:int,telegram_user_id:int} */
    private function telegramAccount(int $userId): array
    {
        $botId = $this->runtime->botId();
        if (filter_var($botId, FILTER_VALIDATE_INT) === false || (int) $botId < 1) {
            throw new RuntimeException('Telegram membership evaluation bot identity is invalid.');
        }

        /** @var object{id:int|string,telegram_user_id:int|string}|null $account */
        $account = $this->database->connection()->table('telegram_accounts')
            ->where('bot_id', (int) $botId)
            ->where('user_id', $userId)
            ->where('is_bot', false)
            ->first(['id', 'telegram_user_id']);
        if ($account === null || (int) $account->telegram_user_id < 1) {
            throw new DomainException('Telegram membership evaluation requires a synchronized Telegram identity.');
        }

        return [
            'id' => (int) $account->id,
            'telegram_user_id' => (int) $account->telegram_user_id,
        ];
    }

    private function samePlan(
        TelegramChannelMembershipRequirementPlan $before,
        TelegramChannelMembershipRequirementPlan $after,
    ): bool {
        return $after->required
            && $before->userId === $after->userId
            && $before->subjectAccountType === $after->subjectAccountType
            && $before->action === $after->action
            && $before->planOfferingId === $after->planOfferingId
            && $before->ruleId === $after->ruleId
            && $before->ruleVersion === $after->ruleVersion
            && hash_equals($before->configurationHash, $after->configurationHash);
    }

    /** @param list<TelegramChannelMembershipChannelEvidence> $evidence */
    private function decision(
        TelegramChannelMembershipRequirementPlan $plan,
        array $evidence,
    ): TelegramChannelMembershipEvaluationDecision {
        if ($plan->matchMode === 'all') {
            foreach ($evidence as $item) {
                if ($item->evidence === TelegramMembershipEvidence::NotMember) {
                    return TelegramChannelMembershipEvaluationDecision::Unsatisfied;
                }
            }
            if ($this->allEvidenceIs($evidence, TelegramMembershipEvidence::Member)) {
                return TelegramChannelMembershipEvaluationDecision::Satisfied;
            }

            return $this->unavailableDecision($plan->failurePolicy);
        }

        if ($plan->matchMode === 'any') {
            foreach ($evidence as $item) {
                if ($item->evidence === TelegramMembershipEvidence::Member) {
                    return TelegramChannelMembershipEvaluationDecision::Satisfied;
                }
            }
            if ($this->allEvidenceIs($evidence, TelegramMembershipEvidence::NotMember)) {
                return TelegramChannelMembershipEvaluationDecision::Unsatisfied;
            }

            return $this->unavailableDecision($plan->failurePolicy);
        }

        throw new RuntimeException('Telegram membership evaluation match mode is invalid.');
    }

    /**
     * @param  list<TelegramChannelMembershipChannelEvidence>  $evidence
     */
    private function allEvidenceIs(array $evidence, TelegramMembershipEvidence $expected): bool
    {
        foreach ($evidence as $item) {
            if ($item->evidence !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function unavailableDecision(?string $failurePolicy): TelegramChannelMembershipEvaluationDecision
    {
        return match ($failurePolicy) {
            'fail_open' => TelegramChannelMembershipEvaluationDecision::Satisfied,
            'fail_closed' => TelegramChannelMembershipEvaluationDecision::Unsatisfied,
            'manual_review' => TelegramChannelMembershipEvaluationDecision::ManualReview,
            default => throw new RuntimeException('Telegram membership evaluation failure policy is invalid.'),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class TelegramActionMembershipRevalidator
{
    public function __construct(
        private TelegramChannelMembershipRuleResolver $resolver,
        private TelegramMembershipConfigurationFence $configurationFence,
    ) {}

    /**
     * Revalidates dynamic configuration/subject authority under the caller's
     * transaction. Provider evidence is intentionally obtained before the transaction.
     *
     * @requirement CHN-001 SEC-001 SEC-002 DAT-003 QUA-001
     */
    public function assertCurrentForUpdate(
        Connection $connection,
        int $actorUserId,
        int $subjectUserId,
        string $action,
        ?TelegramActionMembershipPreflight $preflight,
    ): void {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram action membership self access denied.');
        }
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Telegram action membership revalidation requires an active transaction.');
        }
        if ($preflight !== null && (! $preflight->allowsAction() || $preflight->action !== $action)) {
            throw new TelegramActionMembershipChanged('Telegram action membership preflight does not allow the action.');
        }

        $this->configurationFence->acquire($connection);
        $request = new TelegramChannelMembershipResolutionRequest(
            $subjectUserId,
            $action,
            null,
        );
        if ($preflight === null) {
            if ($this->resolver->hasPotentialCurrentRequirementForUpdate($request)) {
                throw new TelegramActionMembershipChanged('Telegram action membership preflight is required.');
            }

            return;
        }

        $plan = $this->resolver->resolveCurrentForUpdate($request);

        if ($plan->userId !== $subjectUserId
            || $plan->action !== $action
            || $plan->planOfferingId !== null
            || $plan->subjectAccountType !== $preflight->accountType
            || ! hash_equals($preflight->configurationHash, $plan->configurationHash)
            || ($preflight->decision === TelegramChannelMembershipEvaluationDecision::NotRequired && $plan->required)
            || ($preflight->decision === TelegramChannelMembershipEvaluationDecision::Satisfied && ! $plan->required)) {
            throw new TelegramActionMembershipChanged('Telegram action membership authority changed after provider verification.');
        }
    }
}

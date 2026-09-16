<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Revalidates dynamic channel-membership authority immediately before an
 * already-started Support interaction can read, present, or mutate protected
 * Support state. The Support handler remains the owner of business/session
 * semantics; this guard owns only membership freshness at dispatch time.
 */
final readonly class TelegramSupportMembershipFreshnessGuard
{
    private const STATE_HOME = 'support_home';

    private const STATE_CREATE_DESCRIPTION = 'support_create_description';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private TelegramChannelMembershipEvaluator $membership,
        private TelegramInteractionSessionService $sessions,
        private TelegramNavigationEntryGateway $navigationEntry,
    ) {}

    public function assertCurrent(TelegramInteractionAction $action): void
    {
        // Initial Support entry is already checked immediately before showHome().
        if ($action->sessionState === TelegramNavigationEntryGateway::STATE) {
            return;
        }

        // Leaving Support must remain possible even after membership is lost.
        if ($this->exitsSupport($action)) {
            return;
        }

        $this->assertMembership($action->userId, 'support_view');

        if ($this->requiresCurrentTicketCreation($action)) {
            $this->assertMembership($action->userId, 'ticket_creation');
        }
    }

    private function exitsSupport(TelegramInteractionAction $action): bool
    {
        if ($action->sessionState === self::STATE_HOME
            && ($action->kind === TelegramInteractionActionKind::Back
                || ($action->kind === TelegramInteractionActionKind::Callback
                    && $action->callbackAction === self::ACTION_BACK
                    && $action->callbackPayload === []))) {
            return true;
        }

        return $action->kind === TelegramInteractionActionKind::Message
            && $action->messageText !== null
            && $this->navigationEntry->matchesEntryCommand($action->messageText);
    }

    private function requiresCurrentTicketCreation(TelegramInteractionAction $action): bool
    {
        if ($action->sessionState !== self::STATE_CREATE_DESCRIPTION
            || $action->kind !== TelegramInteractionActionKind::Message) {
            return false;
        }

        if (! $action->replayed) {
            return true;
        }

        // A failed pre-mutation update keeps the current session at the bound
        // version and must reauthorize ticket_creation on retry. If a prior
        // attempt already advanced the session, the business effect either
        // committed or the handler will no-op as stale; only support_view is
        // relevant before any recovery presentation.
        $active = $this->sessions->activeForAccount($action->telegramAccountId);

        return $active === null
            || $active->publicId !== $action->sessionPublicId
            || $active->userId !== $action->userId
            || $active->version <= $action->sessionVersion;
    }

    private function assertMembership(int $userId, string $membershipAction): void
    {
        $result = $this->membership->evaluate(
            new TelegramChannelMembershipResolutionRequest($userId, $membershipAction),
        );

        if (! in_array($result->decision, [
            TelegramChannelMembershipEvaluationDecision::NotRequired,
            TelegramChannelMembershipEvaluationDecision::Satisfied,
        ], true)) {
            throw new AuthorizationException('Telegram Support membership requirement is not satisfied.');
        }
    }
}

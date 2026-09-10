<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Agents\Application\AgentApplicationService;
use App\Modules\Agents\Application\AgentApplicationSubmissionRejected;
use App\Modules\Agents\Application\AgentChangeContext;
use App\Modules\Customers\Application\CustomerAccountSummary;
use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Bounded Agent cooperation/status journey. Agent lifecycle authority remains
 * owned by Agents; purchase delegates to the canonical Telegram purchase flow.
 */
final readonly class TelegramAgentNavigationHandler
{
    private const STATE_AGENT = 'agent_cooperation';

    private const STATE_SUBMITTING = 'agent_cooperation_submitting';

    private const STATE_UNAVAILABLE = 'agent_cooperation_unavailable';

    private const ACTION_AGENT = 'navigation.agent';

    private const ACTION_SUBMIT = 'navigation.agent.submit';

    private const ACTION_PURCHASE = 'navigation.agent.purchase';

    private const ACTION_CANONICAL_PURCHASE = 'navigation.purchase';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private Translator $translator,
        private ConfidentialTelegramPresentationFactory $confidentialPresentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private CustomerAccountSummaryService $customers,
        private AgentApplicationService $applications,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === TelegramNavigationEntryGateway::STATE
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_AGENT)
            || ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_PURCHASE)
            || in_array($action->sessionState, [self::STATE_AGENT, self::STATE_UNAVAILABLE], true);
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_PURCHASE) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Agent purchase callback payload is invalid.');
            }
            $this->openPurchase($action);

            return;
        }

        if ($action->sessionState === TelegramNavigationEntryGateway::STATE) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Agent home callback payload is invalid.');
            }
            $this->showAgent($action);

            return;
        }

        if (! in_array($action->sessionState, [self::STATE_AGENT, self::STATE_UNAVAILABLE], true)) {
            throw new RuntimeException('Telegram Agent navigation state is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnHome($action);

                return;
            }
            if ($action->callbackAction === self::ACTION_SUBMIT && $action->callbackPayload === []) {
                $this->submitApplication($action);

                return;
            }

            throw new RuntimeException('Telegram Agent callback action is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnHome($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function showAgent(TelegramInteractionAction $action): void
    {
        $summary = $this->customers->forSelf($action->userId, $action->userId);
        if (! $this->canOpen($summary)) {
            throw new AuthorizationException('Telegram Agent journey access denied.');
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_AGENT,
                [],
                'tg-agent-open:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderStatus($action, $session->version, $summary, false);
    }

    private function openPurchase(TelegramInteractionAction $action): void
    {
        if ($action->replayed && $this->recoverAdvancedPurchase($action)) {
            return;
        }
        if ($action->sessionState !== self::STATE_AGENT) {
            throw new RuntimeException('Telegram Agent purchase callback state is unsupported.');
        }

        $summary = $this->customers->forSelf($action->userId, $action->userId);
        if (! $this->canPurchase($summary)) {
            $this->renderStatus($action, $action->sessionVersion, $summary, false);

            return;
        }

        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Callback,
            $action->requestKey.':agent-purchase',
            $action->botId,
            $action->updateId,
            $action->telegramAccountId,
            $action->userId,
            $action->telegramUserId,
            $action->sessionPublicId,
            $action->flow,
            TelegramNavigationEntryGateway::STATE,
            $action->sessionVersion,
            [],
            null,
            $action->callbackPublicId,
            self::ACTION_CANONICAL_PURCHASE,
            [],
            $action->replayed,
            $action->callbackAcceptedAt,
            $action->messageAcceptedAt,
        ));
    }

    private function recoverAdvancedPurchase(TelegramInteractionAction $action): bool
    {
        $session = $this->sessions->activeForAccount($action->telegramAccountId);

        return $session !== null
            && $session->publicId === $action->sessionPublicId
            && $session->userId === $action->userId
            && $session->version > $action->sessionVersion;
    }

    private function submitApplication(TelegramInteractionAction $action): void
    {
        if ($action->replayed && $this->recoverAdvancedSubmission($action)) {
            return;
        }

        $summary = $this->customers->forSelf($action->userId, $action->userId);
        if (! $this->canSubmit($summary)) {
            $this->consumeUnavailableSubmission($action, $summary);

            return;
        }

        try {
            $session = $this->database->connection()->transaction(function () use ($action) {
                $claim = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_SUBMITTING,
                    [],
                    'tg-agent-submit-claim:'.hash('sha256', $action->requestKey),
                );
                $this->assertActor($action, $claim->userId);

                $this->applications->submit(
                    $action->userId,
                    new AgentChangeContext(
                        requestFingerprint: hash('sha256', 'telegram-agent-submit:'.$action->requestKey),
                        correlationId: 'tg-agent:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':submit'), 0, 48),
                        reasonCode: 'telegram_cooperation_request',
                        actorUserId: $action->userId,
                    ),
                );

                $completed = $this->sessions->transition(
                    $action->sessionPublicId,
                    $claim->version,
                    self::STATE_AGENT,
                    [],
                    'tg-agent-submit-complete:'.hash('sha256', $action->requestKey),
                );
                $this->assertActor($action, $completed->userId);

                return $completed;
            }, 3);
        } catch (AgentApplicationSubmissionRejected) {
            $current = $this->customers->forSelf($action->userId, $action->userId);
            $this->consumeUnavailableSubmission($action, $current);

            return;
        } catch (DomainException) {
            // The callback lost the session-version race before the Agent mutation committed.
            return;
        }

        $current = $this->customers->forSelf($action->userId, $action->userId);
        $this->renderStatus($action, $session->version, $current, false);
    }

    private function recoverAdvancedSubmission(TelegramInteractionAction $action): bool
    {
        $session = $this->sessions->activeForAccount($action->telegramAccountId);
        if ($session === null
            || $session->publicId !== $action->sessionPublicId
            || $session->userId !== $action->userId
            || $session->version <= $action->sessionVersion) {
            return false;
        }

        // A newer version is the durable fence proving that the accepted
        // callback's original version was already consumed. Never replay the
        // Agent mutation against that old snapshot. If the same journey is
        // still active, only recover its idempotent presentation.
        if (! in_array($session->state, [self::STATE_AGENT, self::STATE_UNAVAILABLE], true)) {
            return true;
        }

        $summary = $this->customers->forSelf($action->userId, $action->userId);
        $this->renderStatus(
            $action,
            $session->version,
            $summary,
            $session->state === self::STATE_UNAVAILABLE,
        );

        return true;
    }

    private function consumeUnavailableSubmission(
        TelegramInteractionAction $action,
        CustomerAccountSummary $summary,
    ): void {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_UNAVAILABLE,
                [],
                'tg-agent-submit-unavailable:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderStatus($action, $session->version, $summary, true);
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                TelegramNavigationEntryGateway::STATE,
                [],
                'tg-agent-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':agent-home',
            $action->botId,
            $action->updateId,
            $action->telegramAccountId,
            $action->userId,
            $action->telegramUserId,
            $session->publicId,
            $session->flow,
            $session->state,
            $session->version,
            $session->payload,
            null,
            null,
            null,
            [],
            $action->replayed,
            null,
            $action->messageAcceptedAt,
        ));
    }

    private function renderStatus(
        TelegramInteractionAction $action,
        int $sessionVersion,
        CustomerAccountSummary $summary,
        bool $submissionUnavailable,
    ): void {
        $locale = $summary->locale === 'en' ? 'en' : 'fa';
        $rows = [];

        if ($this->canSubmit($summary)) {
            $submit = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_SUBMIT,
                [],
                'tg-agent-submit-button:'.hash('sha256', $action->requestKey),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram_agent.application.submit_button', $locale),
                $submit->publicId,
                TelegramInlineButtonStyle::Primary,
            )];
        }

        if ($this->canPurchase($summary)) {
            $purchase = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PURCHASE,
                [],
                'tg-agent-purchase-button:'.hash('sha256', $action->requestKey),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram_agent.purchase.single_button', $locale),
                $purchase->publicId,
                TelegramInlineButtonStyle::Primary,
            )];
        }

        $this->appendBack($action, $sessionVersion, $rows, $locale);

        if ($summary->accountType === 'agent' && $summary->agentStatus !== null) {
            $text = $this->translation('telegram_agent.agent.status', $locale, [
                'status' => $this->agentStatusLabel($summary->agentStatus, $locale),
                'joined_at' => $this->dateLabel($summary->joinedAt, $locale),
                'approved_at' => $this->dateLabel($summary->agentApprovedAt, $locale),
            ]);
        } elseif ($summary->agentApplicationState !== null) {
            $text = $this->translation('telegram_agent.application.status', $locale, [
                'status' => $this->applicationStateLabel($summary->agentApplicationState, $locale),
            ]);
        } else {
            $text = $this->translation('telegram_agent.application.not_submitted', $locale);
        }

        if ($submissionUnavailable) {
            $text .= "\n\n".$this->translation('telegram_agent.application.unavailable_notice', $locale);
        }

        $this->queueConfidential(
            $action,
            $text,
            $submissionUnavailable ? 'status-unavailable' : 'status',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    /** @param list<list<TelegramInlineCallbackButton>> $rows */
    private function appendBack(TelegramInteractionAction $action, int $sessionVersion, array &$rows, string $locale): void
    {
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-agent-back:'.hash('sha256', $action->requestKey),
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )];
    }

    private function queueConfidential(
        TelegramInteractionAction $action,
        string $text,
        string $surface,
        TelegramInlineKeyboardSnapshot $keyboard,
    ): void {
        $source = new readonly class($text) implements ConfidentialTelegramPresentationSource
        {
            public function __construct(private string $text) {}

            public function confidentialTelegramText(): string
            {
                return $this->text;
            }
        };
        $presentation = $this->confidentialPresentations->fromSource($source);
        $this->delivery->send(
            $action->telegramUserId,
            $presentation,
            'tg-agent-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-agent:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
            $keyboard,
        );
    }

    private function canOpen(CustomerAccountSummary $summary): bool
    {
        return ($summary->accountType === 'customer' && $summary->accountStatus === 'active')
            || ($summary->accountType === 'agent' && $summary->agentStatus !== null);
    }

    private function canSubmit(CustomerAccountSummary $summary): bool
    {
        return $summary->accountType === 'customer'
            && $summary->accountStatus === 'active'
            && $summary->agentApplicationState === null;
    }

    private function canPurchase(CustomerAccountSummary $summary): bool
    {
        return $summary->accountType === 'agent'
            && $summary->accountStatus === 'active'
            && $summary->agentStatus === 'active';
    }

    private function applicationStateLabel(string $state, string $locale): string
    {
        if (! in_array($state, ['submitted', 'under_review', 'approved', 'rejected', 'withdrawn'], true)) {
            return $this->translation('telegram_agent.states.unknown', $locale);
        }

        return $this->translation('telegram_agent.states.application.'.$state, $locale);
    }

    private function agentStatusLabel(string $status, string $locale): string
    {
        if (! in_array($status, ['active', 'limited', 'suspended'], true)) {
            return $this->translation('telegram_agent.states.unknown', $locale);
        }

        return $this->translation('telegram_agent.states.agent.'.$status, $locale);
    }

    private function dateLabel(?string $value, string $locale): string
    {
        if ($value === null || $value === '') {
            return $this->translation('telegram_agent.not_available', $locale);
        }

        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('Asia/Tehran'))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return $this->translation('telegram_agent.not_available', $locale);
        }
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->translator->get($key, $replace, $locale);
        if (! is_string($value) || $value === '' || $value === $key) {
            $value = $this->translator->get($key, $replace, 'en');
        }
        if (! is_string($value) || $value === '' || $value === $key) {
            throw new RuntimeException('Telegram Agent translation is unavailable.');
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram Agent translation has an unresolved placeholder.');
        }

        return $value;
    }

    private function assertActor(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new RuntimeException('Telegram Agent session actor binding is invalid.');
        }
    }

    private function isEntryCommand(?string $text): bool
    {
        if ($text === null) {
            return false;
        }
        $trimmed = trim($text);

        return preg_match('/\A\/menu(?:@[A-Za-z0-9_]+)?\z/u', $trimmed) === 1
            || preg_match('/\A\/start(?:@[A-Za-z0-9_]+)?(?:\s+[A-Za-z0-9_-]{1,64})?\z/u', $trimmed) === 1;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use Closure;
use DomainException;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramNavigationEntryGateway
{
    public const FLOW = 'navigation.home';

    public const STATE = 'home';

    public const MEMBERSHIP_GATE_FLOW = 'membership.bot_entry';

    public const MEMBERSHIP_GATE_STATE = 'awaiting_retry';

    public const MEMBERSHIP_RETRY_ACTION = 'membership.bot_entry.retry';

    /**
     * @param  Closure(): TelegramChannelMembershipEvaluator  $membership
     * @param  Closure(): NonRestrictedTelegramPresentationFactory  $presentations
     * @param  Closure(): TelegramDeliveryQueueService  $delivery
     * @param  Closure(): TelegramInteractionCallbackService  $callbacks
     * @param  Closure(): TelegramNavigationHandler  $navigation
     * @param  Closure(): Translator  $translator
     */
    public function __construct(
        private TelegramInteractionSessionService $sessions,
        private DatabaseManager $database,
        private TelegramInteractionUpdateBindingService $updateBindings,
        private Closure $membership,
        private Closure $presentations,
        private Closure $delivery,
        private Closure $callbacks,
        private Closure $navigation,
        private Closure $translator,
    ) {}

    /** @param array<string, mixed> $message */
    public function startIfEligible(
        string $botId,
        int $updateId,
        int $userId,
        int $telegramAccountId,
        int $telegramUserId,
        array $message,
        string $text,
    ): bool {
        if (! $this->matchesEntryCommand($text) || ! $this->isPrivateActorChat($message, $telegramUserId)) {
            return false;
        }

        if ($this->sessions->activeForAccount($telegramAccountId) !== null) {
            return false;
        }

        $locale = $this->locale($message);
        try {
            $evaluation = ($this->membership)()->evaluate(
                new TelegramChannelMembershipResolutionRequest($userId, 'bot_entry', null),
            );
        } catch (DomainException|RuntimeException) {
            $this->persistInitialSafeGate(
                $botId,
                $updateId,
                $telegramAccountId,
                $telegramUserId,
                $locale,
            );

            return false;
        }

        if ($evaluation->plan->required && $evaluation->telegramUserId !== $telegramUserId) {
            $this->persistInitialSafeGate(
                $botId,
                $updateId,
                $telegramAccountId,
                $telegramUserId,
                $locale,
            );

            return false;
        }

        if ($evaluation->decision === TelegramChannelMembershipEvaluationDecision::Unsatisfied) {
            $reference = TelegramProtectedPresentationReference::membershipJoinPrompt(
                'bot_entry',
                null,
                $evaluation->plan->configurationHash,
                $locale,
            );
            $retryPrompt = $this->presentation('telegram_membership.retry_prompt', $locale);
            $this->persistInitialGate(
                $botId,
                $updateId,
                $telegramAccountId,
                $locale,
                function (TelegramInlineKeyboardSnapshot $retryKeyboard) use (
                    $botId,
                    $updateId,
                    $telegramUserId,
                    $reference,
                    $retryPrompt,
                ): void {
                    ($this->delivery)()->queueProtectedReference(
                        TelegramDeliveryAction::Send,
                        $telegramUserId,
                        $reference,
                        "telegram-entry-membership-join:{$botId}:{$updateId}",
                        "tg-entry:{$botId}:{$updateId}:mjoin",
                    );
                    ($this->delivery)()->queue(
                        TelegramDeliveryAction::Send,
                        $telegramUserId,
                        null,
                        $retryPrompt,
                        "telegram-entry-membership-retry:{$botId}:{$updateId}",
                        "tg-entry:{$botId}:{$updateId}:mretry",
                        $retryKeyboard,
                    );
                },
            );

            return false;
        }

        if (in_array($evaluation->decision, [
            TelegramChannelMembershipEvaluationDecision::ManualReview,
            TelegramChannelMembershipEvaluationDecision::ConfigurationChanged,
        ], true)) {
            $this->persistInitialSafeGate(
                $botId,
                $updateId,
                $telegramAccountId,
                $telegramUserId,
                $locale,
            );

            return false;
        }

        $this->sessions->start(
            $telegramAccountId,
            self::FLOW,
            self::STATE,
            [],
            "telegram-entry:{$botId}:{$updateId}:navigation-home",
        );

        return true;
    }

    public function handleMembershipGateAction(TelegramInteractionAction $action): void
    {
        if ($action->flow !== self::MEMBERSHIP_GATE_FLOW
            || $action->sessionState !== self::MEMBERSHIP_GATE_STATE) {
            throw new RuntimeException('Telegram bot-entry membership gate state is invalid.');
        }

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction !== self::MEMBERSHIP_RETRY_ACTION || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram bot-entry membership callback is unsupported.');
            }
        } elseif ($action->kind === TelegramInteractionActionKind::Message) {
            if (! is_string($action->messageText) || ! $this->matchesEntryCommand($action->messageText)) {
                return;
            }
        } elseif ($action->kind === TelegramInteractionActionKind::Back) {
            return;
        } else {
            return;
        }

        $active = $this->sessions->activeForAccount($action->telegramAccountId);
        if ($active === null) {
            return;
        }
        if ($active->publicId !== $action->sessionPublicId) {
            if ($active->flow === self::FLOW) {
                $this->renderNavigationHome($action, $active);
            }

            return;
        }
        if ($active->flow !== self::MEMBERSHIP_GATE_FLOW
            || $active->state !== self::MEMBERSHIP_GATE_STATE
            || $active->version !== $action->sessionVersion) {
            // A prior attempt of this same update may already have advanced the
            // gate version and persisted its semantic effect atomically.
            return;
        }

        $locale = $this->gateLocale($action);
        try {
            $evaluation = ($this->membership)()->evaluate(
                new TelegramChannelMembershipResolutionRequest($action->userId, 'bot_entry', null),
            );
        } catch (DomainException|RuntimeException) {
            $this->refreshGateWithSafeFeedback($action, $locale);

            return;
        }

        if ($evaluation->plan->required && $evaluation->telegramUserId !== $action->telegramUserId) {
            $this->refreshGateWithSafeFeedback($action, $locale);

            return;
        }

        if ($evaluation->decision === TelegramChannelMembershipEvaluationDecision::Unsatisfied) {
            $this->refreshUnsatisfiedGate(
                $action,
                $locale,
                $evaluation->plan->configurationHash,
            );

            return;
        }

        if (in_array($evaluation->decision, [
            TelegramChannelMembershipEvaluationDecision::ManualReview,
            TelegramChannelMembershipEvaluationDecision::ConfigurationChanged,
        ], true)) {
            $this->refreshGateWithSafeFeedback($action, $locale);

            return;
        }

        $this->handoffGateToNavigation($action);
    }

    public function matchesEntryCommand(string $text): bool
    {
        $trimmed = trim($text);

        return preg_match('/\A\/menu(?:@[A-Za-z0-9_]+)?\z/u', $trimmed) === 1
            || preg_match('/\A\/start(?:@[A-Za-z0-9_]+)?(?:\s+[A-Za-z0-9_-]{1,64})?\z/u', $trimmed) === 1;
    }

    /** @param array<string, mixed> $message */
    private function isPrivateActorChat(array $message, int $telegramUserId): bool
    {
        $chat = $message['chat'] ?? null;
        $from = $message['from'] ?? null;
        if (! is_array($chat)
            || array_is_list($chat)
            || ($chat['type'] ?? null) !== 'private'
            || ! is_array($from)
            || array_is_list($from)) {
            return false;
        }

        $chatId = $chat['id'] ?? null;
        $fromId = $from['id'] ?? null;

        return is_int($chatId)
            && is_int($fromId)
            && $chatId > 0
            && $fromId > 0
            && $chatId === $telegramUserId
            && $fromId === $telegramUserId;
    }

    /** @param array<string, mixed> $message */
    private function locale(array $message): string
    {
        $from = $message['from'] ?? null;

        return is_array($from) && ($from['language_code'] ?? null) === 'en' ? 'en' : 'fa';
    }

    private function gateLocale(TelegramInteractionAction $action): string
    {
        $locale = $action->sessionPayload['locale'] ?? null;

        return $locale === 'en' ? 'en' : 'fa';
    }

    private function persistInitialSafeGate(
        string $botId,
        int $updateId,
        int $telegramAccountId,
        int $telegramUserId,
        string $locale,
    ): void {
        $feedback = $this->presentation('telegram_membership.entry_unavailable', $locale);
        $this->persistInitialGate(
            $botId,
            $updateId,
            $telegramAccountId,
            $locale,
            function (TelegramInlineKeyboardSnapshot $retryKeyboard) use (
                $botId,
                $updateId,
                $telegramUserId,
                $feedback,
            ): void {
                ($this->delivery)()->queue(
                    TelegramDeliveryAction::Send,
                    $telegramUserId,
                    null,
                    $feedback,
                    "telegram-entry-membership-feedback:{$botId}:{$updateId}",
                    "tg-entry:{$botId}:{$updateId}:mfail",
                    $retryKeyboard,
                );
            },
        );
    }

    /** @param Closure(TelegramInlineKeyboardSnapshot): void $queue */
    private function persistInitialGate(
        string $botId,
        int $updateId,
        int $telegramAccountId,
        string $locale,
        Closure $queue,
    ): void {
        $requestKey = "telegram-update:{$botId}:{$updateId}:message";
        $connection = $this->database->connection();

        $connection->transaction(function () use (
            $botId,
            $updateId,
            $telegramAccountId,
            $locale,
            $requestKey,
            $queue,
        ): void {
            $binding = $this->updateBindings->bind(
                $botId,
                $updateId,
                $telegramAccountId,
                'message',
                $requestKey,
            );

            if ($binding->replayed || $binding->sessionPublicId !== null) {
                return;
            }

            $gate = $this->sessions->start(
                $telegramAccountId,
                self::MEMBERSHIP_GATE_FLOW,
                self::MEMBERSHIP_GATE_STATE,
                ['locale' => $locale],
                "telegram-entry:{$botId}:{$updateId}:membership-gate",
            );
            $retry = ($this->callbacks)()->issue(
                $gate->publicId,
                $gate->version,
                self::MEMBERSHIP_RETRY_ACTION,
                [],
                "telegram-entry-membership-retry-callback:{$botId}:{$updateId}",
            );

            $queue($this->retryKeyboard($retry->publicId, $locale));
        }, 3);
    }

    private function refreshUnsatisfiedGate(
        TelegramInteractionAction $action,
        string $locale,
        string $configurationHash,
    ): void {
        $reference = TelegramProtectedPresentationReference::membershipJoinPrompt(
            'bot_entry',
            null,
            $configurationHash,
            $locale,
        );
        $retryPrompt = $this->presentation('telegram_membership.retry_prompt', $locale);
        $identity = $this->actionIdentity($action);
        $connection = $this->database->connection();

        $connection->transaction(function () use (
            $action,
            $locale,
            $reference,
            $retryPrompt,
            $identity,
        ): void {
            $gate = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::MEMBERSHIP_GATE_STATE,
                ['locale' => $locale],
                "telegram-membership-gate-refresh:{$identity}",
            );
            $retry = ($this->callbacks)()->issue(
                $gate->publicId,
                $gate->version,
                self::MEMBERSHIP_RETRY_ACTION,
                [],
                "telegram-membership-gate-retry-callback:{$identity}",
            );
            $retryKeyboard = $this->retryKeyboard($retry->publicId, $locale);

            ($this->delivery)()->queueProtectedReference(
                TelegramDeliveryAction::Send,
                $action->telegramUserId,
                $reference,
                "telegram-membership-gate-join:{$identity}",
                'tg-mgate-j:'.substr($identity, 0, 32),
            );
            ($this->delivery)()->queue(
                TelegramDeliveryAction::Send,
                $action->telegramUserId,
                null,
                $retryPrompt,
                "telegram-membership-gate-retry:{$identity}",
                'tg-mgate-r:'.substr($identity, 0, 32),
                $retryKeyboard,
            );
        }, 3);
    }

    private function refreshGateWithSafeFeedback(TelegramInteractionAction $action, string $locale): void
    {
        $feedback = $this->presentation('telegram_membership.entry_unavailable', $locale);
        $identity = $this->actionIdentity($action);
        $connection = $this->database->connection();

        $connection->transaction(function () use ($action, $locale, $feedback, $identity): void {
            $gate = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::MEMBERSHIP_GATE_STATE,
                ['locale' => $locale],
                "telegram-membership-gate-feedback-refresh:{$identity}",
            );
            $retry = ($this->callbacks)()->issue(
                $gate->publicId,
                $gate->version,
                self::MEMBERSHIP_RETRY_ACTION,
                [],
                "telegram-membership-gate-feedback-callback:{$identity}",
            );

            ($this->delivery)()->queue(
                TelegramDeliveryAction::Send,
                $action->telegramUserId,
                null,
                $feedback,
                "telegram-membership-gate-feedback:{$identity}",
                'tg-mgate-f:'.substr($identity, 0, 32),
                $this->retryKeyboard($retry->publicId, $locale),
            );
        }, 3);
    }

    private function handoffGateToNavigation(TelegramInteractionAction $action): void
    {
        $identity = $this->actionIdentity($action);
        $connection = $this->database->connection();
        $navigation = $connection->transaction(function () use ($action, $identity): ?TelegramInteractionSessionReceipt {
            $active = $this->sessions->activeForAccount($action->telegramAccountId);
            if ($active === null) {
                return null;
            }
            if ($active->publicId !== $action->sessionPublicId) {
                return $active->flow === self::FLOW ? $active : null;
            }
            if ($active->flow !== self::MEMBERSHIP_GATE_FLOW
                || $active->state !== self::MEMBERSHIP_GATE_STATE
                || $active->version !== $action->sessionVersion) {
                return null;
            }

            $this->sessions->complete(
                $action->sessionPublicId,
                $action->sessionVersion,
                "telegram-membership-gate-complete:{$identity}",
            );

            return $this->sessions->start(
                $action->telegramAccountId,
                self::FLOW,
                self::STATE,
                [],
                "telegram-membership-gate-navigation:{$identity}",
            );
        }, 3);

        if ($navigation !== null && $navigation->flow === self::FLOW) {
            $this->renderNavigationHome($action, $navigation);
        }
    }

    private function renderNavigationHome(
        TelegramInteractionAction $source,
        TelegramInteractionSessionReceipt $navigation,
    ): void {
        if ($navigation->flow !== self::FLOW || $navigation->state !== self::STATE) {
            return;
        }
        if ($navigation->telegramAccountId !== $source->telegramAccountId
            || $navigation->userId !== $source->userId) {
            throw new RuntimeException('Telegram membership handoff navigation actor binding changed.');
        }

        ($this->navigation)()->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            'telegram-membership-gate-home:'.$navigation->publicId,
            $source->botId,
            $source->updateId,
            $source->telegramAccountId,
            $source->userId,
            $source->telegramUserId,
            $navigation->publicId,
            $navigation->flow,
            $navigation->state,
            $navigation->version,
            $navigation->payload,
            null,
            null,
            null,
            [],
            $source->replayed || $navigation->replayed,
            $source->callbackAcceptedAt,
            $source->messageAcceptedAt,
        ));
    }

    private function retryKeyboard(string $callbackPublicId, string $locale): TelegramInlineKeyboardSnapshot
    {
        return new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineCallbackButton(
                $this->translation('telegram_membership.retry_button', $locale),
                $callbackPublicId,
                TelegramInlineButtonStyle::Primary,
            ),
        ]]);
    }

    private function presentation(string $key, string $locale): NonRestrictedTelegramPresentation
    {
        $text = $this->translation($key, $locale);
        $source = new readonly class($text) implements NonRestrictedTelegramPresentationSource
        {
            public function __construct(private string $text) {}

            public function nonRestrictedTelegramText(): string
            {
                return $this->text;
            }
        };

        return ($this->presentations)()->fromSource($source);
    }

    private function actionIdentity(TelegramInteractionAction $action): string
    {
        return hash('sha256', $action->requestKey);
    }

    private function translation(string $key, string $locale): string
    {
        $translator = ($this->translator)();
        $text = $translator->get($key, [], $locale);
        if (! is_string($text) || $text === '' || $text === $key) {
            $text = $translator->get($key, [], 'en');
        }
        if (! is_string($text) || $text === '' || $text === $key) {
            throw new DomainException('Telegram bot-entry membership feedback is unavailable.');
        }

        return $text;
    }
}

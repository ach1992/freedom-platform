<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use Closure;
use DomainException;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramNavigationEntryGateway
{
    public const FLOW = 'navigation.home';

    public const STATE = 'home';

    /**
     * @param  Closure(): TelegramChannelMembershipEvaluator  $membership
     * @param  Closure(): NonRestrictedTelegramPresentationFactory  $presentations
     * @param  Closure(): TelegramDeliveryQueueService  $delivery
     * @param  Closure(): Translator  $translator
     */
    public function __construct(
        private TelegramInteractionSessionService $sessions,
        private DatabaseManager $database,
        private TelegramInteractionUpdateBindingService $updateBindings,
        private Closure $membership,
        private Closure $presentations,
        private Closure $delivery,
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
            $this->queueSafeBlockedFeedback(
                $botId,
                $updateId,
                $telegramAccountId,
                $telegramUserId,
                $locale,
            );

            return false;
        }

        if ($evaluation->plan->required && $evaluation->telegramUserId !== $telegramUserId) {
            $this->queueSafeBlockedFeedback(
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
            $this->persistBlockedEntry(
                $botId,
                $updateId,
                $telegramAccountId,
                function () use ($botId, $updateId, $telegramUserId, $reference): void {
                    ($this->delivery)()->queueProtectedReference(
                        TelegramDeliveryAction::Send,
                        $telegramUserId,
                        $reference,
                        "telegram-entry-membership-join:{$botId}:{$updateId}",
                        "tg-entry:{$botId}:{$updateId}:mjoin",
                    );
                },
            );

            return false;
        }

        if (in_array($evaluation->decision, [
            TelegramChannelMembershipEvaluationDecision::ManualReview,
            TelegramChannelMembershipEvaluationDecision::ConfigurationChanged,
        ], true)) {
            $this->queueSafeBlockedFeedback(
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

    private function queueSafeBlockedFeedback(
        string $botId,
        int $updateId,
        int $telegramAccountId,
        int $telegramUserId,
        string $locale,
    ): void {
        $text = $this->translation('telegram_membership.entry_unavailable', $locale);
        $source = new readonly class($text) implements NonRestrictedTelegramPresentationSource
        {
            public function __construct(private string $text) {}

            public function nonRestrictedTelegramText(): string
            {
                return $this->text;
            }
        };
        $presentation = ($this->presentations)()->fromSource($source);

        $this->persistBlockedEntry(
            $botId,
            $updateId,
            $telegramAccountId,
            function () use ($botId, $updateId, $telegramUserId, $presentation): void {
                ($this->delivery)()->queue(
                    TelegramDeliveryAction::Send,
                    $telegramUserId,
                    null,
                    $presentation,
                    "telegram-entry-membership-feedback:{$botId}:{$updateId}",
                    "tg-entry:{$botId}:{$updateId}:mfail",
                );
            },
        );
    }

    /** @param Closure(): void $queue */
    private function persistBlockedEntry(
        string $botId,
        int $updateId,
        int $telegramAccountId,
        Closure $queue,
    ): void {
        $requestKey = "telegram-update:{$botId}:{$updateId}:message";
        $connection = $this->database->connection();

        $connection->transaction(function () use (
            $botId,
            $updateId,
            $telegramAccountId,
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

            $queue();
        }, 3);
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

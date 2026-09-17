<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Support\Application\SupportTicketRatingReceipt;
use App\Modules\Support\Application\SupportTicketRatingService;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Bounded customer rating extension for the existing Support ticket journey.
 * Support owns rating/state/ownership; Telegram owns only restart-safe command
 * interaction and confidential confirmation presentation.
 */
final readonly class TelegramSupportRatingNavigationHandler
{
    private const STATE_TICKET = 'support_ticket';

    private const STATE_MUTATING = 'support_mutating';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private SupportTicketRatingService $ratings,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return $action->sessionState === self::STATE_TICKET
            && $action->kind === TelegramInteractionActionKind::Message
            && $action->messageText !== null
            && str_starts_with(trim($action->messageText), '/support-rate');
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null) {
            throw new RuntimeException('Telegram Support rating expects a message command.');
        }

        $ticketId = $this->positivePayloadId($action->sessionPayload, 'ticket_id');
        $score = $this->score(trim($action->messageText));

        if ($action->replayed && $this->recoverAdvancedRating($action, $ticketId)) {
            return;
        }

        $committed = $this->commitRating($action, $ticketId, $score);
        if ($committed === null) {
            return;
        }
        [$session, $receipt] = $committed;
        $this->queueConfirmation($action, $session->version, $receipt);
    }

    /** @return array{0:TelegramInteractionSessionReceipt,1:SupportTicketRatingReceipt}|null */
    private function commitRating(
        TelegramInteractionAction $action,
        int $ticketId,
        int $score,
    ): ?array {
        try {
            return $this->database->connection()->transaction(function () use ($action, $ticketId, $score): array {
                $claim = $this->claimSession($action);
                $receipt = $this->ratings->rate($ticketId, $action->userId, $score);
                $completed = $this->completeSession($action, $claim, $ticketId);

                return [$completed, $receipt];
            }, 3);
        } catch (TelegramSupportRatingSessionRace) {
            return null;
        }
    }

    private function claimSession(TelegramInteractionAction $action): TelegramInteractionSessionReceipt
    {
        try {
            $claim = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_MUTATING,
                ['operation' => 'support-rate'],
                'tg-support-rating-claim:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException $exception) {
            throw new TelegramSupportRatingSessionRace('Telegram Support rating lost the session claim race.', 0, $exception);
        }
        $this->assertSessionActor($action, $claim);

        return $claim;
    }

    private function completeSession(
        TelegramInteractionAction $action,
        TelegramInteractionSessionReceipt $claim,
        int $ticketId,
    ): TelegramInteractionSessionReceipt {
        try {
            $completed = $this->sessions->transition(
                $action->sessionPublicId,
                $claim->version,
                self::STATE_TICKET,
                ['ticket_id' => $ticketId],
                'tg-support-rating-complete:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException $exception) {
            throw new TelegramSupportRatingSessionRace('Telegram Support rating lost the session completion race.', 0, $exception);
        }
        $this->assertSessionActor($action, $completed);

        return $completed;
    }

    private function recoverAdvancedRating(TelegramInteractionAction $action, int $ticketId): bool
    {
        $session = $this->sessions->activeForAccount($action->telegramAccountId);
        if ($session === null
            || $session->publicId !== $action->sessionPublicId
            || $session->userId !== $action->userId
            || $session->version <= $action->sessionVersion) {
            return false;
        }

        if ($session->version === $action->sessionVersion + 2
            && $session->state === self::STATE_TICKET
            && $this->positivePayloadId($session->payload, 'ticket_id') === $ticketId) {
            $rating = $this->ratings->ratingForCustomer($ticketId, $action->userId);
            if ($rating === null) {
                throw new RuntimeException('Committed Telegram Support rating is unavailable during recovery.');
            }
            $this->queueConfirmation(
                $action,
                $session->version,
                new SupportTicketRatingReceipt($rating, true),
            );
        }

        return true;
    }

    private function queueConfirmation(
        TelegramInteractionAction $action,
        int $version,
        SupportTicketRatingReceipt $receipt,
    ): void {
        $locale = $this->locale($action->userId);
        $rows = [];
        $this->appendBack($action, $version, $rows, $locale);
        $this->queue(
            $action,
            $this->translation('telegram_support.rating_recorded', $locale, [
                'score' => $receipt->rating->score,
            ]),
            'rating-recorded',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function score(string $text): int
    {
        if (preg_match('/\A\/support-rate(?:@[A-Za-z0-9_]+)?\s+([1-5])\z/u', $text, $matches) !== 1) {
            throw new DomainException('Telegram Support rating command is invalid.');
        }

        return (int) $matches[1];
    }

    /** @param array<string,mixed> $payload */
    private function positivePayloadId(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if ((! is_int($value) && ! is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value < 1) {
            throw new RuntimeException('Telegram Support rating ticket identity is invalid.');
        }

        return (int) $value;
    }

    private function assertSessionActor(
        TelegramInteractionAction $action,
        TelegramInteractionSessionReceipt $session,
    ): void {
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram Support rating session actor binding is invalid.');
        }
    }

    /** @param list<list<TelegramInlineCallbackButton>> $rows */
    private function appendBack(TelegramInteractionAction $action, int $version, array &$rows, string $locale): void
    {
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $version,
            self::ACTION_BACK,
            [],
            'tg-support-rating-back:'.hash('sha256', $action->requestKey.':'.$version),
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram_support.buttons.back', $locale),
            $back->publicId,
        )];
    }

    private function queue(
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
        $presentation = $this->presentations->fromSource($source);
        $this->delivery->send(
            $action->telegramUserId,
            $presentation,
            'tg-support-rating-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-support:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
            $keyboard,
        );
    }

    private function locale(int $userId): string
    {
        $locale = $this->database->connection()->table('users')->where('id', $userId)->value('locale');

        return $locale === 'en' ? 'en' : 'fa';
    }

    /** @param array<string,bool|float|int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === '' || $value === '['.$key.']') {
            throw new RuntimeException('Telegram Support rating translation is unavailable.');
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram Support rating translation has an unresolved placeholder.');
        }

        return $value;
    }
}

final class TelegramSupportRatingSessionRace extends RuntimeException {}

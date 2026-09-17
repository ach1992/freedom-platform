<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Support\Application\SupportTicketRoutingService;
use App\Modules\Support\Application\SupportTicketSearchField;
use App\Modules\Support\Application\SupportTicketSnapshot;
use App\Modules\Support\Application\SupportTicketSupportService;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Bounded Telegram presentation for Support operator ticket discovery and
 * assignment. Business authorization/state remains owned by Support.
 */
final readonly class TelegramSupportRoutingNavigationHandler
{
    private const STATE_QUEUE = 'support_queue';

    private const STATE_QUEUE_TICKET = 'support_queue_ticket';

    private const STATE_MUTATING = 'support_mutating';

    private const ACTION_QUEUE_TICKET = 'navigation.support.queue.ticket';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private SupportTicketRoutingService $routing,
        private SupportTicketSupportService $support,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null) {
            return false;
        }

        $text = trim($action->messageText);

        return ($action->sessionState === self::STATE_QUEUE && str_starts_with($text, '/support-search'))
            || ($action->sessionState === self::STATE_QUEUE_TICKET && str_starts_with($text, '/support-assign'));
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null) {
            throw new RuntimeException('Telegram Support routing expects a message command.');
        }

        $text = trim($action->messageText);
        if ($action->sessionState === self::STATE_QUEUE) {
            $this->handleSearch($action, $text);

            return;
        }
        if ($action->sessionState === self::STATE_QUEUE_TICKET) {
            $this->handleAssignment($action, $text);

            return;
        }

        throw new RuntimeException('Telegram Support routing state is unsupported.');
    }

    private function handleSearch(TelegramInteractionAction $action, string $text): void
    {
        if (preg_match('/\A\/support-search(?:@[A-Za-z0-9_]+)?\s+(tracking|user|order|payment|service)\s+(\S+)\z/u', $text, $matches) !== 1) {
            throw new DomainException('Telegram Support search command is invalid.');
        }

        $field = SupportTicketSearchField::tryFrom($matches[1]);
        if ($field === null) {
            throw new DomainException('Telegram Support search field is invalid.');
        }

        $this->renderSearchResults(
            $action,
            $this->routing->search($action->userId, $field, $matches[2], 20),
        );
    }

    private function handleAssignment(TelegramInteractionAction $action, string $text): void
    {
        $assigneeUserId = $this->assignmentTarget($text);
        $ticketId = $this->positivePayloadId($action->sessionPayload, 'ticket_id');

        if ($action->replayed && $this->recoverAdvancedAssignment($action, $ticketId, $assigneeUserId)) {
            return;
        }

        $committed = $this->commitAssignment($action, $ticketId, $assigneeUserId);
        if ($committed === null) {
            return;
        }
        [$session, $ticket] = $committed;

        $this->queueAssignmentConfirmation($action, $session->version, $ticket, $assigneeUserId);
    }

    /** @param list<SupportTicketSnapshot> $tickets */
    private function renderSearchResults(TelegramInteractionAction $action, array $tickets): void
    {
        $locale = $this->locale($action->userId);
        $rows = [];
        $items = [];
        foreach ($tickets as $ticket) {
            $callback = $this->callbacks->issue(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::ACTION_QUEUE_TICKET,
                ['ticket_id' => $ticket->id],
                'tg-support-routing-search-ticket:'.hash('sha256', $action->requestKey.':'.$ticket->id),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->ticketButtonText($ticket->trackingNumber, $ticket->title),
                $callback->publicId,
            )];
            $items[] = $this->translation('telegram_support.queue_item', $locale, [
                'tracking' => $ticket->trackingNumber,
                'title' => $ticket->title,
                'state' => $this->translation('telegram_support.states.'.$ticket->state->value, $locale),
                'priority' => $this->translation('telegram_support.priorities.'.$ticket->priority->value, $locale),
                'assignment' => $this->assignmentText($ticket->assignedUserId, $locale),
            ]);
        }
        $this->appendBack($action, $action->sessionVersion, $rows, $locale);

        $text = $tickets === []
            ? $this->translation('telegram_support.search_empty', $locale)
            : $this->translation('telegram_support.search_results', $locale, ['items' => implode("\n\n", $items)]);

        $this->queue(
            $action,
            $text,
            'search-results',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    /** @return array{0:TelegramInteractionSessionReceipt,1:SupportTicketSnapshot}|null */
    private function commitAssignment(
        TelegramInteractionAction $action,
        int $ticketId,
        int $assigneeUserId,
    ): ?array {
        try {
            return $this->database->connection()->transaction(function () use ($action, $ticketId, $assigneeUserId): array {
                $claim = $this->claimSession($action);
                $ticket = $this->routing->assign($action->userId, $ticketId, $assigneeUserId);
                $completed = $this->completeSession($action, $claim, $ticketId);

                return [$completed, $ticket];
            }, 3);
        } catch (TelegramSupportRoutingSessionRace) {
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
                ['operation' => 'support-assign'],
                'tg-support-routing-assignment-claim:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException $exception) {
            throw new TelegramSupportRoutingSessionRace('Telegram Support assignment lost the session claim race.', 0, $exception);
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
                self::STATE_QUEUE_TICKET,
                ['ticket_id' => $ticketId],
                'tg-support-routing-assignment-complete:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException $exception) {
            throw new TelegramSupportRoutingSessionRace('Telegram Support assignment lost the session completion race.', 0, $exception);
        }
        $this->assertSessionActor($action, $completed);

        return $completed;
    }

    private function recoverAdvancedAssignment(
        TelegramInteractionAction $action,
        int $ticketId,
        int $assigneeUserId,
    ): bool {
        $session = $this->sessions->activeForAccount($action->telegramAccountId);
        if ($session === null
            || $session->publicId !== $action->sessionPublicId
            || $session->userId !== $action->userId
            || $session->version <= $action->sessionVersion) {
            return false;
        }

        if ($session->version === $action->sessionVersion + 2
            && $session->state === self::STATE_QUEUE_TICKET
            && $this->positivePayloadId($session->payload, 'ticket_id') === $ticketId) {
            $this->queueAssignmentConfirmation(
                $action,
                $session->version,
                $this->support->detail($action->userId, $ticketId)->ticket,
                $assigneeUserId,
            );
        }

        return true;
    }

    private function queueAssignmentConfirmation(
        TelegramInteractionAction $action,
        int $version,
        SupportTicketSnapshot $ticket,
        int $assigneeUserId,
    ): void {
        $locale = $this->locale($action->userId);
        $rows = [];
        $this->appendBack($action, $version, $rows, $locale);
        $this->queue(
            $action,
            $this->translation('telegram_support.assignment_updated', $locale, [
                'tracking' => $ticket->trackingNumber,
                'user_id' => $assigneeUserId,
            ]),
            'assignment-updated',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function assignmentTarget(string $text): int
    {
        if (preg_match('/\A\/support-assign(?:@[A-Za-z0-9_]+)?\s+([1-9][0-9]{0,18})\z/u', $text, $matches) !== 1) {
            throw new DomainException('Telegram Support assignment command is invalid.');
        }

        $assigneeUserId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($assigneeUserId)) {
            throw new DomainException('Telegram Support assignee identity is invalid.');
        }

        return $assigneeUserId;
    }

    private function assignmentText(?int $assignedUserId, string $locale): string
    {
        return $assignedUserId === null
            ? $this->translation('telegram_support.assigned_none', $locale)
            : $this->translation('telegram_support.assigned_user', $locale, ['user_id' => $assignedUserId]);
    }

    /** @param array<string,mixed> $payload */
    private function positivePayloadId(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if ((! is_int($value) && ! is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value < 1) {
            throw new RuntimeException('Telegram Support routing payload identity is invalid.');
        }

        return (int) $value;
    }

    private function assertSessionActor(
        TelegramInteractionAction $action,
        TelegramInteractionSessionReceipt $session,
    ): void {
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram Support routing session actor binding is invalid.');
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
            'tg-support-routing-back:'.hash('sha256', $action->requestKey.':'.$version),
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram_support.buttons.back', $locale),
            $back->publicId,
        )];
    }

    private function ticketButtonText(string $trackingNumber, string $title): string
    {
        $text = trim($trackingNumber.' · '.$title);
        $limit = TelegramInlineCallbackButton::MAXIMUM_TEXT_CHARACTERS;
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit - 1).'…';
    }

    private function queue(
        TelegramInteractionAction $action,
        string $text,
        string $surface,
        TelegramInlineKeyboardSnapshot $keyboard,
    ): void {
        if (mb_strlen($text) > ConfidentialTelegramPresentation::MAXIMUM_TEXT_CHARACTERS) {
            $text = mb_substr($text, 0, ConfidentialTelegramPresentation::MAXIMUM_TEXT_CHARACTERS - 1).'…';
        }

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
            'tg-support-routing-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-support-routing:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
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
            throw new RuntimeException('Telegram Support routing translation is unavailable.');
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram Support routing translation has an unresolved placeholder.');
        }

        return $value;
    }
}

final class TelegramSupportRoutingSessionRace extends RuntimeException {}

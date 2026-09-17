<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketDetailSnapshot;
use App\Modules\Support\Application\SupportTicketRoutingService;
use App\Modules\Support\Application\SupportTicketSearchField;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Application\SupportTicketSupportService;
use App\Modules\Support\Domain\SupportTicketMessageKind;
use App\Modules\Support\Domain\SupportTicketPriority;
use App\Modules\Support\Domain\SupportTicketState;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use Closure;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Text-first Telegram presentation for the authoritative Support lifecycle.
 * Support owns ticket state/persistence; Telegram owns only interaction state
 * and confidential presentation.
 */
final readonly class TelegramSupportNavigationHandler
{
    private const STATE_HOME = 'support_home';

    private const STATE_CREATE_CATEGORY = 'support_create_category';

    private const STATE_CREATE_TITLE = 'support_create_title';

    private const STATE_CREATE_DESCRIPTION = 'support_create_description';

    private const STATE_TICKET = 'support_ticket';

    private const STATE_REPLY = 'support_reply';

    private const STATE_CLOSE = 'support_close';

    private const STATE_QUEUE = 'support_queue';

    private const STATE_QUEUE_TICKET = 'support_queue_ticket';

    private const STATE_QUEUE_REPLY = 'support_queue_reply';

    private const STATE_QUEUE_NOTE = 'support_queue_note';

    private const STATE_QUEUE_PRIORITY = 'support_queue_priority';

    private const STATE_QUEUE_STATE = 'support_queue_state';

    private const STATE_QUEUE_CLOSE = 'support_queue_close';

    private const STATE_MUTATING = 'support_mutating';

    private const ACTION_SUPPORT = 'navigation.support';

    private const ACTION_CREATE = 'navigation.support.create';

    private const ACTION_TICKET = 'navigation.support.ticket';

    private const ACTION_CATEGORY = 'navigation.support.category';

    private const ACTION_REPLY = 'navigation.support.reply';

    private const ACTION_CLOSE = 'navigation.support.close';

    private const ACTION_REOPEN = 'navigation.support.reopen';

    private const ACTION_QUEUE = 'navigation.support.queue';

    private const ACTION_QUEUE_TICKET = 'navigation.support.queue.ticket';

    private const ACTION_CLAIM = 'navigation.support.claim';

    private const ACTION_QUEUE_REPLY = 'navigation.support.queue.reply';

    private const ACTION_QUEUE_NOTE = 'navigation.support.queue.note';

    private const ACTION_QUEUE_PRIORITY = 'navigation.support.queue.priority';

    private const ACTION_QUEUE_PRIORITY_SET = 'navigation.support.queue.priority.set';

    private const ACTION_QUEUE_STATE = 'navigation.support.queue.state';

    private const ACTION_QUEUE_STATE_SET = 'navigation.support.queue.state.set';

    private const ACTION_BACK = 'navigation.back';

    /** @var list<string> */
    private const STATES = [
        self::STATE_HOME,
        self::STATE_CREATE_CATEGORY,
        self::STATE_CREATE_TITLE,
        self::STATE_CREATE_DESCRIPTION,
        self::STATE_TICKET,
        self::STATE_REPLY,
        self::STATE_CLOSE,
        self::STATE_QUEUE,
        self::STATE_QUEUE_TICKET,
        self::STATE_QUEUE_REPLY,
        self::STATE_QUEUE_NOTE,
        self::STATE_QUEUE_PRIORITY,
        self::STATE_QUEUE_STATE,
        self::STATE_QUEUE_CLOSE,
        self::STATE_MUTATING,
    ];

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramChannelMembershipEvaluator $membership,
        private SupportTicketService $tickets,
        private SupportTicketSupportService $support,
        private SupportTicketRoutingService $routing,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === TelegramNavigationEntryGateway::STATE
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_SUPPORT)
            || in_array($action->sessionState, self::STATES, true);
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->replayed && $this->recoverAdvancedMutation($action)) {
            return;
        }
        if ($action->replayed && $this->sessionAdvancedPast($action)) {
            return;
        }

        if ($action->sessionState === TelegramNavigationEntryGateway::STATE) {
            if ($action->kind !== TelegramInteractionActionKind::Callback
                || $action->callbackAction !== self::ACTION_SUPPORT
                || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Support home action is unsupported.');
            }
            $this->assertMembership($action->userId, 'support_view');
            $this->showHome($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->handleBack($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnMain($action);

            return;
        }
        if ($this->handleOperatorCommand($action)) {
            return;
        }

        match ($action->sessionState) {
            self::STATE_HOME => $this->handleHome($action),
            self::STATE_CREATE_CATEGORY => $this->handleCreateCategory($action),
            self::STATE_CREATE_TITLE => $this->handleCreateTitle($action),
            self::STATE_CREATE_DESCRIPTION => $this->handleCreateDescription($action),
            self::STATE_TICKET => $this->handleTicket($action),
            self::STATE_REPLY => $this->handleReply($action),
            self::STATE_CLOSE => $this->handleClose($action),
            self::STATE_QUEUE => $this->handleQueue($action),
            self::STATE_QUEUE_TICKET => $this->handleQueueTicket($action),
            self::STATE_QUEUE_REPLY => $this->handleQueueReply($action),
            self::STATE_QUEUE_NOTE => $this->handleQueueNote($action),
            self::STATE_QUEUE_PRIORITY => $this->handleQueuePriority($action),
            self::STATE_QUEUE_STATE => $this->handleQueueState($action),
            self::STATE_QUEUE_CLOSE => $this->handleQueueClose($action),
            self::STATE_MUTATING => throw new RuntimeException('Telegram Support mutation state cannot be externally resumed.'),
            default => throw new RuntimeException('Telegram Support navigation state is unsupported.'),
        };
    }

    private function handleOperatorCommand(TelegramInteractionAction $action): bool
    {
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null) {
            return false;
        }

        $text = trim($action->messageText);
        if ($action->sessionState === self::STATE_QUEUE && str_starts_with($text, '/support-search')) {
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

            return true;
        }

        if ($action->sessionState === self::STATE_QUEUE_TICKET && str_starts_with($text, '/support-assign')) {
            if (! $this->isAssignCommand($text)) {
                throw new DomainException('Telegram Support assignment command is invalid.');
            }
            preg_match('/\A\/support-assign(?:@[A-Za-z0-9_]+)?\s+([1-9][0-9]{0,18})\z/u', $text, $matches);
            $assigneeUserId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($assigneeUserId)) {
                throw new DomainException('Telegram Support assignee identity is invalid.');
            }
            $ticketId = $this->positivePayloadId($action->sessionPayload, 'ticket_id');
            $session = $this->commitTicketMutation(
                $action,
                $ticketId,
                self::STATE_QUEUE_TICKET,
                'support-assign',
                fn (): mixed => $this->routing->assign($action->userId, $ticketId, $assigneeUserId),
            );
            if ($session !== null) {
                $this->renderQueueTicket(
                    $action,
                    $session->version,
                    $this->support->detail($action->userId, $ticketId),
                    'assigned',
                );
            }

            return true;
        }

        return false;
    }

    private function handleHome(TelegramInteractionAction $action): void
    {
        $this->requireCallback($action);
        if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
            $this->returnMain($action);

            return;
        }
        if ($action->callbackAction === self::ACTION_CREATE && $action->callbackPayload === []) {
            $this->assertMembership($action->userId, 'ticket_creation');
            $this->showCategories($action);

            return;
        }
        if ($action->callbackAction === self::ACTION_TICKET) {
            $this->showTicket($action, $this->positivePayloadId($action->callbackPayload, 'ticket_id'));

            return;
        }
        if ($action->callbackAction === self::ACTION_QUEUE && $action->callbackPayload === []) {
            $this->showQueue($action);

            return;
        }

        throw new RuntimeException('Telegram Support home callback is unsupported.');
    }

    private function handleCreateCategory(TelegramInteractionAction $action): void
    {
        $this->requireCallback($action);
        if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
            $this->showHome($action);

            return;
        }
        if ($action->callbackAction !== self::ACTION_CATEGORY) {
            throw new RuntimeException('Telegram Support category callback is unsupported.');
        }
        $category = $this->stringPayload($action->callbackPayload, 'category');
        $available = false;
        foreach ($this->tickets->activeCategories() as $item) {
            if (hash_equals($item->code, $category)) {
                $available = true;
                break;
            }
        }
        if (! $available) {
            throw new AuthorizationException('Telegram Support category is stale or unavailable.');
        }
        $session = $this->transition($action, self::STATE_CREATE_TITLE, ['category' => $category], 'create-title');
        if ($session === null) {
            return;
        }
        $this->queue($action, $this->translation('telegram_support.title_prompt', $this->locale($action->userId)), 'title-prompt', $this->backKeyboard($action, $session->version));
    }

    private function handleCreateTitle(TelegramInteractionAction $action): void
    {
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null) {
            throw new RuntimeException('Telegram Support title expects a message.');
        }
        $title = trim($action->messageText);
        if ($title === '' || mb_strlen($title) > 200) {
            throw new DomainException('Telegram Support title is invalid.');
        }
        $category = $this->stringPayload($action->sessionPayload, 'category');
        $session = $this->transition($action, self::STATE_CREATE_DESCRIPTION, ['category' => $category, 'title' => $title], 'create-description');
        if ($session === null) {
            return;
        }
        $this->queue($action, $this->translation('telegram_support.description_prompt', $this->locale($action->userId)), 'description-prompt', $this->backKeyboard($action, $session->version));
    }

    private function handleCreateDescription(TelegramInteractionAction $action): void
    {
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null) {
            throw new RuntimeException('Telegram Support description expects a message.');
        }
        $body = trim($action->messageText);
        if ($body === '' || mb_strlen($body) > 8000) {
            throw new DomainException('Telegram Support description is invalid.');
        }
        $category = $this->stringPayload($action->sessionPayload, 'category');
        $title = $this->stringPayload($action->sessionPayload, 'title');
        $committed = $this->commitTicketCreation(
            $action,
            new SupportTicketCreateRequest(
                $action->userId,
                $category,
                $title,
                $body,
                $this->idempotencyKey('create', $action->requestKey),
            ),
        );
        if ($committed === null) {
            return;
        }
        [$session, $ticketId] = $committed;
        $this->renderCustomerTicket($action, $session->version, $this->tickets->ticketForCustomer($ticketId, $action->userId), 'created');
    }

    private function handleTicket(TelegramInteractionAction $action): void
    {
        $ticketId = $this->positivePayloadId($action->sessionPayload, 'ticket_id');
        $this->requireCallback($action);
        if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
            $this->showHome($action);

            return;
        }
        if ($action->callbackAction === self::ACTION_REPLY && $action->callbackPayload === []) {
            $this->tickets->ticketForCustomer($ticketId, $action->userId);
            $session = $this->transition($action, self::STATE_REPLY, ['ticket_id' => $ticketId], 'reply-prompt');
            if ($session !== null) {
                $this->queue($action, $this->translation('ticket.reply', $this->locale($action->userId)), 'reply-prompt', $this->backKeyboard($action, $session->version));
            }

            return;
        }
        if ($action->callbackAction === self::ACTION_CLOSE && $action->callbackPayload === []) {
            $this->tickets->ticketForCustomer($ticketId, $action->userId);
            $session = $this->transition($action, self::STATE_CLOSE, ['ticket_id' => $ticketId], 'close-prompt');
            if ($session !== null) {
                $this->queue($action, $this->translation('ticket.close', $this->locale($action->userId)), 'close-prompt', $this->backKeyboard($action, $session->version));
            }

            return;
        }
        if ($action->callbackAction === self::ACTION_REOPEN && $action->callbackPayload === []) {
            $session = $this->commitTicketMutation(
                $action,
                $ticketId,
                self::STATE_TICKET,
                'customer-reopen',
                fn (): mixed => $this->tickets->reopenForCustomer($ticketId, $action->userId),
            );
            if ($session !== null) {
                $this->renderCustomerTicket($action, $session->version, $this->tickets->ticketForCustomer($ticketId, $action->userId), 'reopened');
            }

            return;
        }

        throw new RuntimeException('Telegram Support ticket callback is unsupported.');
    }

    private function handleReply(TelegramInteractionAction $action): void
    {
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null) {
            throw new RuntimeException('Telegram Support reply expects a message.');
        }
        $ticketId = $this->positivePayloadId($action->sessionPayload, 'ticket_id');
        $session = $this->commitTicketMutation(
            $action,
            $ticketId,
            self::STATE_TICKET,
            'customer-reply',
            fn (): mixed => $this->tickets->replyAsCustomer($ticketId, $action->userId, $action->messageText, $this->idempotencyKey('customer-reply', $action->requestKey)),
        );
        if ($session !== null) {
            $this->renderCustomerTicket($action, $session->version, $this->tickets->ticketForCustomer($ticketId, $action->userId), 'replied');
        }
    }

    private function handleClose(TelegramInteractionAction $action): void
    {
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null) {
            throw new RuntimeException('Telegram Support close expects a message.');
        }
        $ticketId = $this->positivePayloadId($action->sessionPayload, 'ticket_id');
        $session = $this->commitTicketMutation(
            $action,
            $ticketId,
            self::STATE_TICKET,
            'customer-close',
            fn (): mixed => $this->tickets->closeForCustomer($ticketId, $action->userId, $action->messageText),
        );
        if ($session !== null) {
            $this->renderCustomerTicket($action, $session->version, $this->tickets->ticketForCustomer($ticketId, $action->userId), 'closed');
        }
    }

    private function handleQueue(TelegramInteractionAction $action): void
    {
        $this->requireCallback($action);
        if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
            $this->showHome($action);

            return;
        }
        if ($action->callbackAction === self::ACTION_QUEUE_TICKET) {
            $this->showQueueTicket($action, $this->positivePayloadId($action->callbackPayload, 'ticket_id'));

            return;
        }

        throw new RuntimeException('Telegram Support queue callback is unsupported.');
    }

    private function handleQueueTicket(TelegramInteractionAction $action): void
    {
        $ticketId = $this->positivePayloadId($action->sessionPayload, 'ticket_id');
        $this->requireCallback($action);
        if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
            $this->showQueue($action);

            return;
        }
        if ($action->callbackAction === self::ACTION_CLAIM && $action->callbackPayload === []) {
            $session = $this->commitTicketMutation(
                $action,
                $ticketId,
                self::STATE_QUEUE_TICKET,
                'support-claim',
                fn (): mixed => $this->support->claim($action->userId, $ticketId),
            );
            if ($session !== null) {
                $this->renderQueueTicket($action, $session->version, $this->support->detail($action->userId, $ticketId), 'claimed');
            }

            return;
        }
        if ($action->callbackAction === self::ACTION_QUEUE_REPLY && $action->callbackPayload === []) {
            $this->beginQueueText($action, $ticketId, self::STATE_QUEUE_REPLY, 'telegram_support.support_reply_prompt', 'queue-reply');

            return;
        }
        if ($action->callbackAction === self::ACTION_QUEUE_NOTE && $action->callbackPayload === []) {
            $this->beginQueueText($action, $ticketId, self::STATE_QUEUE_NOTE, 'telegram_support.internal_note_prompt', 'queue-note');

            return;
        }
        if ($action->callbackAction === self::ACTION_QUEUE_PRIORITY && $action->callbackPayload === []) {
            $this->showPriorityChoices($action, $ticketId);

            return;
        }
        if ($action->callbackAction === self::ACTION_QUEUE_STATE && $action->callbackPayload === []) {
            $this->showStateChoices($action, $ticketId);

            return;
        }

        throw new RuntimeException('Telegram Support queue ticket callback is unsupported.');
    }

    private function handleQueueReply(TelegramInteractionAction $action): void
    {
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null) {
            throw new RuntimeException('Telegram Support staff reply expects a message.');
        }
        $ticketId = $this->positivePayloadId($action->sessionPayload, 'ticket_id');
        $session = $this->commitTicketMutation(
            $action,
            $ticketId,
            self::STATE_QUEUE_TICKET,
            'support-reply',
            fn (): mixed => $this->support->reply($action->userId, $ticketId, $action->messageText, $this->idempotencyKey('support-reply', $action->requestKey)),
        );
        if ($session !== null) {
            $this->renderQueueTicket($action, $session->version, $this->support->detail($action->userId, $ticketId), 'support-replied');
        }
    }

    private function handleQueueNote(TelegramInteractionAction $action): void
    {
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null) {
            throw new RuntimeException('Telegram Support internal note expects a message.');
        }
        $ticketId = $this->positivePayloadId($action->sessionPayload, 'ticket_id');
        $session = $this->commitTicketMutation(
            $action,
            $ticketId,
            self::STATE_QUEUE_TICKET,
            'support-note',
            fn (): mixed => $this->support->internalNote($action->userId, $ticketId, $action->messageText, $this->idempotencyKey('internal-note', $action->requestKey)),
        );
        if ($session !== null) {
            $this->renderQueueTicket($action, $session->version, $this->support->detail($action->userId, $ticketId), 'internal-note');
        }
    }

    private function handleQueuePriority(TelegramInteractionAction $action): void
    {
        $this->requireCallback($action);
        if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
            $this->showQueueTicket($action, $this->positivePayloadId($action->sessionPayload, 'ticket_id'));

            return;
        }
        if ($action->callbackAction !== self::ACTION_QUEUE_PRIORITY_SET) {
            throw new RuntimeException('Telegram Support priority callback is unsupported.');
        }
        $ticketId = $this->positivePayloadId($action->sessionPayload, 'ticket_id');
        $priority = SupportTicketPriority::tryFrom($this->stringPayload($action->callbackPayload, 'priority'));
        if ($priority === null) {
            throw new AuthorizationException('Telegram Support priority is invalid.');
        }
        $session = $this->commitTicketMutation(
            $action,
            $ticketId,
            self::STATE_QUEUE_TICKET,
            'support-priority',
            fn (): mixed => $this->support->setPriority($action->userId, $ticketId, $priority),
        );
        if ($session !== null) {
            $this->renderQueueTicket($action, $session->version, $this->support->detail($action->userId, $ticketId), 'priority');
        }
    }

    private function handleQueueState(TelegramInteractionAction $action): void
    {
        $this->requireCallback($action);
        if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
            $this->showQueueTicket($action, $this->positivePayloadId($action->sessionPayload, 'ticket_id'));

            return;
        }
        if ($action->callbackAction !== self::ACTION_QUEUE_STATE_SET) {
            throw new RuntimeException('Telegram Support state callback is unsupported.');
        }
        $ticketId = $this->positivePayloadId($action->sessionPayload, 'ticket_id');
        $target = SupportTicketState::tryFrom($this->stringPayload($action->callbackPayload, 'state'));
        if ($target === null) {
            throw new AuthorizationException('Telegram Support target state is invalid.');
        }
        if ($target === SupportTicketState::Closed) {
            $session = $this->transition($action, self::STATE_QUEUE_CLOSE, ['ticket_id' => $ticketId], 'queue-close-prompt');
            if ($session !== null) {
                $this->queue($action, $this->translation('ticket.close', $this->locale($action->userId)), 'queue-close-prompt', $this->backKeyboard($action, $session->version));
            }

            return;
        }
        $session = $this->commitTicketMutation(
            $action,
            $ticketId,
            self::STATE_QUEUE_TICKET,
            'support-state',
            fn (): mixed => $this->support->transition($action->userId, $ticketId, $target, 'support_state_change'),
        );
        if ($session !== null) {
            $this->renderQueueTicket($action, $session->version, $this->support->detail($action->userId, $ticketId), 'state');
        }
    }

    private function handleQueueClose(TelegramInteractionAction $action): void
    {
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null) {
            throw new RuntimeException('Telegram Support staff close expects a message.');
        }
        $ticketId = $this->positivePayloadId($action->sessionPayload, 'ticket_id');
        $session = $this->commitTicketMutation(
            $action,
            $ticketId,
            self::STATE_QUEUE_TICKET,
            'support-close',
            fn (): mixed => $this->support->transition($action->userId, $ticketId, SupportTicketState::Closed, 'support_closed', $action->messageText),
        );
        if ($session !== null) {
            $this->renderQueueTicket($action, $session->version, $this->support->detail($action->userId, $ticketId), 'support-closed');
        }
    }

    private function showHome(TelegramInteractionAction $action): void
    {
        $session = $this->transition($action, self::STATE_HOME, [], 'home');
        if ($session === null) {
            return;
        }
        $this->renderHome($action, $session->version);
    }

    private function renderHome(TelegramInteractionAction $action, int $version): void
    {
        $locale = $this->locale($action->userId);
        $rows = [];
        foreach ($this->tickets->ticketsForCustomer($action->userId, 10) as $ticket) {
            $callback = $this->callbacks->issue($action->sessionPublicId, $version, self::ACTION_TICKET, ['ticket_id' => $ticket->id], 'tg-support-ticket:'.hash('sha256', $action->requestKey.':'.$ticket->id));
            $rows[] = [new TelegramInlineCallbackButton($this->ticketButtonText($ticket->trackingNumber, $ticket->title), $callback->publicId)];
        }
        $create = $this->callbacks->issue($action->sessionPublicId, $version, self::ACTION_CREATE, [], 'tg-support-create-button:'.hash('sha256', $action->requestKey));
        $rows[] = [new TelegramInlineCallbackButton($this->translation('telegram_support.buttons.create', $locale), $create->publicId, TelegramInlineButtonStyle::Primary)];
        if ($this->support->availableFor($action->userId)) {
            $queue = $this->callbacks->issue($action->sessionPublicId, $version, self::ACTION_QUEUE, [], 'tg-support-queue-button:'.hash('sha256', $action->requestKey));
            $rows[] = [new TelegramInlineCallbackButton($this->translation('telegram_support.buttons.queue', $locale), $queue->publicId, TelegramInlineButtonStyle::Primary)];
        }
        $this->appendBack($action, $version, $rows, $locale);
        $text = $this->translation('telegram_support.home', $locale);
        if ($this->tickets->ticketsForCustomer($action->userId, 1) === []) {
            $text .= "\n\n".$this->translation('telegram_support.empty', $locale);
        }
        $this->queue($action, $text, 'home', new TelegramInlineKeyboardSnapshot($rows));
    }

    private function showCategories(TelegramInteractionAction $action): void
    {
        $session = $this->transition($action, self::STATE_CREATE_CATEGORY, [], 'categories');
        if ($session === null) {
            return;
        }
        $locale = $this->locale($action->userId);
        $rows = [];
        foreach ($this->tickets->activeCategories() as $category) {
            $callback = $this->callbacks->issue($action->sessionPublicId, $session->version, self::ACTION_CATEGORY, ['category' => $category->code], 'tg-support-category:'.hash('sha256', $action->requestKey.':'.$category->code));
            $rows[] = [new TelegramInlineCallbackButton($this->boundedButtonText($locale === 'en' ? $category->nameEn : $category->nameFa), $callback->publicId)];
        }
        $this->appendBack($action, $session->version, $rows, $locale);
        $this->queue($action, $this->translation('ticket.category', $locale), 'categories', new TelegramInlineKeyboardSnapshot($rows));
    }

    private function showTicket(TelegramInteractionAction $action, int $ticketId): void
    {
        $detail = $this->tickets->ticketForCustomer($ticketId, $action->userId);
        $session = $this->transition($action, self::STATE_TICKET, ['ticket_id' => $ticketId], 'ticket-'.$ticketId);
        if ($session !== null) {
            $this->renderCustomerTicket($action, $session->version, $detail, 'ticket');
        }
    }

    private function renderCustomerTicket(TelegramInteractionAction $action, int $version, SupportTicketDetailSnapshot $detail, string $surface): void
    {
        $locale = $this->locale($action->userId);
        $rows = [];
        if (! in_array($detail->ticket->state, [SupportTicketState::Resolved, SupportTicketState::Closed], true)) {
            $reply = $this->callbacks->issue($action->sessionPublicId, $version, self::ACTION_REPLY, [], 'tg-support-reply:'.hash('sha256', $action->requestKey));
            $close = $this->callbacks->issue($action->sessionPublicId, $version, self::ACTION_CLOSE, [], 'tg-support-close:'.hash('sha256', $action->requestKey));
            $rows[] = [new TelegramInlineCallbackButton($this->translation('telegram_support.buttons.reply', $locale), $reply->publicId, TelegramInlineButtonStyle::Primary)];
            $rows[] = [new TelegramInlineCallbackButton($this->translation('telegram_support.buttons.close', $locale), $close->publicId)];
        } elseif ($detail->ticket->state === SupportTicketState::Resolved) {
            $close = $this->callbacks->issue($action->sessionPublicId, $version, self::ACTION_CLOSE, [], 'tg-support-close:'.hash('sha256', $action->requestKey));
            $rows[] = [new TelegramInlineCallbackButton($this->translation('telegram_support.buttons.close', $locale), $close->publicId)];
        } else {
            $reopen = $this->callbacks->issue($action->sessionPublicId, $version, self::ACTION_REOPEN, [], 'tg-support-reopen:'.hash('sha256', $action->requestKey));
            $rows[] = [new TelegramInlineCallbackButton($this->translation('telegram_support.buttons.reopen', $locale), $reopen->publicId, TelegramInlineButtonStyle::Primary)];
        }
        $this->appendBack($action, $version, $rows, $locale);
        $this->queue($action, $this->ticketText($detail, $locale, false), $surface, new TelegramInlineKeyboardSnapshot($rows));
    }

    private function showQueue(TelegramInteractionAction $action): void
    {
        $tickets = $this->support->queue($action->userId, 20);
        $session = $this->transition($action, self::STATE_QUEUE, [], 'queue');
        if ($session === null) {
            return;
        }
        $locale = $this->locale($action->userId);
        $rows = [];
        $items = [];
        foreach ($tickets as $ticket) {
            $callback = $this->callbacks->issue($action->sessionPublicId, $session->version, self::ACTION_QUEUE_TICKET, ['ticket_id' => $ticket->id], 'tg-support-queue-ticket:'.hash('sha256', $action->requestKey.':'.$ticket->id));
            $rows[] = [new TelegramInlineCallbackButton($this->ticketButtonText($ticket->trackingNumber, $ticket->title), $callback->publicId)];
            $items[] = $this->translation('telegram_support.queue_item', $locale, [
                'tracking' => $ticket->trackingNumber,
                'title' => $ticket->title,
                'state' => $this->stateLabel($ticket->state, $locale),
                'priority' => $this->priorityLabel($ticket->priority, $locale),
                'assignment' => $this->assignmentText($ticket->assignedUserId, $locale),
            ]);
        }
        $this->appendBack($action, $session->version, $rows, $locale);
        $text = $tickets === [] ? $this->translation('telegram_support.queue_empty', $locale) : $this->translation('telegram_support.queue', $locale, ['items' => implode("\n\n", $items)]);
        $this->queue($action, $text, 'queue', new TelegramInlineKeyboardSnapshot($rows));
    }

    /** @param list<\App\Modules\Support\Application\SupportTicketSnapshot> $tickets */
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
                'tg-support-search-ticket:'.hash('sha256', $action->requestKey.':'.$ticket->id),
            );
            $rows[] = [new TelegramInlineCallbackButton($this->ticketButtonText($ticket->trackingNumber, $ticket->title), $callback->publicId)];
            $items[] = $this->translation('telegram_support.queue_item', $locale, [
                'tracking' => $ticket->trackingNumber,
                'title' => $ticket->title,
                'state' => $this->stateLabel($ticket->state, $locale),
                'priority' => $this->priorityLabel($ticket->priority, $locale),
                'assignment' => $this->assignmentText($ticket->assignedUserId, $locale),
            ]);
        }
        $text = $tickets === []
            ? $this->translation('telegram_support.search_empty', $locale)
            : $this->translation('telegram_support.search_results', $locale, ['items' => implode("\n\n", $items)]);
        $this->queue($action, $text, 'search-results', new TelegramInlineKeyboardSnapshot($rows));
    }

    private function showQueueTicket(TelegramInteractionAction $action, int $ticketId): void
    {
        $detail = $this->support->detail($action->userId, $ticketId);
        $session = $this->transition($action, self::STATE_QUEUE_TICKET, ['ticket_id' => $ticketId], 'queue-ticket-'.$ticketId);
        if ($session !== null) {
            $this->renderQueueTicket($action, $session->version, $detail, 'queue-ticket');
        }
    }

    private function renderQueueTicket(TelegramInteractionAction $action, int $version, SupportTicketDetailSnapshot $detail, string $surface): void
    {
        $locale = $this->locale($action->userId);
        $rows = [];
        if ($detail->ticket->state !== SupportTicketState::Closed && $detail->ticket->assignedUserId === null) {
            $claim = $this->callbacks->issue($action->sessionPublicId, $version, self::ACTION_CLAIM, [], 'tg-support-claim:'.hash('sha256', $action->requestKey));
            $rows[] = [new TelegramInlineCallbackButton($this->translation('telegram_support.buttons.claim', $locale), $claim->publicId, TelegramInlineButtonStyle::Primary)];
        }
        if (! in_array($detail->ticket->state, [SupportTicketState::Resolved, SupportTicketState::Closed], true)) {
            $reply = $this->callbacks->issue($action->sessionPublicId, $version, self::ACTION_QUEUE_REPLY, [], 'tg-support-staff-reply:'.hash('sha256', $action->requestKey));
            $rows[] = [new TelegramInlineCallbackButton($this->translation('telegram_support.buttons.reply', $locale), $reply->publicId, TelegramInlineButtonStyle::Primary)];
        }
        $note = $this->callbacks->issue($action->sessionPublicId, $version, self::ACTION_QUEUE_NOTE, [], 'tg-support-note:'.hash('sha256', $action->requestKey));
        $allowedStates = $this->support->allowedTransitions($action->userId, $detail->ticket->id);
        $rows[] = [new TelegramInlineCallbackButton($this->translation('telegram_support.buttons.internal_note', $locale), $note->publicId)];
        if ($detail->ticket->state !== SupportTicketState::Closed) {
            $priority = $this->callbacks->issue($action->sessionPublicId, $version, self::ACTION_QUEUE_PRIORITY, [], 'tg-support-priority:'.hash('sha256', $action->requestKey));
            $controls = [new TelegramInlineCallbackButton($this->translation('telegram_support.buttons.priority', $locale), $priority->publicId)];
            if ($allowedStates !== []) {
                $state = $this->callbacks->issue($action->sessionPublicId, $version, self::ACTION_QUEUE_STATE, [], 'tg-support-state:'.hash('sha256', $action->requestKey));
                $controls[] = new TelegramInlineCallbackButton($this->translation('telegram_support.buttons.state', $locale), $state->publicId);
            }
            $rows[] = $controls;
        } elseif ($allowedStates !== []) {
            $state = $this->callbacks->issue($action->sessionPublicId, $version, self::ACTION_QUEUE_STATE, [], 'tg-support-state:'.hash('sha256', $action->requestKey));
            $rows[] = [new TelegramInlineCallbackButton($this->translation('telegram_support.buttons.state', $locale), $state->publicId)];
        }
        $this->appendBack($action, $version, $rows, $locale);
        $this->queue($action, $this->ticketText($detail, $locale, true), $surface, new TelegramInlineKeyboardSnapshot($rows));
    }

    private function beginQueueText(TelegramInteractionAction $action, int $ticketId, string $state, string $translationKey, string $surface): void
    {
        $this->support->detail($action->userId, $ticketId);
        $session = $this->transition($action, $state, ['ticket_id' => $ticketId], $surface);
        if ($session !== null) {
            $this->queue($action, $this->translation($translationKey, $this->locale($action->userId)), $surface, $this->backKeyboard($action, $session->version));
        }
    }

    private function showPriorityChoices(TelegramInteractionAction $action, int $ticketId): void
    {
        $this->support->detail($action->userId, $ticketId);
        $session = $this->transition($action, self::STATE_QUEUE_PRIORITY, ['ticket_id' => $ticketId], 'priority-choices');
        if ($session === null) {
            return;
        }
        $locale = $this->locale($action->userId);
        $rows = [];
        foreach (SupportTicketPriority::cases() as $priority) {
            $callback = $this->callbacks->issue($action->sessionPublicId, $session->version, self::ACTION_QUEUE_PRIORITY_SET, ['priority' => $priority->value], 'tg-support-priority-choice:'.hash('sha256', $action->requestKey.':'.$priority->value));
            $rows[] = [new TelegramInlineCallbackButton($this->priorityLabel($priority, $locale), $callback->publicId)];
        }
        $this->appendBack($action, $session->version, $rows, $locale);
        $this->queue($action, $this->translation('telegram_support.priority_prompt', $locale), 'priority-choices', new TelegramInlineKeyboardSnapshot($rows));
    }

    private function showStateChoices(TelegramInteractionAction $action, int $ticketId): void
    {
        $allowedStates = $this->support->allowedTransitions($action->userId, $ticketId);
        $session = $this->transition($action, self::STATE_QUEUE_STATE, ['ticket_id' => $ticketId], 'state-choices');
        if ($session === null) {
            return;
        }
        $locale = $this->locale($action->userId);
        $rows = [];
        foreach ($allowedStates as $state) {
            $callback = $this->callbacks->issue($action->sessionPublicId, $session->version, self::ACTION_QUEUE_STATE_SET, ['state' => $state->value], 'tg-support-state-choice:'.hash('sha256', $action->requestKey.':'.$state->value));
            $rows[] = [new TelegramInlineCallbackButton($this->stateLabel($state, $locale), $callback->publicId)];
        }
        $this->appendBack($action, $session->version, $rows, $locale);
        $this->queue($action, $this->translation('telegram_support.state_prompt', $locale), 'state-choices', new TelegramInlineKeyboardSnapshot($rows));
    }

    private function ticketText(SupportTicketDetailSnapshot $detail, string $locale, bool $supportView): string
    {
        $render = function (string $messageText) use ($detail, $locale, $supportView): string {
            $replace = [
                'tracking' => $detail->ticket->trackingNumber,
                'title' => $detail->ticket->title,
                'state' => $this->stateLabel($detail->ticket->state, $locale),
                'priority' => $this->priorityLabel($detail->ticket->priority, $locale),
                'messages' => $messageText,
            ];
            if ($supportView) {
                $replace['assignment'] = $this->assignmentText($detail->ticket->assignedUserId, $locale);

                return $this->translation('telegram_support.support_detail', $locale, $replace);
            }

            return $this->translation('telegram_support.detail', $locale, $replace);
        };

        if ($detail->messages === []) {
            return $render($this->translation('telegram_support.history_empty', $locale));
        }

        $messages = [];
        foreach ($detail->messages as $message) {
            $key = match ($message->kind) {
                SupportTicketMessageKind::CustomerReply => 'telegram_support.message_customer',
                SupportTicketMessageKind::SupportReply => 'telegram_support.message_support',
                SupportTicketMessageKind::InternalNote => 'telegram_support.message_internal',
            };
            $messages[] = $this->translation($key, $locale, ['body' => $message->body]);
        }

        $omitted = $this->translation('telegram_support.history_older_omitted', $locale);
        $selected = [];
        $accepted = null;
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            array_unshift($selected, $messages[$index]);
            $history = ($index > 0 ? $omitted."\n\n" : '').implode("\n\n", $selected);
            $candidate = $render($history);
            if (mb_strlen($candidate) <= ConfidentialTelegramPresentation::MAXIMUM_TEXT_CHARACTERS) {
                $accepted = $candidate;

                continue;
            }

            array_shift($selected);
            break;
        }

        if ($accepted !== null) {
            return $accepted;
        }

        $latest = $messages[array_key_last($messages)];
        $prefix = count($messages) > 1 ? $omitted."\n\n" : '';

        return $this->fitLatestTicketMessage($render, $prefix, $latest);
    }

    /** @param Closure(string):string $render */
    private function fitLatestTicketMessage(Closure $render, string $prefix, string $latest): string
    {
        $limit = ConfidentialTelegramPresentation::MAXIMUM_TEXT_CHARACTERS;
        $best = $render($prefix.'…');
        if (mb_strlen($best) > $limit) {
            return $this->boundedPresentationText($best);
        }

        $latestLength = mb_strlen($latest);
        $low = 0;
        $high = $latestLength;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $history = $prefix.mb_substr($latest, 0, $middle).($middle < $latestLength ? '…' : '');
            $candidate = $render($history);
            if (mb_strlen($candidate) <= $limit) {
                $best = $candidate;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $best;
    }

    private function assignmentText(?int $assignedUserId, string $locale): string
    {
        return $assignedUserId === null
            ? $this->translation('telegram_support.assigned_none', $locale)
            : $this->translation('telegram_support.assigned_user', $locale, ['user_id' => $assignedUserId]);
    }

    private function handleBack(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === self::STATE_HOME) {
            $this->returnMain($action);

            return;
        }
        if (in_array($action->sessionState, [self::STATE_QUEUE_TICKET, self::STATE_QUEUE_REPLY, self::STATE_QUEUE_NOTE, self::STATE_QUEUE_PRIORITY, self::STATE_QUEUE_STATE, self::STATE_QUEUE_CLOSE], true)) {
            $this->showQueue($action);

            return;
        }
        $this->showHome($action);
    }

    private function returnMain(TelegramInteractionAction $action): void
    {
        $session = $this->transition($action, TelegramNavigationEntryGateway::STATE, [], 'main');
        if ($session === null) {
            return;
        }
        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':support-main',
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

    private function assertMembership(int $userId, string $membershipAction): void
    {
        $result = $this->membership->evaluate(new TelegramChannelMembershipResolutionRequest($userId, $membershipAction));
        if (! in_array($result->decision, [TelegramChannelMembershipEvaluationDecision::NotRequired, TelegramChannelMembershipEvaluationDecision::Satisfied], true)) {
            throw new AuthorizationException('Telegram Support membership requirement is not satisfied.');
        }
    }

    /**
     * Recover only the idempotent presentation after an atomic Support mutation
     * committed but presentation queuing did not complete. The durable +2
     * session version proves claim + completion committed in the same DB
     * transaction as the business mutation.
     */
    private function recoverAdvancedMutation(TelegramInteractionAction $action): bool
    {
        $session = $this->sessions->activeForAccount($action->telegramAccountId);
        if ($session === null
            || $session->publicId !== $action->sessionPublicId
            || $session->userId !== $action->userId
            || $session->version <= $action->sessionVersion) {
            return false;
        }

        if ($session->version !== $action->sessionVersion + 2) {
            return false;
        }

        $recovery = $this->mutationRecovery($action);
        if ($recovery === null) {
            return false;
        }
        [$expectedState, $surface, $supportView] = $recovery;
        if ($session->state !== $expectedState) {
            return true;
        }

        $ticketId = $this->positivePayloadId($session->payload, 'ticket_id');
        if ($supportView) {
            $this->renderQueueTicket(
                $action,
                $session->version,
                $this->support->detail($action->userId, $ticketId),
                $surface,
            );
        } else {
            $this->renderCustomerTicket(
                $action,
                $session->version,
                $this->tickets->ticketForCustomer($ticketId, $action->userId),
                $surface,
            );
        }

        return true;
    }

    /** @return array{0:string,1:string,2:bool}|null */
    private function mutationRecovery(TelegramInteractionAction $action): ?array
    {
        if ($action->sessionState === self::STATE_CREATE_DESCRIPTION
            && $action->kind === TelegramInteractionActionKind::Message) {
            return [self::STATE_TICKET, 'created', false];
        }
        if ($action->sessionState === self::STATE_REPLY
            && $action->kind === TelegramInteractionActionKind::Message) {
            return [self::STATE_TICKET, 'replied', false];
        }
        if ($action->sessionState === self::STATE_CLOSE
            && $action->kind === TelegramInteractionActionKind::Message) {
            return [self::STATE_TICKET, 'closed', false];
        }
        if ($action->sessionState === self::STATE_TICKET
            && $action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_REOPEN) {
            return [self::STATE_TICKET, 'reopened', false];
        }
        if ($action->sessionState === self::STATE_QUEUE_TICKET
            && $action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_CLAIM) {
            return [self::STATE_QUEUE_TICKET, 'claimed', true];
        }
        if ($action->sessionState === self::STATE_QUEUE_TICKET
            && $action->kind === TelegramInteractionActionKind::Message
            && $this->isAssignCommand($action->messageText)) {
            return [self::STATE_QUEUE_TICKET, 'assigned', true];
        }
        if ($action->sessionState === self::STATE_QUEUE_REPLY
            && $action->kind === TelegramInteractionActionKind::Message) {
            return [self::STATE_QUEUE_TICKET, 'support-replied', true];
        }
        if ($action->sessionState === self::STATE_QUEUE_NOTE
            && $action->kind === TelegramInteractionActionKind::Message) {
            return [self::STATE_QUEUE_TICKET, 'internal-note', true];
        }
        if ($action->sessionState === self::STATE_QUEUE_PRIORITY
            && $action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_QUEUE_PRIORITY_SET) {
            return [self::STATE_QUEUE_TICKET, 'priority', true];
        }
        if ($action->sessionState === self::STATE_QUEUE_STATE
            && $action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_QUEUE_STATE_SET
            && ($action->callbackPayload['state'] ?? null) !== SupportTicketState::Closed->value) {
            return [self::STATE_QUEUE_TICKET, 'state', true];
        }
        if ($action->sessionState === self::STATE_QUEUE_CLOSE
            && $action->kind === TelegramInteractionActionKind::Message) {
            return [self::STATE_QUEUE_TICKET, 'support-closed', true];
        }

        return null;
    }

    /**
     * @param  Closure(): mixed  $mutation
     */
    private function commitTicketMutation(
        TelegramInteractionAction $action,
        int $ticketId,
        string $targetState,
        string $operation,
        Closure $mutation,
    ): ?TelegramInteractionSessionReceipt {
        try {
            return $this->database->connection()->transaction(function () use ($action, $ticketId, $targetState, $operation, $mutation): TelegramInteractionSessionReceipt {
                $claim = $this->claimMutationSession($action, $operation);
                $mutation();

                return $this->completeMutationSession(
                    $action,
                    $claim,
                    $targetState,
                    ['ticket_id' => $ticketId],
                    $operation,
                );
            }, 3);
        } catch (TelegramSupportSessionRace) {
            return null;
        }
    }

    /** @return array{0:TelegramInteractionSessionReceipt,1:int}|null */
    private function commitTicketCreation(
        TelegramInteractionAction $action,
        SupportTicketCreateRequest $request,
    ): ?array {
        try {
            return $this->database->connection()->transaction(function () use ($action, $request): array {
                $claim = $this->claimMutationSession($action, 'customer-create');
                $ticket = $this->tickets->create($request);
                $completed = $this->completeMutationSession(
                    $action,
                    $claim,
                    self::STATE_TICKET,
                    ['ticket_id' => $ticket->id],
                    'customer-create',
                );

                return [$completed, $ticket->id];
            }, 3);
        } catch (TelegramSupportSessionRace) {
            return null;
        }
    }

    private function claimMutationSession(
        TelegramInteractionAction $action,
        string $operation,
    ): TelegramInteractionSessionReceipt {
        try {
            $claim = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_MUTATING,
                ['operation' => $operation],
                'tg-support-mutation-claim:'.hash('sha256', $action->requestKey.':'.$operation),
            );
        } catch (DomainException $exception) {
            throw new TelegramSupportSessionRace('Telegram Support mutation lost the session claim race.', 0, $exception);
        }
        $this->assertSessionActor($action, $claim);

        return $claim;
    }

    /** @param array<string,mixed> $payload */
    private function completeMutationSession(
        TelegramInteractionAction $action,
        TelegramInteractionSessionReceipt $claim,
        string $targetState,
        array $payload,
        string $operation,
    ): TelegramInteractionSessionReceipt {
        try {
            $completed = $this->sessions->transition(
                $action->sessionPublicId,
                $claim->version,
                $targetState,
                $payload,
                'tg-support-mutation-complete:'.hash('sha256', $action->requestKey.':'.$operation),
            );
        } catch (DomainException $exception) {
            throw new TelegramSupportSessionRace('Telegram Support mutation lost the session completion race.', 0, $exception);
        }
        $this->assertSessionActor($action, $completed);

        return $completed;
    }

    private function assertSessionActor(
        TelegramInteractionAction $action,
        TelegramInteractionSessionReceipt $session,
    ): void {
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram Support session actor binding is invalid.');
        }
    }

    private function sessionAdvancedPast(TelegramInteractionAction $action): bool
    {
        $session = $this->sessions->activeForAccount($action->telegramAccountId);

        return $session !== null
            && $session->publicId === $action->sessionPublicId
            && $session->userId === $action->userId
            && $session->version > $action->sessionVersion;
    }

    /** @param array<string,mixed> $payload */
    private function positivePayloadId(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if ((! is_int($value) && ! is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new RuntimeException('Telegram Support payload identity is invalid.');
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $payload */
    private function stringPayload(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('Telegram Support payload value is invalid.');
        }

        return $value;
    }

    private function requireCallback(TelegramInteractionAction $action): void
    {
        if ($action->kind !== TelegramInteractionActionKind::Callback || $action->callbackAction === null) {
            throw new RuntimeException('Telegram Support state expects a callback.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function transition(TelegramInteractionAction $action, string $state, array $payload, string $suffix): ?TelegramInteractionSessionReceipt
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                $state,
                $payload,
                'tg-support-transition:'.hash('sha256', $action->requestKey.':'.$suffix),
            );
        } catch (DomainException) {
            return null;
        }
        $this->assertSessionActor($action, $session);

        return $session;
    }

    /** @param list<list<TelegramInlineCallbackButton>> $rows */
    private function appendBack(TelegramInteractionAction $action, int $version, array &$rows, string $locale): void
    {
        $back = $this->callbacks->issue($action->sessionPublicId, $version, self::ACTION_BACK, [], 'tg-support-back:'.hash('sha256', $action->requestKey.':'.$version));
        $rows[] = [new TelegramInlineCallbackButton($this->translation('telegram_support.buttons.back', $locale), $back->publicId)];
    }

    private function backKeyboard(TelegramInteractionAction $action, int $version): TelegramInlineKeyboardSnapshot
    {
        $rows = [];
        $this->appendBack($action, $version, $rows, $this->locale($action->userId));

        return new TelegramInlineKeyboardSnapshot($rows);
    }

    private function ticketButtonText(string $trackingNumber, string $title): string
    {
        return $this->boundedButtonText($trackingNumber.' · '.$title);
    }

    private function boundedButtonText(string $text): string
    {
        $text = trim($text);
        $limit = TelegramInlineCallbackButton::MAXIMUM_TEXT_CHARACTERS;
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit - 1).'…';
    }

    private function boundedPresentationText(string $text): string
    {
        $limit = ConfidentialTelegramPresentation::MAXIMUM_TEXT_CHARACTERS;
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit - 1).'…';
    }

    private function queue(TelegramInteractionAction $action, string $text, string $surface, TelegramInlineKeyboardSnapshot $keyboard): void
    {
        $text = $this->boundedPresentationText($text);
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
            'tg-support-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
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
            throw new RuntimeException('Telegram Support translation is unavailable.');
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram Support translation has an unresolved placeholder.');
        }

        return $value;
    }

    private function stateLabel(SupportTicketState $state, string $locale): string
    {
        return $this->translation('telegram_support.states.'.$state->value, $locale);
    }

    private function priorityLabel(SupportTicketPriority $priority, string $locale): string
    {
        return $this->translation('telegram_support.priorities.'.$priority->value, $locale);
    }

    private function idempotencyKey(string $operation, string $requestKey): string
    {
        return 'tg-'.$operation.':'.hash('sha256', $requestKey);
    }

    private function isAssignCommand(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        return preg_match('/\A\/support-assign(?:@[A-Za-z0-9_]+)?\s+[1-9][0-9]{0,18}\z/u', trim($text)) === 1;
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

final class TelegramSupportSessionRace extends RuntimeException {}

<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramAdministratorCustomerTargetDiscovery;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Administrator customer target discovery/preview plus the bounded COM-001
 * text-only direct-message journey. Customer lookup remains owned by Customers;
 * external delivery remains owned by the existing confidential #212/#179 path.
 */
final readonly class TelegramAdminCustomerNavigationHandler
{
    public const ACTION_SEARCH = 'navigation.admin.customer_search';

    private const STATE_SEARCH = 'admin_customer_search';

    private const STATE_PREVIEW = 'admin_customer_preview';

    private const STATE_MESSAGE_COMPOSE = 'admin_customer_message_compose';

    private const STATE_MESSAGE_CONFIRM = 'admin_customer_message_confirm';

    private const STATE_MESSAGE_SUBMITTING = 'admin_customer_message_submitting';

    private const ACTION_BACK = 'navigation.back';

    private const ACTION_MESSAGE = 'navigation.admin.customer.message';

    private const ACTION_MESSAGE_CONFIRM = 'navigation.admin.customer.message.confirm';

    private const MESSAGE_CONFIRMATION_HEADER_MAX_LENGTH = 594;

    private const TELEGRAM_TEXT_MAX_LENGTH = 4096;

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramAdministratorCustomerTargetDiscovery $targets,
        private TelegramAdministratorDirectMessageService $directMessages,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === 'admin_control'
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_SEARCH)
            || in_array($action->sessionState, [
                self::STATE_SEARCH,
                self::STATE_PREVIEW,
                self::STATE_MESSAGE_COMPOSE,
                self::STATE_MESSAGE_CONFIRM,
                self::STATE_MESSAGE_SUBMITTING,
            ], true);
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'admin_control') {
            if ($action->kind !== TelegramInteractionActionKind::Callback
                || $action->callbackAction !== self::ACTION_SEARCH
                || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram administrator customer search entry is invalid.');
            }
            $this->showSearch($action);

            return;
        }

        match ($action->sessionState) {
            self::STATE_SEARCH => $this->handleSearch($action),
            self::STATE_PREVIEW => $this->handlePreview($action),
            self::STATE_MESSAGE_COMPOSE => $this->handleMessageCompose($action),
            self::STATE_MESSAGE_CONFIRM => $this->handleMessageConfirm($action),
            self::STATE_MESSAGE_SUBMITTING => $this->handleMessageSubmitting($action),
            default => throw new RuntimeException('Telegram administrator customer navigation state is unsupported.'),
        };
    }

    private function handleSearch(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->navigation->showAdminControl($action);

                return;
            }

            throw new RuntimeException('Telegram administrator customer search callback is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->navigation->showAdminControl($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }

        $result = $this->targets->search($action->userId, $action->botId, $action->messageText);
        if ($result->disposition === TelegramAdministratorCustomerTargetSearchDisposition::NotFound) {
            $this->renderSearch($action, $action->sessionVersion, 'not_found');

            return;
        }
        if ($result->disposition === TelegramAdministratorCustomerTargetSearchDisposition::Ambiguous) {
            $this->renderSearch($action, $action->sessionVersion, 'ambiguous');

            return;
        }
        if ($result->target === null) {
            throw new RuntimeException('Telegram administrator customer search result is invalid.');
        }

        try {
            $target = $this->targets->resolve(
                $action->userId,
                $action->botId,
                $result->target->selectionToken,
            );
        } catch (AuthorizationException) {
            $this->navigation->showAdminControl($action);

            return;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_PREVIEW,
                ['selection' => $target->selectionToken],
                'tg-admin-customer-preview:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderPreview($action, $session->version, $target);
    }

    private function handlePreview(TelegramInteractionAction $action): void
    {
        $selection = $this->selectionFromPayload($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showSearch($action);

                return;
            }
            if ($action->callbackAction === self::ACTION_MESSAGE && $action->callbackPayload === []) {
                $this->showMessageCompose($action, $selection);

                return;
            }

            throw new RuntimeException('Telegram administrator customer preview callback is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showSearch($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }

        $target = $this->targets->resolve($action->userId, $action->botId, $selection);
        $this->renderPreview($action, $action->sessionVersion, $target);
    }

    private function handleMessageCompose(TelegramInteractionAction $action): void
    {
        $selection = $this->selectionFromPayload($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showPreviewState($action, $selection, 'message-compose-back');

                return;
            }

            throw new RuntimeException('Telegram administrator direct-message composition callback is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showPreviewState($action, $selection, 'message-compose-back');

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }

        try {
            $draft = $this->directMessages->createTextDraft(
                $action->userId,
                $action->botId,
                $selection,
                $action->messageText,
                $action->requestKey,
            );
        } catch (AuthorizationException) {
            $this->showPreviewState($action, $selection, 'message-authorization-lost');

            return;
        } catch (DomainException) {
            $target = $this->targets->resolve($action->userId, $action->botId, $selection);
            $this->renderMessageCompose($action, $action->sessionVersion, $target, 'message_invalid');

            return;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_MESSAGE_CONFIRM,
                ['draft' => $draft->publicId, 'selection' => $selection],
                'tg-admin-customer-message-confirm:'.hash('sha256', $action->requestKey.':'.$draft->publicId),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderMessageConfirmation($action, $session->version, $selection, $draft->publicId);
    }

    private function handleMessageConfirm(TelegramInteractionAction $action): void
    {
        [$selection, $draftPublicId] = $this->messageStateFromPayload($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showMessageCompose($action, $selection);

                return;
            }
            if ($action->callbackAction === self::ACTION_MESSAGE_CONFIRM && $action->callbackPayload === []) {
                if ($action->replayed
                    && $this->recoverAdvancedMessageSubmission($action, $selection, $draftPublicId)) {
                    return;
                }
                $this->beginMessageSubmission($action, $selection, $draftPublicId);

                return;
            }

            throw new RuntimeException('Telegram administrator direct-message confirmation callback is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showMessageCompose($action, $selection);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }

        try {
            $this->renderMessageConfirmation($action, $action->sessionVersion, $selection, $draftPublicId);
        } catch (AuthorizationException) {
            $this->showPreviewState($action, $selection, 'message-authorization-lost');
        } catch (DomainException) {
            $this->showPreviewState($action, $selection, 'message-unavailable', 'message_unavailable');
        }
    }

    private function handleMessageSubmitting(TelegramInteractionAction $action): void
    {
        [$selection, $draftPublicId] = $this->submittingStateFromPayload($action->sessionPayload);
        $this->resumeMessageSubmission($action, $action->sessionVersion, $selection, $draftPublicId);
    }

    private function recoverAdvancedMessageSubmission(
        TelegramInteractionAction $action,
        string $selection,
        string $draftPublicId,
    ): bool {
        $session = $this->sessions->activeForAccount($action->telegramAccountId);
        if ($session === null
            || $session->publicId !== $action->sessionPublicId
            || $session->userId !== $action->userId
            || $session->version <= $action->sessionVersion) {
            return false;
        }

        // A newer durable session version proves that this accepted callback's
        // original confirmation snapshot was already consumed. Never rerun the
        // pre-acceptance authorization path against that stale snapshot.
        if ($session->state !== self::STATE_MESSAGE_SUBMITTING) {
            return true;
        }

        [$currentSelection, $currentDraftPublicId] = $this->submittingStateFromPayload($session->payload);
        if (! hash_equals($selection, $currentSelection)
            || ! hash_equals($draftPublicId, $currentDraftPublicId)) {
            return true;
        }

        $this->resumeMessageSubmission(
            $this->actionForSession($action, $session, 'message-submit-replay'),
            $session->version,
            $currentSelection,
            $currentDraftPublicId,
        );

        return true;
    }

    private function beginMessageSubmission(
        TelegramInteractionAction $action,
        string $selection,
        string $draftPublicId,
    ): void {
        try {
            $this->directMessages->draftForConfirmation(
                $action->userId,
                $action->botId,
                $selection,
                $draftPublicId,
            );
        } catch (AuthorizationException) {
            $this->showPreviewState($action, $selection, 'message-authorization-lost');

            return;
        } catch (DomainException) {
            $this->showPreviewState($action, $selection, 'message-unavailable', 'message_unavailable');

            return;
        }

        $connection = $this->database->connection();
        try {
            $session = $connection->transaction(function () use ($action, $selection, $draftPublicId) {
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_MESSAGE_SUBMITTING,
                    [
                        'cancel_locked' => true,
                        'draft' => $draftPublicId,
                        'expiry_locked' => true,
                        'selection' => $selection,
                    ],
                    'tg-admin-customer-message-submit:'.hash('sha256', $draftPublicId),
                );

                $this->directMessages->acceptTextConfirmation(
                    $action->userId,
                    $action->botId,
                    $selection,
                    $draftPublicId,
                );

                return $session;
            }, 3);
        } catch (AuthorizationException) {
            $this->showPreviewState($action, $selection, 'message-authorization-lost');

            return;
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);

        $this->resumeMessageSubmission($action, $session->version, $selection, $draftPublicId);
    }

    private function resumeMessageSubmission(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $selection,
        string $draftPublicId,
    ): void {
        try {
            $this->directMessages->confirmText(
                $action->userId,
                $action->botId,
                $selection,
                $draftPublicId,
            );
        } catch (AuthorizationException) {
            $this->recoverSubmissionToAdminControl($action, $sessionVersion);

            return;
        } catch (DomainException) {
            $this->recoverSubmissionToPreview(
                $action,
                $sessionVersion,
                $selection,
                $draftPublicId,
                'message_unavailable',
            );

            return;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $sessionVersion,
                self::STATE_PREVIEW,
                ['selection' => $selection],
                'tg-admin-customer-message-complete:'.$draftPublicId,
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);

        try {
            $target = $this->targets->resolve($action->userId, $action->botId, $selection);
            $this->renderPreview($action, $session->version, $target, 'message_queued');
        } catch (AuthorizationException) {
            $this->navigation->showAdminControl(
                $this->actionForSession($action, $session, 'message-complete-auth-lost'),
            );
        }
    }

    private function recoverSubmissionToAdminControl(TelegramInteractionAction $action, int $sessionVersion): void
    {
        $session = $this->sessions->activeForAccount($action->telegramAccountId);
        if ($session === null
            || $session->publicId !== $action->sessionPublicId
            || $session->version !== $sessionVersion
            || $session->state !== self::STATE_MESSAGE_SUBMITTING) {
            return;
        }

        $this->navigation->showAdminControl(
            $this->actionForSession($action, $session, 'message-auth-lost'),
        );
    }

    private function recoverSubmissionToPreview(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $selection,
        string $draftPublicId,
        string $notice,
    ): void {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $sessionVersion,
                self::STATE_PREVIEW,
                ['selection' => $selection],
                'tg-admin-customer-message-recover:'.$draftPublicId,
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);

        try {
            $target = $this->targets->resolve($action->userId, $action->botId, $selection);
            $this->renderPreview($action, $session->version, $target, $notice);
        } catch (AuthorizationException) {
            $this->navigation->showAdminControl(
                $this->actionForSession($action, $session, 'message-recover-auth-lost'),
            );
        }
    }

    private function showSearch(TelegramInteractionAction $action): void
    {
        if (! $this->targets->availableFor($action->userId)) {
            $this->navigation->showAdminControl($action);

            return;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_SEARCH,
                [],
                'tg-admin-customer-search:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderSearch($action, $session->version);
    }

    private function showMessageCompose(TelegramInteractionAction $action, string $selection): void
    {
        if (! $this->directMessages->availableFor($action->userId)) {
            $this->showPreviewState($action, $selection, 'message-permission-unavailable');

            return;
        }

        try {
            $target = $this->targets->resolve($action->userId, $action->botId, $selection);
        } catch (AuthorizationException) {
            $this->navigation->showAdminControl($action);

            return;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_MESSAGE_COMPOSE,
                ['selection' => $selection],
                'tg-admin-customer-message-compose:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderMessageCompose($action, $session->version, $target);
    }

    private function showPreviewState(
        TelegramInteractionAction $action,
        string $selection,
        string $surface,
        ?string $notice = null,
    ): void {
        try {
            $target = $this->targets->resolve($action->userId, $action->botId, $selection);
        } catch (AuthorizationException) {
            $this->navigation->showAdminControl($action);

            return;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_PREVIEW,
                ['selection' => $selection],
                'tg-admin-customer-preview-return:'.hash('sha256', $action->requestKey.':'.$surface),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderPreview($action, $session->version, $target, $notice);
    }

    private function renderSearch(
        TelegramInteractionAction $action,
        int $sessionVersion,
        ?string $notice = null,
    ): void {
        $locale = $this->locale($action->userId);
        $text = $this->translation('telegram.navigation.admin.customer_search.prompt', $locale);
        if ($notice !== null) {
            $text .= "\n\n".$this->translation('telegram.navigation.admin.customer_search.'.$notice, $locale);
        }

        $this->queue(
            $action,
            $text,
            'search',
            $this->backKeyboard($action, $sessionVersion, $locale, 'search'),
        );
    }

    private function renderPreview(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramAdministratorCustomerTarget $target,
        ?string $notice = null,
    ): void {
        $locale = $this->locale($action->userId);
        $username = $target->maskedUsername
            ?? $this->translation('telegram.navigation.admin.customer_search.username_unavailable', $locale);
        $previewKey = $notice === 'message_queued' ? 'preview_after_message' : 'preview';
        $text = $this->translation('telegram.navigation.admin.customer_search.'.$previewKey, $locale, [
            'telegram_id' => $target->telegramUserId,
            'account_id' => $target->accountPublicId,
            'username' => $username,
            'account_type' => $this->translation(
                'telegram.navigation.account.values.account_type.'.$target->accountType,
                $locale,
            ),
            'account_status' => $this->translation(
                'telegram.navigation.account.values.account_status.'.$target->accountStatus,
                $locale,
            ),
        ]);
        if ($notice !== null && $notice !== 'message_queued') {
            $text .= "\n\n".$this->translation('telegram.navigation.admin.customer_search.'.$notice, $locale);
        }

        $this->queue(
            $action,
            $text,
            'preview',
            $this->previewKeyboard($action, $sessionVersion, $locale),
        );
    }

    private function renderMessageCompose(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramAdministratorCustomerTarget $target,
        ?string $notice = null,
    ): void {
        $locale = $this->locale($action->userId);
        $username = $target->maskedUsername
            ?? $this->translation('telegram.navigation.admin.customer_search.username_unavailable', $locale);
        $text = $this->translation('telegram.navigation.admin.customer_search.message_prompt', $locale, [
            'telegram_id' => $target->telegramUserId,
            'account_id' => $target->accountPublicId,
            'username' => $username,
        ]);
        if ($notice !== null) {
            $text .= "\n\n".$this->translation('telegram.navigation.admin.customer_search.'.$notice, $locale);
        }

        $this->queue(
            $action,
            $text,
            'message-compose',
            $this->backKeyboard($action, $sessionVersion, $locale, 'message-compose'),
        );
    }

    private function renderMessageConfirmation(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $selection,
        string $draftPublicId,
    ): void {
        $target = $this->targets->resolve($action->userId, $action->botId, $selection);
        $draft = $this->directMessages->draftForConfirmation(
            $action->userId,
            $action->botId,
            $selection,
            $draftPublicId,
        );
        $locale = $this->locale($action->userId);
        $username = $target->maskedUsername
            ?? $this->translation('telegram.navigation.admin.customer_search.username_unavailable', $locale);
        $header = $this->translation('telegram.navigation.admin.customer_search.message_confirmation', $locale, [
            'telegram_id' => $target->telegramUserId,
            'account_id' => $target->accountPublicId,
            'username' => $username,
        ]);
        if (mb_strlen($header) > self::MESSAGE_CONFIRMATION_HEADER_MAX_LENGTH) {
            throw new RuntimeException('Telegram administrator direct-message confirmation localization exceeds its reserved header budget.');
        }
        $confirmation = $header."\n\n".$draft->text;
        if (mb_strlen($confirmation) > self::TELEGRAM_TEXT_MAX_LENGTH) {
            throw new RuntimeException('Telegram administrator direct-message confirmation exceeds the single-presentation limit.');
        }

        // The bounded text and localization budgets keep target context + exact
        // authored content in one confidential presentation before any effect.
        $this->queue(
            $action,
            $confirmation,
            'message-confirm',
            $this->messageConfirmationKeyboard($action, $sessionVersion, $locale, $draftPublicId),
        );
    }

    private function previewKeyboard(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $locale,
    ): TelegramInlineKeyboardSnapshot {
        $rows = [];
        if ($this->directMessages->availableFor($action->userId)) {
            $message = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_MESSAGE,
                [],
                'tg-admin-customer-message-entry:'.hash('sha256', $action->requestKey),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.admin.customer_search.message_button', $locale),
                $message->publicId,
            )];
        }

        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-admin-customer-back:'.hash('sha256', $action->requestKey.':preview'),
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )];

        return new TelegramInlineKeyboardSnapshot($rows);
    }

    private function messageConfirmationKeyboard(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $locale,
        string $draftPublicId,
    ): TelegramInlineKeyboardSnapshot {
        $confirm = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_MESSAGE_CONFIRM,
            [],
            'tg-admin-customer-message-confirm-button:'.hash('sha256', $draftPublicId),
        );
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-admin-customer-back:'.hash('sha256', $action->requestKey.':message-confirm'),
        );

        return new TelegramInlineKeyboardSnapshot([
            [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.admin.customer_search.message_confirm_button', $locale),
                $confirm->publicId,
            )],
            [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            )],
        ]);
    }

    private function backKeyboard(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $locale,
        string $surface,
    ): TelegramInlineKeyboardSnapshot {
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-admin-customer-back:'.hash('sha256', $action->requestKey.':'.$surface),
        );

        return new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            ),
        ]]);
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
            'tg-admin-customer-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-admin-customer:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 40),
            $keyboard,
        );
    }

    private function actionForSession(
        TelegramInteractionAction $action,
        TelegramInteractionSessionReceipt $session,
        string $suffix,
    ): TelegramInteractionAction {
        return new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':'.$suffix,
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
        );
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                TelegramNavigationEntryGateway::STATE,
                [],
                'tg-admin-customer-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);

        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':admin-customer-home',
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

    /** @param array<string,mixed> $payload */
    private function selectionFromPayload(array $payload): string
    {
        if (array_keys($payload) !== ['selection']
            || ! is_string($payload['selection'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $payload['selection']) !== 1) {
            throw new RuntimeException('Telegram administrator customer preview state is invalid.');
        }

        return $payload['selection'];
    }

    /** @param array<string,mixed> $payload
     * @return array{0:string,1:string}
     */
    private function messageStateFromPayload(array $payload): array
    {
        if (array_keys($payload) !== ['draft', 'selection']
            || ! is_string($payload['draft'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $payload['draft']) !== 1
            || ! is_string($payload['selection'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $payload['selection']) !== 1) {
            throw new RuntimeException('Telegram administrator direct-message confirmation state is invalid.');
        }

        return [$payload['selection'], $payload['draft']];
    }

    /** @param array<string,mixed> $payload
     * @return array{0:string,1:string}
     */
    private function submittingStateFromPayload(array $payload): array
    {
        if (array_keys($payload) !== ['cancel_locked', 'draft', 'expiry_locked', 'selection']
            || ($payload['cancel_locked'] ?? null) !== true
            || ($payload['expiry_locked'] ?? null) !== true
            || ! is_string($payload['draft'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $payload['draft']) !== 1
            || ! is_string($payload['selection'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $payload['selection']) !== 1) {
            throw new RuntimeException('Telegram administrator direct-message submitting state is invalid.');
        }

        return [$payload['selection'], $payload['draft']];
    }

    private function locale(int $userId): string
    {
        $locale = $this->database->connection()->table('users')->where('id', $userId)->value('locale');

        return $locale === 'en' ? 'en' : 'fa';
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === '' || $value === '['.$key.']') {
            throw new RuntimeException('Telegram administrator customer translation is unavailable.');
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram administrator customer translation has an unresolved placeholder.');
        }

        return $value;
    }

    private function assertActor(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new RuntimeException('Telegram administrator customer session actor binding is invalid.');
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

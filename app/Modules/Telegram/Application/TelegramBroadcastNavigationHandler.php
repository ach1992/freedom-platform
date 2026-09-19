<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Domain\TelegramBroadcastCampaignState;
use App\Modules\Telegram\Domain\TelegramBroadcastLifecycleAction;
use App\Modules\Telegram\Domain\TelegramBroadcastMessageMode;
use App\Modules\Telegram\Domain\TelegramBroadcastSourceKind;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Administrator Telegram journey for COM-002/COM-003.
 *
 * Session payloads contain only durable public IDs and bounded enum-like state.
 * Broadcast content/audience/provider evidence remains in the dedicated durable
 * broadcast authority rather than being copied into generic interaction state.
 */
final readonly class TelegramBroadcastNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.admin.broadcast';

    private const STATE_HOME = 'admin_broadcast_home';

    private const STATE_CONTENT_MODE = 'admin_broadcast_content_mode';

    private const STATE_TEXT_INPUT = 'admin_broadcast_text_input';

    private const STATE_SOURCE_KIND = 'admin_broadcast_source_kind';

    private const STATE_SOURCE_WAIT = 'admin_broadcast_source_wait';

    private const STATE_AUDIENCE = 'admin_broadcast_audience';

    private const STATE_AUDIENCE_INPUT = 'admin_broadcast_audience_input';

    private const STATE_REVIEW = 'admin_broadcast_review';

    private const STATE_BUTTONS_INPUT = 'admin_broadcast_buttons_input';

    private const STATE_SCHEDULE_INPUT = 'admin_broadcast_schedule_input';

    private const STATE_MANAGE = 'admin_broadcast_manage';

    private const STATE_EDIT_INPUT = 'admin_broadcast_edit_input';

    private const STATE_LIFECYCLE_BUTTONS_INPUT = 'admin_broadcast_lifecycle_buttons_input';

    private const STATE_LIFECYCLE_REVIEW = 'admin_broadcast_lifecycle_review';

    private const ACTION_NEW = 'navigation.admin.broadcast.new';

    private const ACTION_MANAGE = 'navigation.admin.broadcast.manage';

    private const ACTION_CONTENT_TEXT = 'navigation.admin.broadcast.content.text';

    private const ACTION_CONTENT_COPY = 'navigation.admin.broadcast.content.copy';

    private const ACTION_CONTENT_FORWARD = 'navigation.admin.broadcast.content.forward';

    private const ACTION_SOURCE_KIND = 'navigation.admin.broadcast.source_kind';

    private const ACTION_AUDIENCE = 'navigation.admin.broadcast.audience';

    private const ACTION_AUDIENCE_ALL = 'navigation.admin.broadcast.audience.all';

    private const ACTION_AUDIENCE_CUSTOM = 'navigation.admin.broadcast.audience.custom';

    private const ACTION_BUTTONS = 'navigation.admin.broadcast.buttons';

    private const ACTION_OWNER_TEST = 'navigation.admin.broadcast.owner_test';

    private const ACTION_OWNER_REFRESH = 'navigation.admin.broadcast.owner_refresh';

    private const ACTION_START = 'navigation.admin.broadcast.start';

    private const ACTION_SCHEDULE = 'navigation.admin.broadcast.schedule';

    private const ACTION_REFRESH = 'navigation.admin.broadcast.refresh';

    private const ACTION_PAUSE = 'navigation.admin.broadcast.pause';

    private const ACTION_RESUME = 'navigation.admin.broadcast.resume';

    private const ACTION_CANCEL = 'navigation.admin.broadcast.cancel';

    private const ACTION_RETRY = 'navigation.admin.broadcast.retry';

    private const ACTION_EDIT = 'navigation.admin.broadcast.edit';

    private const ACTION_LIFECYCLE_BUTTONS = 'navigation.admin.broadcast.lifecycle.buttons';

    private const ACTION_PIN = 'navigation.admin.broadcast.pin';

    private const ACTION_UNPIN = 'navigation.admin.broadcast.unpin';

    private const ACTION_DELETE = 'navigation.admin.broadcast.delete';

    private const ACTION_APPLY_LIFECYCLE = 'navigation.admin.broadcast.lifecycle.apply';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramBroadcastCampaignService $campaigns,
        private TelegramBroadcastOwnerTestService $ownerTests,
        private TelegramBroadcastRetryService $retries,
        private TelegramBroadcastLifecycleService $lifecycle,
        private TelegramBroadcastAudienceParser $audienceParser,
        private TelegramBroadcastButtonParser $buttonParser,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
        private TelegramDeliveryRuntime $runtime,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === 'admin_control'
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_ENTRY)
            || in_array($action->sessionState, [
                self::STATE_HOME,
                self::STATE_CONTENT_MODE,
                self::STATE_TEXT_INPUT,
                self::STATE_SOURCE_KIND,
                self::STATE_SOURCE_WAIT,
                self::STATE_AUDIENCE,
                self::STATE_AUDIENCE_INPUT,
                self::STATE_REVIEW,
                self::STATE_BUTTONS_INPUT,
                self::STATE_SCHEDULE_INPUT,
                self::STATE_MANAGE,
                self::STATE_EDIT_INPUT,
                self::STATE_LIFECYCLE_BUTTONS_INPUT,
                self::STATE_LIFECYCLE_REVIEW,
            ], true);
    }

    /** @requirement COM-002 COM-003 ACL-001 ACL-002 SEC-002 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'admin_control') {
            if ($action->kind !== TelegramInteractionActionKind::Callback
                || $action->callbackAction !== self::ACTION_ENTRY
                || $action->callbackPayload !== []
            ) {
                throw new RuntimeException('Telegram broadcast administration entry is invalid.');
            }

            $this->showHome($action);

            return;
        }

        match ($action->sessionState) {
            self::STATE_HOME => $this->handleHome($action),
            self::STATE_CONTENT_MODE => $this->handleContentMode($action),
            self::STATE_TEXT_INPUT => $this->handleTextInput($action),
            self::STATE_SOURCE_KIND => $this->handleSourceKind($action),
            self::STATE_SOURCE_WAIT => $this->handleSourceWait($action),
            self::STATE_AUDIENCE => $this->handleAudience($action),
            self::STATE_AUDIENCE_INPUT => $this->handleAudienceInput($action),
            self::STATE_REVIEW => $this->handleReview($action),
            self::STATE_BUTTONS_INPUT => $this->handleButtonsInput($action),
            self::STATE_SCHEDULE_INPUT => $this->handleScheduleInput($action),
            self::STATE_MANAGE => $this->handleManage($action),
            self::STATE_EDIT_INPUT => $this->handleEditInput($action),
            self::STATE_LIFECYCLE_BUTTONS_INPUT => $this->handleLifecycleButtonsInput($action),
            self::STATE_LIFECYCLE_REVIEW => $this->handleLifecycleReview($action),
            default => throw new RuntimeException('Telegram broadcast navigation state is unsupported.'),
        };
    }

    /** @requirement COM-002 ACL-001 ACL-002 SEC-002 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function handleSourceMessage(TelegramSourceMessageInteraction $interaction): bool
    {
        if ($interaction->flow !== TelegramNavigationEntryGateway::FLOW
            || $interaction->sessionState !== self::STATE_SOURCE_WAIT
        ) {
            return false;
        }

        [$mode, $sourceKind] = $this->sourcePayload($interaction->sessionPayload);
        $message = $mode === TelegramBroadcastMessageMode::Copy
            ? TelegramBroadcastMessageDefinition::copy(
                $interaction->sourceChatId,
                $interaction->sourceMessageId,
                $sourceKind,
            )
            : TelegramBroadcastMessageDefinition::forward(
                $interaction->sourceChatId,
                $interaction->sourceMessageId,
                $sourceKind,
            );

        $action = $this->actionForSourceMessage($interaction);
        try {
            $campaign = $this->campaigns->createDraft(
                $interaction->userId,
                $message,
                new TelegramBroadcastAudienceDefinition,
                $interaction->requestKey,
            );
            $session = $this->sessions->transition(
                $interaction->sessionPublicId,
                $interaction->sessionVersion,
                self::STATE_AUDIENCE,
                ['campaign' => $campaign->publicId],
                'tg-broadcast-audience:'.hash('sha256', $interaction->requestKey.':'.$campaign->publicId),
            );
        } catch (AuthorizationException|DomainException) {
            $this->renderSourceWait(
                $action,
                $interaction->sessionVersion,
                $this->locale($interaction->userId),
                'invalid',
            );

            return true;
        }

        $this->assertActor($action, $session->userId);
        $this->renderAudience(
            $this->actionForSession($action, $session, 'source-created'),
            $session->version,
            $campaign->publicId,
            $this->locale($interaction->userId),
            'saved',
        );

        return true;
    }

    private function handleHome(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->navigation->showAdminControl($action);

                return;
            }
            if ($action->callbackAction === self::ACTION_NEW && $action->callbackPayload === []) {
                $this->showContentMode($action);

                return;
            }
            if ($action->callbackAction === self::ACTION_MANAGE) {
                $campaign = $this->campaignFromCallback($action->callbackPayload);
                $this->showManage($action, $campaign);

                return;
            }

            throw new RuntimeException('Telegram broadcast home callback is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->navigation->showAdminControl($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleContentMode(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showHome($action);

                return;
            }
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram broadcast content-mode callback payload is unsupported.');
            }

            if ($action->callbackAction === self::ACTION_CONTENT_TEXT) {
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_TEXT_INPUT,
                    [],
                    'tg-broadcast-text-input:'.hash('sha256', $action->requestKey),
                );
                $this->assertActor($action, $session->userId);
                $this->renderTextInput($this->actionForSession($action, $session, 'text-input'), $session->version);

                return;
            }

            $mode = match ($action->callbackAction) {
                self::ACTION_CONTENT_COPY => TelegramBroadcastMessageMode::Copy,
                self::ACTION_CONTENT_FORWARD => TelegramBroadcastMessageMode::Forward,
                default => throw new RuntimeException('Telegram broadcast content-mode callback is unsupported.'),
            };
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_SOURCE_KIND,
                ['mode' => $mode->value],
                'tg-broadcast-source-kind:'.hash('sha256', $action->requestKey.':'.$mode->value),
            );
            $this->assertActor($action, $session->userId);
            $this->renderSourceKind(
                $this->actionForSession($action, $session, 'source-kind'),
                $session->version,
                $mode,
            );

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showHome($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleTextInput(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_BACK
            && $action->callbackPayload === []
        ) {
            $this->showContentMode($action);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showContentMode($action);

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
            $campaign = $this->campaigns->createDraft(
                $action->userId,
                TelegramBroadcastMessageDefinition::newText($action->messageText),
                new TelegramBroadcastAudienceDefinition,
                $action->requestKey,
            );
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_AUDIENCE,
                ['campaign' => $campaign->publicId],
                'tg-broadcast-audience:'.hash('sha256', $action->requestKey.':'.$campaign->publicId),
            );
        } catch (AuthorizationException|DomainException) {
            $this->renderTextInput($action, $action->sessionVersion, 'invalid');

            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderAudience(
            $this->actionForSession($action, $session, 'text-created'),
            $session->version,
            $campaign->publicId,
            $this->locale($action->userId),
            'saved',
        );
    }

    private function handleSourceKind(TelegramInteractionAction $action): void
    {
        $mode = $this->sourceModePayload($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showContentMode($action);

                return;
            }
            if ($action->callbackAction !== self::ACTION_SOURCE_KIND) {
                throw new RuntimeException('Telegram broadcast source-kind callback is unsupported.');
            }
            $sourceKind = $this->sourceKindFromCallback($action->callbackPayload);
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_SOURCE_WAIT,
                ['mode' => $mode->value, 'source_kind' => $sourceKind->value],
                'tg-broadcast-source-wait:'.hash(
                    'sha256',
                    $action->requestKey.':'.$mode->value.':'.$sourceKind->value,
                ),
            );
            $this->assertActor($action, $session->userId);
            $this->renderSourceWait(
                $this->actionForSession($action, $session, 'source-wait'),
                $session->version,
                $this->locale($action->userId),
            );

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showContentMode($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleSourceWait(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_BACK
            && $action->callbackPayload === []
        ) {
            $this->showContentMode($action);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showContentMode($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }

        $this->renderSourceWait(
            $action,
            $action->sessionVersion,
            $this->locale($action->userId),
            'invalid',
        );
    }

    private function handleAudience(TelegramInteractionAction $action): void
    {
        $campaignId = $this->campaignPayload($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showHome($action);

                return;
            }
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram broadcast audience callback payload is unsupported.');
            }
            if ($action->callbackAction === self::ACTION_AUDIENCE_CUSTOM) {
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_AUDIENCE_INPUT,
                    ['campaign' => $campaignId],
                    'tg-broadcast-audience-input:'.hash('sha256', $action->requestKey.':'.$campaignId),
                );
                $this->assertActor($action, $session->userId);
                $this->renderAudienceInput(
                    $this->actionForSession($action, $session, 'audience-input'),
                    $session->version,
                    $this->locale($action->userId),
                );

                return;
            }
            if ($action->callbackAction === self::ACTION_AUDIENCE_ALL) {
                try {
                    $current = $this->campaigns->current($action->userId, $campaignId);
                    $definition = new TelegramBroadcastAudienceDefinition;
                    if ($current->audienceVersion > 1) {
                        $current = $this->campaigns->replaceDraftAudience(
                            $action->userId,
                            $campaignId,
                            $current->stateVersion,
                            $definition,
                        );
                    }
                    $this->campaigns->estimateAudience(
                        $action->userId,
                        $campaignId,
                        $current->audienceVersion,
                    );
                    $session = $this->sessions->transition(
                        $action->sessionPublicId,
                        $action->sessionVersion,
                        self::STATE_REVIEW,
                        ['campaign' => $campaignId],
                        'tg-broadcast-review:'.hash('sha256', $action->requestKey.':'.$campaignId),
                    );
                } catch (AuthorizationException|DomainException) {
                    $this->renderAudience($action, $action->sessionVersion, $campaignId, $this->locale($action->userId), 'invalid');

                    return;
                }
                $this->assertActor($action, $session->userId);
                $this->renderReview(
                    $this->actionForSession($action, $session, 'audience-all'),
                    $session->version,
                    $campaignId,
                    'saved',
                );

                return;
            }

            throw new RuntimeException('Telegram broadcast audience callback is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showHome($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleAudienceInput(TelegramInteractionAction $action): void
    {
        $campaignId = $this->campaignPayload($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_BACK
            && $action->callbackPayload === []
        ) {
            $this->showAudience($action, $campaignId);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showAudience($action, $campaignId);

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
            $definition = $this->audienceParser->parse($action->messageText);
            $current = $this->campaigns->current($action->userId, $campaignId);
            $updated = $this->campaigns->replaceDraftAudience(
                $action->userId,
                $campaignId,
                $current->stateVersion,
                $definition,
            );
            $this->campaigns->estimateAudience(
                $action->userId,
                $campaignId,
                $updated->audienceVersion,
            );
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_REVIEW,
                ['campaign' => $campaignId],
                'tg-broadcast-review:'.hash('sha256', $action->requestKey.':'.$campaignId),
            );
        } catch (AuthorizationException|DomainException) {
            $this->renderAudienceInput($action, $action->sessionVersion, $this->locale($action->userId), 'invalid');

            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderReview(
            $this->actionForSession($action, $session, 'audience-custom'),
            $session->version,
            $campaignId,
            'saved',
        );
    }

    private function handleReview(TelegramInteractionAction $action): void
    {
        $campaignId = $this->campaignPayload($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showAudience($action, $campaignId);

                return;
            }
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram broadcast review callback payload is unsupported.');
            }

            if ($action->callbackAction === self::ACTION_AUDIENCE) {
                $this->showAudience($action, $campaignId);

                return;
            }
            if ($action->callbackAction === self::ACTION_BUTTONS) {
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_BUTTONS_INPUT,
                    ['campaign' => $campaignId],
                    'tg-broadcast-buttons-input:'.hash('sha256', $action->requestKey.':'.$campaignId),
                );
                $this->assertActor($action, $session->userId);
                $this->renderButtonsInput(
                    $this->actionForSession($action, $session, 'buttons-input'),
                    $session->version,
                    $this->locale($action->userId),
                );

                return;
            }
            if ($action->callbackAction === self::ACTION_OWNER_TEST) {
                $this->sendOwnerTest($action, $campaignId, false);

                return;
            }
            if ($action->callbackAction === self::ACTION_OWNER_REFRESH) {
                $this->refreshOwnerTest($action, $campaignId, false);

                return;
            }
            if ($action->callbackAction === self::ACTION_START) {
                try {
                    $current = $this->campaigns->current($action->userId, $campaignId);
                    $this->campaigns->startNow($action->userId, $campaignId, $current->stateVersion);
                    $this->showManage($action, $campaignId, 'started');
                } catch (AuthorizationException|DomainException) {
                    $this->renderReview($action, $action->sessionVersion, $campaignId, 'owner_test_required');
                }

                return;
            }
            if ($action->callbackAction === self::ACTION_SCHEDULE) {
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_SCHEDULE_INPUT,
                    ['campaign' => $campaignId],
                    'tg-broadcast-schedule-input:'.hash('sha256', $action->requestKey.':'.$campaignId),
                );
                $this->assertActor($action, $session->userId);
                $this->renderScheduleInput(
                    $this->actionForSession($action, $session, 'schedule-input'),
                    $session->version,
                    $this->locale($action->userId),
                );

                return;
            }
            if ($action->callbackAction === self::ACTION_CANCEL) {
                try {
                    $current = $this->campaigns->current($action->userId, $campaignId);
                    $this->campaigns->cancel($action->userId, $campaignId, $current->stateVersion);
                    $this->showManage($action, $campaignId, 'cancelled');
                } catch (AuthorizationException|DomainException) {
                    $this->renderReview($action, $action->sessionVersion, $campaignId, 'invalid');
                }

                return;
            }

            throw new RuntimeException('Telegram broadcast review callback is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showAudience($action, $campaignId);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleButtonsInput(TelegramInteractionAction $action): void
    {
        $campaignId = $this->campaignPayload($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_BACK
            && $action->callbackPayload === []
        ) {
            $this->showReview($action, $campaignId);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showReview($action, $campaignId);

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
            $keyboard = $this->buttonParser->parse($action->messageText);
            $current = $this->campaigns->current($action->userId, $campaignId);
            $message = $this->campaigns->currentMessage($action->userId, $campaignId);
            $updated = $this->campaigns->replaceDraftMessage(
                $action->userId,
                $campaignId,
                $current->stateVersion,
                $message->withInlineKeyboard($keyboard),
            );
            $this->campaigns->estimateAudience(
                $action->userId,
                $campaignId,
                $updated->audienceVersion,
            );
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_REVIEW,
                ['campaign' => $campaignId],
                'tg-broadcast-review-buttons:'.hash('sha256', $action->requestKey.':'.$campaignId),
            );
        } catch (AuthorizationException|DomainException) {
            $this->renderButtonsInput($action, $action->sessionVersion, $this->locale($action->userId), 'invalid');

            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderReview(
            $this->actionForSession($action, $session, 'buttons-saved'),
            $session->version,
            $campaignId,
            'saved',
        );
    }

    private function handleScheduleInput(TelegramInteractionAction $action): void
    {
        $campaignId = $this->campaignPayload($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_BACK
            && $action->callbackPayload === []
        ) {
            $this->showReview($action, $campaignId);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showReview($action, $campaignId);

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
            $scheduledAt = $this->scheduleTime($action->messageText);
            $current = $this->campaigns->current($action->userId, $campaignId);
            $this->campaigns->schedule(
                $action->userId,
                $campaignId,
                $current->stateVersion,
                $scheduledAt,
            );
            $this->showManage($action, $campaignId, 'scheduled');
        } catch (AuthorizationException|DomainException) {
            $this->renderScheduleInput($action, $action->sessionVersion, $this->locale($action->userId), 'invalid');
        }
    }

    private function handleManage(TelegramInteractionAction $action): void
    {
        [$campaignId, $groupPublicId] = $this->managePayload($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showHome($action);

                return;
            }
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram broadcast manage callback payload is unsupported.');
            }

            if ($action->callbackAction === self::ACTION_REFRESH) {
                $this->renderManage($action, $action->sessionVersion, $campaignId, $groupPublicId);

                return;
            }
            if ($action->callbackAction === self::ACTION_AUDIENCE) {
                $this->showReview($action, $campaignId);

                return;
            }

            try {
                $progress = $this->campaigns->progress($action->userId, $campaignId);
                if ($action->callbackAction === self::ACTION_PAUSE) {
                    $this->campaigns->pause($action->userId, $campaignId, $progress->stateVersion);
                    $this->renderManage($action, $action->sessionVersion, $campaignId, null, 'paused');

                    return;
                }
                if ($action->callbackAction === self::ACTION_RESUME) {
                    $this->campaigns->resume($action->userId, $campaignId, $progress->stateVersion);
                    $this->renderManage($action, $action->sessionVersion, $campaignId, null, 'resumed');

                    return;
                }
                if ($action->callbackAction === self::ACTION_CANCEL) {
                    $this->campaigns->cancel($action->userId, $campaignId, $progress->stateVersion);
                    $this->renderManage($action, $action->sessionVersion, $campaignId, null, 'cancelled');

                    return;
                }
                if ($action->callbackAction === self::ACTION_RETRY) {
                    $this->retries->retryFailed(
                        $action->userId,
                        $campaignId,
                        $progress->stateVersion,
                        $action->requestKey,
                    );
                    $this->renderManage($action, $action->sessionVersion, $campaignId, null, 'resumed');

                    return;
                }
                if ($action->callbackAction === self::ACTION_EDIT) {
                    $session = $this->sessions->transition(
                        $action->sessionPublicId,
                        $action->sessionVersion,
                        self::STATE_EDIT_INPUT,
                        ['campaign' => $campaignId],
                        'tg-broadcast-edit-input:'.hash('sha256', $action->requestKey.':'.$campaignId),
                    );
                    $this->assertActor($action, $session->userId);
                    $this->renderEditInput(
                        $this->actionForSession($action, $session, 'edit-input'),
                        $session->version,
                        $campaignId,
                    );

                    return;
                }
                if ($action->callbackAction === self::ACTION_LIFECYCLE_BUTTONS) {
                    $session = $this->sessions->transition(
                        $action->sessionPublicId,
                        $action->sessionVersion,
                        self::STATE_LIFECYCLE_BUTTONS_INPUT,
                        ['campaign' => $campaignId],
                        'tg-broadcast-lifecycle-buttons:'.hash('sha256', $action->requestKey.':'.$campaignId),
                    );
                    $this->assertActor($action, $session->userId);
                    $this->renderButtonsInput(
                        $this->actionForSession($action, $session, 'lifecycle-buttons-input'),
                        $session->version,
                        $this->locale($action->userId),
                    );

                    return;
                }

                $lifecycleAction = match ($action->callbackAction) {
                    self::ACTION_PIN => TelegramBroadcastLifecycleAction::Pin,
                    self::ACTION_UNPIN => TelegramBroadcastLifecycleAction::Unpin,
                    self::ACTION_DELETE => TelegramBroadcastLifecycleAction::Delete,
                    default => null,
                };
                if ($lifecycleAction !== null) {
                    $batch = $this->lifecycle->queueAction(
                        $action->userId,
                        $campaignId,
                        $progress->stateVersion,
                        $lifecycleAction,
                        $action->requestKey,
                    );
                    $this->transitionManageWithGroup($action, $campaignId, $batch->groupPublicId);

                    return;
                }
            } catch (AuthorizationException|DomainException) {
                $this->renderManage($action, $action->sessionVersion, $campaignId, $groupPublicId, 'invalid');

                return;
            }

            throw new RuntimeException('Telegram broadcast manage callback is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showHome($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleEditInput(TelegramInteractionAction $action): void
    {
        $campaignId = $this->campaignPayload($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_BACK
            && $action->callbackPayload === []
        ) {
            $this->showManage($action, $campaignId);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showManage($action, $campaignId);

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
            $current = $this->campaigns->current($action->userId, $campaignId);
            $message = $this->campaigns->currentMessage($action->userId, $campaignId);
            $next = match ($message->mode) {
                TelegramBroadcastMessageMode::NewText => $message->withText($action->messageText),
                TelegramBroadcastMessageMode::Copy => $message->sourceKind === TelegramBroadcastSourceKind::Text
                    ? TelegramBroadcastMessageDefinition::newText($action->messageText, $message->inlineKeyboard)
                    : $message->withCaptionOverride(
                        strcasecmp(trim($action->messageText), 'none') === 0 ? '' : $action->messageText,
                    ),
                TelegramBroadcastMessageMode::Forward => throw new DomainException(
                    'Forwarded broadcast content cannot be edited.',
                ),
            };
            $this->lifecycle->reviseContent(
                $action->userId,
                $campaignId,
                $current->stateVersion,
                $next,
            );
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_LIFECYCLE_REVIEW,
                ['action' => TelegramBroadcastLifecycleAction::Edit->value, 'campaign' => $campaignId],
                'tg-broadcast-lifecycle-review:'.hash('sha256', $action->requestKey.':edit:'.$campaignId),
            );
        } catch (AuthorizationException|DomainException) {
            $this->renderEditInput($action, $action->sessionVersion, $campaignId, 'invalid');

            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderLifecycleReview(
            $this->actionForSession($action, $session, 'edit-revised'),
            $session->version,
            $campaignId,
            TelegramBroadcastLifecycleAction::Edit,
            'owner_test_required',
        );
    }

    private function handleLifecycleButtonsInput(TelegramInteractionAction $action): void
    {
        $campaignId = $this->campaignPayload($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_BACK
            && $action->callbackPayload === []
        ) {
            $this->showManage($action, $campaignId);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showManage($action, $campaignId);

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
            $keyboard = $this->buttonParser->parse($action->messageText);
            $current = $this->campaigns->current($action->userId, $campaignId);
            $message = $this->campaigns->currentMessage($action->userId, $campaignId);
            $this->lifecycle->reviseContent(
                $action->userId,
                $campaignId,
                $current->stateVersion,
                $message->withInlineKeyboard($keyboard),
            );
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_LIFECYCLE_REVIEW,
                ['action' => TelegramBroadcastLifecycleAction::Buttons->value, 'campaign' => $campaignId],
                'tg-broadcast-lifecycle-review:'.hash('sha256', $action->requestKey.':buttons:'.$campaignId),
            );
        } catch (AuthorizationException|DomainException) {
            $this->renderButtonsInput($action, $action->sessionVersion, $this->locale($action->userId), 'invalid');

            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderLifecycleReview(
            $this->actionForSession($action, $session, 'buttons-revised'),
            $session->version,
            $campaignId,
            TelegramBroadcastLifecycleAction::Buttons,
            'owner_test_required',
        );
    }

    private function handleLifecycleReview(TelegramInteractionAction $action): void
    {
        [$campaignId, $lifecycleAction] = $this->lifecycleReviewPayload($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showManage($action, $campaignId);

                return;
            }
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram broadcast lifecycle-review callback payload is unsupported.');
            }
            if ($action->callbackAction === self::ACTION_OWNER_TEST) {
                $this->sendOwnerTest($action, $campaignId, true, $lifecycleAction);

                return;
            }
            if ($action->callbackAction === self::ACTION_OWNER_REFRESH) {
                $this->refreshOwnerTest($action, $campaignId, true, $lifecycleAction);

                return;
            }
            if ($action->callbackAction === self::ACTION_APPLY_LIFECYCLE) {
                try {
                    $current = $this->campaigns->current($action->userId, $campaignId);
                    $batch = $this->lifecycle->queueAction(
                        $action->userId,
                        $campaignId,
                        $current->stateVersion,
                        $lifecycleAction,
                        $action->requestKey,
                    );
                    $this->transitionManageWithGroup($action, $campaignId, $batch->groupPublicId);
                } catch (AuthorizationException|DomainException) {
                    $this->renderLifecycleReview(
                        $action,
                        $action->sessionVersion,
                        $campaignId,
                        $lifecycleAction,
                        'owner_test_required',
                    );
                }

                return;
            }

            throw new RuntimeException('Telegram broadcast lifecycle-review callback is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showManage($action, $campaignId);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function showHome(TelegramInteractionAction $action): void
    {
        if (! $this->campaigns->availableFor($action->userId)) {
            $this->navigation->showAdminControl($action);

            return;
        }

        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_HOME,
            [],
            'tg-broadcast-home:'.hash('sha256', $action->requestKey),
        );
        $this->assertActor($action, $session->userId);
        $this->renderHome(
            $this->actionForSession($action, $session, 'home'),
            $session->version,
        );
    }

    private function showContentMode(TelegramInteractionAction $action): void
    {
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_CONTENT_MODE,
            [],
            'tg-broadcast-content-mode:'.hash('sha256', $action->requestKey),
        );
        $this->assertActor($action, $session->userId);
        $this->renderContentMode(
            $this->actionForSession($action, $session, 'content-mode'),
            $session->version,
        );
    }

    private function showAudience(TelegramInteractionAction $action, string $campaignId): void
    {
        try {
            $this->campaigns->current($action->userId, $campaignId);
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_AUDIENCE,
                ['campaign' => $campaignId],
                'tg-broadcast-audience-show:'.hash('sha256', $action->requestKey.':'.$campaignId),
            );
        } catch (AuthorizationException|DomainException) {
            $this->showHome($action);

            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderAudience(
            $this->actionForSession($action, $session, 'audience'),
            $session->version,
            $campaignId,
            $this->locale($action->userId),
        );
    }

    private function showReview(TelegramInteractionAction $action, string $campaignId): void
    {
        try {
            $current = $this->campaigns->current($action->userId, $campaignId);
            if ($current->state !== TelegramBroadcastCampaignState::Draft) {
                $this->showManage($action, $campaignId);

                return;
            }
            $this->campaigns->estimateAudience(
                $action->userId,
                $campaignId,
                $current->audienceVersion,
            );
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_REVIEW,
                ['campaign' => $campaignId],
                'tg-broadcast-review-show:'.hash('sha256', $action->requestKey.':'.$campaignId),
            );
        } catch (AuthorizationException|DomainException) {
            $this->showHome($action);

            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderReview(
            $this->actionForSession($action, $session, 'review'),
            $session->version,
            $campaignId,
        );
    }

    private function showManage(
        TelegramInteractionAction $action,
        string $campaignId,
        ?string $notice = null,
    ): void {
        try {
            $this->campaigns->progress($action->userId, $campaignId);
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_MANAGE,
                ['campaign' => $campaignId],
                'tg-broadcast-manage:'.hash('sha256', $action->requestKey.':'.$campaignId),
            );
        } catch (AuthorizationException|DomainException|RuntimeException) {
            $this->showHome($action);

            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderManage(
            $this->actionForSession($action, $session, 'manage'),
            $session->version,
            $campaignId,
            null,
            $notice,
        );
    }

    private function transitionManageWithGroup(
        TelegramInteractionAction $action,
        string $campaignId,
        string $groupPublicId,
    ): void {
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_MANAGE,
            ['campaign' => $campaignId, 'group' => $groupPublicId],
            'tg-broadcast-manage-group:'.hash(
                'sha256',
                $action->requestKey.':'.$campaignId.':'.$groupPublicId,
            ),
        );
        $this->assertActor($action, $session->userId);
        $this->renderManage(
            $this->actionForSession($action, $session, 'manage-group'),
            $session->version,
            $campaignId,
            $groupPublicId,
        );
    }

    private function sendOwnerTest(
        TelegramInteractionAction $action,
        string $campaignId,
        bool $lifecycle,
        ?TelegramBroadcastLifecycleAction $lifecycleAction = null,
    ): void {
        try {
            $current = $this->campaigns->current($action->userId, $campaignId);
            $test = $this->ownerTests->send(
                $action->userId,
                $campaignId,
                $current->stateVersion,
                $action->requestKey,
            );
            $notice = $test->state === 'succeeded' ? 'owner_test_succeeded' : 'owner_test_pending';
        } catch (AuthorizationException|DomainException) {
            $notice = 'owner_test_required';
        }

        if ($lifecycle && $lifecycleAction !== null) {
            $this->renderLifecycleReview(
                $action,
                $action->sessionVersion,
                $campaignId,
                $lifecycleAction,
                $notice,
            );

            return;
        }

        $this->renderReview($action, $action->sessionVersion, $campaignId, $notice);
    }

    private function refreshOwnerTest(
        TelegramInteractionAction $action,
        string $campaignId,
        bool $lifecycle,
        ?TelegramBroadcastLifecycleAction $lifecycleAction = null,
    ): void {
        try {
            $test = $this->ownerTests->reconcileCurrent($action->userId, $campaignId);
            $notice = $test?->state === 'succeeded' ? 'owner_test_succeeded' : 'owner_test_pending';
        } catch (AuthorizationException|DomainException) {
            $notice = 'owner_test_required';
        }

        if ($lifecycle && $lifecycleAction !== null) {
            $this->renderLifecycleReview(
                $action,
                $action->sessionVersion,
                $campaignId,
                $lifecycleAction,
                $notice,
            );

            return;
        }

        $this->renderReview($action, $action->sessionVersion, $campaignId, $notice);
    }

    private function renderHome(TelegramInteractionAction $action, int $sessionVersion): void
    {
        $locale = $this->locale($action->userId);
        $rows = [[
            $this->button(
                $action,
                $sessionVersion,
                self::ACTION_NEW,
                [],
                'new',
                $this->translation('telegram.broadcast.new_campaign', $locale),
            ),
        ]];

        /** @var Collection<int,object{public_id:string,state:string}> $campaigns */
        $campaigns = $this->database->connection()->table('broadcast_campaigns')
            ->where('bot_id', $this->runtime->botId())
            ->orderByDesc('id')
            ->limit(5)
            ->get(['public_id', 'state']);
        foreach ($campaigns as $campaign) {
            $state = TelegramBroadcastCampaignState::tryFrom($campaign->state);
            if ($state === null) {
                continue;
            }
            $rows[] = [$this->button(
                $action,
                $sessionVersion,
                self::ACTION_MANAGE,
                ['campaign' => $campaign->public_id],
                'manage-'.$campaign->public_id,
                substr($campaign->public_id, 0, 8).' · '.$this->stateLabel($state, $locale),
            )];
        }

        $rows[] = [$this->backButton($action, $sessionVersion, $locale, 'home')];
        $this->queue(
            $action,
            $this->translation('telegram.broadcast.title', $locale),
            'home',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderContentMode(TelegramInteractionAction $action, int $sessionVersion): void
    {
        $locale = $this->locale($action->userId);
        $rows = [
            [$this->button($action, $sessionVersion, self::ACTION_CONTENT_TEXT, [], 'content-text', $this->translation('telegram.broadcast.content_new_text', $locale))],
            [$this->button($action, $sessionVersion, self::ACTION_CONTENT_COPY, [], 'content-copy', $this->translation('telegram.broadcast.content_copy', $locale))],
            [$this->button($action, $sessionVersion, self::ACTION_CONTENT_FORWARD, [], 'content-forward', $this->translation('telegram.broadcast.content_forward', $locale))],
            [$this->backButton($action, $sessionVersion, $locale, 'content-mode')],
        ];
        $this->queue(
            $action,
            $this->translation('telegram.broadcast.content_mode', $locale),
            'content-mode',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderTextInput(
        TelegramInteractionAction $action,
        int $sessionVersion,
        ?string $notice = null,
    ): void {
        $locale = $this->locale($action->userId);
        $this->queue(
            $action,
            $this->withNotice($this->translation('telegram.broadcast.await_text', $locale), $locale, $notice),
            'text-input',
            new TelegramInlineKeyboardSnapshot([[$this->backButton($action, $sessionVersion, $locale, 'text-input')]]),
        );
    }

    private function renderSourceKind(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramBroadcastMessageMode $mode,
    ): void {
        $locale = $this->locale($action->userId);
        $rows = [];
        foreach (TelegramBroadcastSourceKind::cases() as $sourceKind) {
            $rows[] = [$this->button(
                $action,
                $sessionVersion,
                self::ACTION_SOURCE_KIND,
                ['source_kind' => $sourceKind->value],
                'source-kind-'.$sourceKind->value,
                $this->translation('telegram.broadcast.source_kinds.'.$sourceKind->value, $locale),
            )];
        }
        $rows[] = [$this->backButton($action, $sessionVersion, $locale, 'source-kind')];

        $this->queue(
            $action,
            $this->translation('telegram.broadcast.source_kind', $locale).' · '.
                $this->translation('telegram.broadcast.content_'.$mode->value, $locale),
            'source-kind',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderSourceWait(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $locale,
        ?string $notice = null,
    ): void {
        $this->queue(
            $action,
            $this->withNotice($this->translation('telegram.broadcast.await_source', $locale), $locale, $notice),
            'source-wait',
            new TelegramInlineKeyboardSnapshot([[$this->backButton($action, $sessionVersion, $locale, 'source-wait')]]),
        );
    }

    private function renderAudience(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $campaignId,
        string $locale,
        ?string $notice = null,
    ): void {
        $rows = [
            [$this->button($action, $sessionVersion, self::ACTION_AUDIENCE_ALL, [], 'audience-all', $this->translation('telegram.broadcast.audience_all', $locale))],
            [$this->button($action, $sessionVersion, self::ACTION_AUDIENCE_CUSTOM, [], 'audience-custom', $this->translation('telegram.broadcast.audience_custom', $locale))],
            [$this->backButton($action, $sessionVersion, $locale, 'audience')],
        ];
        $text = $this->translation('telegram.broadcast.audience', $locale)."\n".
            $this->translation('telegram.broadcast.campaign_id', $locale, ['id' => $campaignId]);
        $this->queue(
            $action,
            $this->withNotice($text, $locale, $notice),
            'audience',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderAudienceInput(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $locale,
        ?string $notice = null,
    ): void {
        $this->queue(
            $action,
            $this->withNotice($this->translation('telegram.broadcast.await_audience', $locale), $locale, $notice),
            'audience-input',
            new TelegramInlineKeyboardSnapshot([[$this->backButton($action, $sessionVersion, $locale, 'audience-input')]]),
        );
    }

    private function renderReview(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $campaignId,
        ?string $notice = null,
    ): void {
        $locale = $this->locale($action->userId);
        try {
            $current = $this->campaigns->current($action->userId, $campaignId);
            $message = $this->campaigns->currentMessage($action->userId, $campaignId);
        } catch (AuthorizationException|DomainException) {
            $this->showHome($action);

            return;
        }
        try {
            $test = $this->ownerTests->reconcileCurrent($action->userId, $campaignId);
        } catch (AuthorizationException|DomainException) {
            $test = null;
        }

        $preview = $this->messagePreview($message, $locale);
        $text = $this->translation('telegram.broadcast.review', $locale, [
            'count' => (string) $current->estimatedRecipientCount,
        ])."\n".
            $this->translation('telegram.broadcast.campaign_id', $locale, ['id' => $campaignId])."\n".
            $preview."\n".
            $this->translation('telegram.broadcast.owner_test_state', $locale, [
                'state' => $test->state ?? 'none',
            ]);

        $rows = [
            [$this->button($action, $sessionVersion, self::ACTION_AUDIENCE, [], 'review-audience', $this->translation('telegram.broadcast.audience', $locale))],
        ];
        if ($message->mode !== TelegramBroadcastMessageMode::Forward) {
            $rows[] = [$this->button($action, $sessionVersion, self::ACTION_BUTTONS, [], 'review-buttons', $this->translation('telegram.broadcast.buttons', $locale))];
        }
        $rows[] = [$this->button($action, $sessionVersion, self::ACTION_OWNER_TEST, [], 'review-owner-test', $this->translation('telegram.broadcast.owner_test', $locale))];
        $rows[] = [$this->button($action, $sessionVersion, self::ACTION_OWNER_REFRESH, [], 'review-owner-refresh', $this->translation('telegram.broadcast.owner_refresh', $locale))];
        $rows[] = [
            $this->button($action, $sessionVersion, self::ACTION_START, [], 'review-start', $this->translation('telegram.broadcast.start_now', $locale)),
            $this->button($action, $sessionVersion, self::ACTION_SCHEDULE, [], 'review-schedule', $this->translation('telegram.broadcast.schedule', $locale)),
        ];
        $rows[] = [$this->button($action, $sessionVersion, self::ACTION_CANCEL, [], 'review-cancel', $this->translation('telegram.broadcast.cancel', $locale))];
        $rows[] = [$this->backButton($action, $sessionVersion, $locale, 'review')];

        $this->queue(
            $action,
            $this->withNotice($text, $locale, $notice),
            'review',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderButtonsInput(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $locale,
        ?string $notice = null,
    ): void {
        $this->queue(
            $action,
            $this->withNotice($this->translation('telegram.broadcast.await_buttons', $locale), $locale, $notice),
            'buttons-input',
            new TelegramInlineKeyboardSnapshot([[$this->backButton($action, $sessionVersion, $locale, 'buttons-input')]]),
        );
    }

    private function renderScheduleInput(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $locale,
        ?string $notice = null,
    ): void {
        $this->queue(
            $action,
            $this->withNotice($this->translation('telegram.broadcast.await_schedule', $locale), $locale, $notice),
            'schedule-input',
            new TelegramInlineKeyboardSnapshot([[$this->backButton($action, $sessionVersion, $locale, 'schedule-input')]]),
        );
    }

    private function renderManage(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $campaignId,
        ?string $groupPublicId = null,
        ?string $notice = null,
    ): void {
        $locale = $this->locale($action->userId);
        try {
            $progress = $this->campaigns->progress($action->userId, $campaignId);
        } catch (AuthorizationException|DomainException|RuntimeException) {
            $this->showHome($action);

            return;
        }

        $text = $this->translation('telegram.broadcast.campaign_id', $locale, ['id' => $campaignId])."\n".
            $this->translation('telegram.broadcast.progress', $locale, [
                'state' => $this->stateLabel($progress->state, $locale),
                'total' => (string) $progress->recipientCount,
                'sent' => (string) $progress->sent,
                'queued' => (string) ($progress->queued + $progress->sending),
                'failed' => (string) ($progress->failedTransient + $progress->failedPermanent),
                'uncertain' => (string) $progress->uncertain,
            ]);

        if ($groupPublicId !== null) {
            try {
                $group = $this->lifecycle->progress($action->userId, $campaignId, $groupPublicId);
                $text .= "\n".$this->translation('telegram.broadcast.lifecycle_progress', $locale, [
                    'action' => $this->translation('telegram.broadcast.'.$group->action->value, $locale),
                    'done' => (string) $group->finishedCount(),
                    'total' => (string) $group->recipientCount,
                    'uncertain' => (string) $group->uncertain,
                ]);
            } catch (DomainException) {
                $groupPublicId = null;
            }
        }

        $rows = [];
        if ($progress->state === TelegramBroadcastCampaignState::Draft) {
            $rows[] = [$this->button($action, $sessionVersion, self::ACTION_AUDIENCE, [], 'manage-draft-review', $this->translation('telegram.broadcast.manage_campaign', $locale))];
        } elseif ($progress->state === TelegramBroadcastCampaignState::Active) {
            $rows[] = [$this->button($action, $sessionVersion, self::ACTION_PAUSE, [], 'manage-pause', $this->translation('telegram.broadcast.pause', $locale))];
            $rows[] = [$this->button($action, $sessionVersion, self::ACTION_CANCEL, [], 'manage-cancel', $this->translation('telegram.broadcast.cancel', $locale))];
        } elseif ($progress->state === TelegramBroadcastCampaignState::Paused) {
            $rows[] = [$this->button($action, $sessionVersion, self::ACTION_RESUME, [], 'manage-resume', $this->translation('telegram.broadcast.resume', $locale))];
            $rows[] = [$this->button($action, $sessionVersion, self::ACTION_CANCEL, [], 'manage-cancel', $this->translation('telegram.broadcast.cancel', $locale))];
        } elseif ($progress->state === TelegramBroadcastCampaignState::Scheduled) {
            $rows[] = [$this->button($action, $sessionVersion, self::ACTION_CANCEL, [], 'manage-cancel', $this->translation('telegram.broadcast.cancel', $locale))];
        } elseif ($progress->state === TelegramBroadcastCampaignState::Completed) {
            if (($progress->failedTransient + $progress->failedPermanent) > 0) {
                $rows[] = [$this->button($action, $sessionVersion, self::ACTION_RETRY, [], 'manage-retry', $this->translation('telegram.broadcast.retry_failed', $locale))];
            }
            $rows[] = [
                $this->button($action, $sessionVersion, self::ACTION_EDIT, [], 'manage-edit', $this->translation('telegram.broadcast.edit', $locale)),
                $this->button($action, $sessionVersion, self::ACTION_LIFECYCLE_BUTTONS, [], 'manage-buttons', $this->translation('telegram.broadcast.buttons', $locale)),
            ];
            $rows[] = [
                $this->button($action, $sessionVersion, self::ACTION_PIN, [], 'manage-pin', $this->translation('telegram.broadcast.pin', $locale)),
                $this->button($action, $sessionVersion, self::ACTION_UNPIN, [], 'manage-unpin', $this->translation('telegram.broadcast.unpin', $locale)),
            ];
            $rows[] = [$this->button($action, $sessionVersion, self::ACTION_DELETE, [], 'manage-delete', $this->translation('telegram.broadcast.delete', $locale))];
        }
        $rows[] = [$this->button($action, $sessionVersion, self::ACTION_REFRESH, [], 'manage-refresh', $this->translation('telegram.broadcast.refresh', $locale))];
        $rows[] = [$this->backButton($action, $sessionVersion, $locale, 'manage')];

        $this->queue(
            $action,
            $this->withNotice($text, $locale, $notice),
            'manage',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderEditInput(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $campaignId,
        ?string $notice = null,
    ): void {
        $locale = $this->locale($action->userId);
        try {
            $message = $this->campaigns->currentMessage($action->userId, $campaignId);
            $key = $message->mode === TelegramBroadcastMessageMode::Copy
                && $message->sourceKind?->supportsCaption() === true
                ? 'telegram.broadcast.await_caption_edit'
                : 'telegram.broadcast.await_text_edit';
        } catch (AuthorizationException|DomainException) {
            $this->showHome($action);

            return;
        }

        $this->queue(
            $action,
            $this->withNotice($this->translation($key, $locale), $locale, $notice),
            'edit-input',
            new TelegramInlineKeyboardSnapshot([[$this->backButton($action, $sessionVersion, $locale, 'edit-input')]]),
        );
    }

    private function renderLifecycleReview(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $campaignId,
        TelegramBroadcastLifecycleAction $lifecycleAction,
        ?string $notice = null,
    ): void {
        $locale = $this->locale($action->userId);
        try {
            $current = $this->campaigns->current($action->userId, $campaignId);
        } catch (AuthorizationException|DomainException) {
            $this->showHome($action);

            return;
        }
        try {
            $test = $this->ownerTests->reconcileCurrent($action->userId, $campaignId);
        } catch (AuthorizationException|DomainException) {
            $test = null;
        }

        $text = $this->translation('telegram.broadcast.lifecycle_review', $locale, [
            'action' => $this->translation('telegram.broadcast.'.$lifecycleAction->value, $locale),
            'version' => (string) $current->messageVersion,
        ])."\n".
            $this->translation('telegram.broadcast.owner_test_state', $locale, [
                'state' => $test->state ?? 'none',
            ]);

        $rows = [
            [$this->button($action, $sessionVersion, self::ACTION_OWNER_TEST, [], 'lifecycle-owner-test', $this->translation('telegram.broadcast.owner_test', $locale))],
            [$this->button($action, $sessionVersion, self::ACTION_OWNER_REFRESH, [], 'lifecycle-owner-refresh', $this->translation('telegram.broadcast.owner_refresh', $locale))],
            [$this->button($action, $sessionVersion, self::ACTION_APPLY_LIFECYCLE, [], 'lifecycle-apply', $this->translation('telegram.broadcast.apply', $locale))],
            [$this->backButton($action, $sessionVersion, $locale, 'lifecycle-review')],
        ];

        $this->queue(
            $action,
            $this->withNotice($text, $locale, $notice),
            'lifecycle-review',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function messagePreview(TelegramBroadcastMessageDefinition $message, string $locale): string
    {
        if ($message->mode === TelegramBroadcastMessageMode::NewText) {
            $text = $message->text ?? '';
            $text = mb_strlen($text) > 640 ? mb_substr($text, 0, 637).'...' : $text;

            return $this->translation('telegram.broadcast.preview_text', $locale, ['text' => $text]);
        }

        return $this->translation('telegram.broadcast.preview_source', $locale, [
            'mode' => $this->translation('telegram.broadcast.content_'.$message->mode->value, $locale),
            'kind' => $this->translation('telegram.broadcast.source_kinds.'.$message->sourceKind->value, $locale),
            'message_id' => (string) ($message->sourceMessageId ?? 0),
        ]);
    }

    /** @param  array<string,mixed>  $payload */
    private function button(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $callbackAction,
        array $payload,
        string $surface,
        string $label,
    ): TelegramInlineCallbackButton {
        $callback = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            $callbackAction,
            $payload,
            'tg-broadcast-callback:'.hash(
                'sha256',
                $action->requestKey.':'.$surface.':'.json_encode($payload, JSON_UNESCAPED_SLASHES),
            ),
        );

        return new TelegramInlineCallbackButton($label, $callback->publicId);
    }

    private function backButton(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $locale,
        string $surface,
    ): TelegramInlineCallbackButton {
        return $this->button(
            $action,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            $surface.'-back',
            $this->translation('telegram.broadcast.back', $locale),
        );
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
            'tg-broadcast-admin-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-broadcast-admin:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 40),
            $keyboard,
        );
    }

    /** @param  array<string,mixed>  $payload */
    private function campaignFromCallback(array $payload): string
    {
        return $this->campaignPayload($payload);
    }

    /** @param  array<string,mixed>  $payload */
    private function campaignPayload(array $payload): string
    {
        if (array_keys($payload) !== ['campaign']
            || ! is_string($payload['campaign'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $payload['campaign']) !== 1
        ) {
            throw new RuntimeException('Telegram broadcast campaign session payload is invalid.');
        }

        return $payload['campaign'];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{0:string,1:?string}
     */
    private function managePayload(array $payload): array
    {
        if (array_keys($payload) === ['campaign']) {
            return [$this->campaignPayload($payload), null];
        }
        if (array_keys($payload) !== ['campaign', 'group']
            || ! is_string($payload['campaign'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $payload['campaign']) !== 1
            || ! is_string($payload['group'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $payload['group']) !== 1
        ) {
            throw new RuntimeException('Telegram broadcast manage session payload is invalid.');
        }

        return [$payload['campaign'], $payload['group']];
    }

    /** @param  array<string,mixed>  $payload */
    private function sourceModePayload(array $payload): TelegramBroadcastMessageMode
    {
        if (array_keys($payload) !== ['mode'] || ! is_string($payload['mode'] ?? null)) {
            throw new RuntimeException('Telegram broadcast source-mode payload is invalid.');
        }
        $mode = TelegramBroadcastMessageMode::tryFrom($payload['mode']);
        if (! in_array($mode, [TelegramBroadcastMessageMode::Copy, TelegramBroadcastMessageMode::Forward], true)) {
            throw new RuntimeException('Telegram broadcast source mode is invalid.');
        }

        return $mode;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{0:TelegramBroadcastMessageMode,1:TelegramBroadcastSourceKind}
     */
    private function sourcePayload(array $payload): array
    {
        if (array_keys($payload) !== ['mode', 'source_kind']
            || ! is_string($payload['mode'] ?? null)
            || ! is_string($payload['source_kind'] ?? null)
        ) {
            throw new RuntimeException('Telegram broadcast source session payload is invalid.');
        }

        $mode = TelegramBroadcastMessageMode::tryFrom($payload['mode']);
        $sourceKind = TelegramBroadcastSourceKind::tryFrom($payload['source_kind']);
        if (! in_array($mode, [TelegramBroadcastMessageMode::Copy, TelegramBroadcastMessageMode::Forward], true)
            || $sourceKind === null
        ) {
            throw new RuntimeException('Telegram broadcast source session payload is invalid.');
        }

        return [$mode, $sourceKind];
    }

    /** @param  array<string,mixed>  $payload */
    private function sourceKindFromCallback(array $payload): TelegramBroadcastSourceKind
    {
        if (array_keys($payload) !== ['source_kind']
            || ! is_string($payload['source_kind'] ?? null)
        ) {
            throw new RuntimeException('Telegram broadcast source-kind callback payload is invalid.');
        }

        return TelegramBroadcastSourceKind::tryFrom($payload['source_kind'])
            ?? throw new RuntimeException('Telegram broadcast source-kind callback is invalid.');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{0:string,1:TelegramBroadcastLifecycleAction}
     */
    private function lifecycleReviewPayload(array $payload): array
    {
        if (array_keys($payload) !== ['action', 'campaign']
            || ! is_string($payload['action'] ?? null)
            || ! is_string($payload['campaign'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $payload['campaign']) !== 1
        ) {
            throw new RuntimeException('Telegram broadcast lifecycle-review payload is invalid.');
        }

        $action = TelegramBroadcastLifecycleAction::tryFrom($payload['action']);
        if (! in_array($action, [
            TelegramBroadcastLifecycleAction::Edit,
            TelegramBroadcastLifecycleAction::Buttons,
        ], true)) {
            throw new RuntimeException('Telegram broadcast lifecycle-review action is invalid.');
        }

        return [$payload['campaign'], $action];
    }

    private function scheduleTime(string $input): DateTimeImmutable
    {
        $input = trim($input);
        if (strlen($input) > 64
            || preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})\z/', $input) !== 1
        ) {
            throw new DomainException('Broadcast schedule must include an explicit ISO 8601 timezone.');
        }

        try {
            return new DateTimeImmutable($input);
        } catch (Throwable $exception) {
            throw new DomainException('Broadcast schedule timestamp is invalid.', 0, $exception);
        }
    }

    private function stateLabel(TelegramBroadcastCampaignState $state, string $locale): string
    {
        return $this->translation('telegram.broadcast.states.'.$state->value, $locale);
    }

    private function withNotice(string $text, string $locale, ?string $notice): string
    {
        if ($notice === null) {
            return $text;
        }

        return $this->translation('telegram.broadcast.'.$notice, $locale)."\n\n".$text;
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
            throw new RuntimeException('Telegram broadcast translation is unavailable.');
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram broadcast translation has an unresolved placeholder.');
        }

        return $value;
    }

    private function actionForSourceMessage(TelegramSourceMessageInteraction $interaction): TelegramInteractionAction
    {
        return new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $interaction->requestKey,
            $interaction->botId,
            $interaction->updateId,
            $interaction->telegramAccountId,
            $interaction->userId,
            $interaction->telegramUserId,
            $interaction->sessionPublicId,
            $interaction->flow,
            $interaction->sessionState,
            $interaction->sessionVersion,
            $interaction->sessionPayload,
            null,
            null,
            null,
            [],
            $interaction->replayed,
            null,
            null,
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

    private function assertActor(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new RuntimeException('Telegram broadcast session actor binding is invalid.');
        }
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                TelegramNavigationEntryGateway::STATE,
                [],
                'tg-broadcast-return-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->navigation->handle($this->actionForSession($action, $session, 'home'));
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

<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummary;
use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramAgentBulkPurchase;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/**
 * Telegram presentation/orchestration for AGT-004. The selected children are
 * already-authoritative Agent purchase settlements; this handler never charges
 * or captures payment and delegates the parent/child mutation to the reviewed
 * AgentBulkOrderService through TelegramAgentBulkPurchase.
 */
final readonly class TelegramAgentBulkPurchaseNavigationHandler
{
    public const ACTION_OPEN = 'navigation.agent.bulk';

    private const STATE_SELECT = 'agent_bulk_select';

    private const STATE_REVIEW = 'agent_bulk_review';

    private const STATE_SUBMITTING = 'agent_bulk_submitting';

    private const STATE_RESULT = 'agent_bulk_result';

    private const ACTION_TOGGLE = 'navigation.agent.bulk.toggle';

    private const ACTION_PAGE = 'navigation.agent.bulk.page';

    private const ACTION_REVIEW = 'navigation.agent.bulk.review';

    private const ACTION_CONFIRM = 'navigation.agent.bulk.confirm';

    private const ACTION_RETRY = 'navigation.agent.bulk.retry';

    private const ACTION_CANCEL = 'navigation.agent.bulk.cancel';

    private const ACTION_BACK = 'navigation.back';

    private const PAGE_SIZE = 6;

    private const MAX_SELECTION = 50;

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $confidentialPresentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private CustomerAccountSummaryService $customers,
        private TelegramAgentBulkPurchase $bulkPurchases,
        private TelegramAgentNavigationHandler $agent,
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->kind === TelegramInteractionActionKind::Callback
                && in_array($action->callbackAction, [
                    self::ACTION_OPEN,
                    self::ACTION_TOGGLE,
                    self::ACTION_PAGE,
                    self::ACTION_REVIEW,
                    self::ACTION_CONFIRM,
                    self::ACTION_RETRY,
                    self::ACTION_CANCEL,
                ], true))
            || in_array($action->sessionState, [
                self::STATE_SELECT,
                self::STATE_REVIEW,
                self::STATE_SUBMITTING,
                self::STATE_RESULT,
            ], true);
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->replayed && $this->recoverAdvancedReplay($action)) {
            return;
        }
        if ($action->sessionState === self::STATE_SUBMITTING) {
            $this->completeSubmitting($action, $action->sessionVersion, $action->sessionPayload);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_OPEN) {
                $this->open($action);
            } elseif ($action->callbackAction === self::ACTION_TOGGLE) {
                $this->toggle($action, $this->settlementIdFromPayload($action->callbackPayload));
            } elseif ($action->callbackAction === self::ACTION_PAGE) {
                $this->changePage($action, $this->pageFromCallbackPayload($action->callbackPayload));
            } elseif ($action->callbackAction === self::ACTION_REVIEW) {
                $this->review($action);
            } elseif ($action->callbackAction === self::ACTION_CONFIRM) {
                $this->confirm($action);
            } elseif ($action->callbackAction === self::ACTION_RETRY) {
                $this->retry($action);
            } elseif ($action->callbackAction === self::ACTION_CANCEL) {
                $this->returnAgent($action, 'cancel');
            } elseif ($action->callbackAction === self::ACTION_BACK) {
                $this->back($action);
            } else {
                $this->unsupportedCallback();
            }

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->back($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function open(TelegramInteractionAction $action): void
    {
        if ($action->callbackPayload !== [] || $action->sessionState !== 'agent_cooperation') {
            throw new RuntimeException('Telegram Agent bulk purchase open callback is invalid.');
        }
        $summary = $this->activeAgentSummary($action);
        $catalog = $this->bulkPurchases->pageForSelf($action->userId, $action->userId, 1, self::PAGE_SIZE);
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_SELECT,
                ['page' => $catalog->page, 'selected' => []],
                'tg-agent-bulk-open:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderSelection($action, $session->version, $summary, $catalog->page, [], null, $catalog);
    }

    private function toggle(TelegramInteractionAction $action, string $settlementPublicId): void
    {
        if ($action->sessionState !== self::STATE_SELECT) {
            throw new RuntimeException('Telegram Agent bulk purchase toggle state is invalid.');
        }
        $summary = $this->activeAgentSummary($action);
        [$page, $selected] = $this->selectionState($action->sessionPayload);
        $notice = null;

        $existing = array_search($settlementPublicId, $selected, true);
        if ($existing !== false) {
            array_splice($selected, (int) $existing, 1);
        } else {
            if (count($selected) >= self::MAX_SELECTION) {
                $notice = 'selection_limit';
            } else {
                try {
                    $this->bulkPurchases->candidatesForSelf($action->userId, $action->userId, [$settlementPublicId]);
                    $selected[] = $settlementPublicId;
                } catch (AuthorizationException) {
                    $notice = 'selection_stale';
                }
            }
        }

        $session = $this->transitionOrNull(
            $action,
            self::STATE_SELECT,
            ['page' => $page, 'selected' => $selected],
            'toggle:'.$settlementPublicId,
        );
        if ($session === null) {
            return;
        }
        $this->renderSelection($action, $session->version, $summary, $page, $selected, $notice);
    }

    private function changePage(TelegramInteractionAction $action, int $page): void
    {
        if ($action->sessionState !== self::STATE_SELECT) {
            throw new RuntimeException('Telegram Agent bulk purchase page state is invalid.');
        }
        $summary = $this->activeAgentSummary($action);
        [, $selected] = $this->selectionState($action->sessionPayload);
        $catalog = $this->bulkPurchases->pageForSelf($action->userId, $action->userId, $page, self::PAGE_SIZE);
        $session = $this->transitionOrNull(
            $action,
            self::STATE_SELECT,
            ['page' => $catalog->page, 'selected' => $selected],
            'page:'.$catalog->page,
        );
        if ($session === null) {
            return;
        }
        $this->renderSelection($action, $session->version, $summary, $catalog->page, $selected, null, $catalog);
    }

    private function review(TelegramInteractionAction $action): void
    {
        if ($action->callbackPayload !== [] || $action->sessionState !== self::STATE_SELECT) {
            throw new RuntimeException('Telegram Agent bulk purchase review callback is invalid.');
        }
        $summary = $this->activeAgentSummary($action);
        [$page, $selected] = $this->selectionState($action->sessionPayload);
        if ($selected === []) {
            $session = $this->transitionOrNull(
                $action,
                self::STATE_SELECT,
                ['page' => $page, 'selected' => []],
                'review-empty',
            );
            if ($session !== null) {
                $this->renderSelection($action, $session->version, $summary, $page, [], 'selection_empty');
            }

            return;
        }

        try {
            $candidates = $this->bulkPurchases->candidatesForSelf($action->userId, $action->userId, $selected);
        } catch (AuthorizationException) {
            $session = $this->transitionOrNull(
                $action,
                self::STATE_SELECT,
                ['page' => 1, 'selected' => []],
                'review-stale',
            );
            if ($session !== null) {
                $this->renderSelection($action, $session->version, $summary, 1, [], 'selection_stale');
            }

            return;
        }

        $batchKey = $this->batchKey($action->sessionPublicId, $selected);
        $session = $this->transitionOrNull(
            $action,
            self::STATE_REVIEW,
            ['page' => $page, 'selected' => $selected, 'batch_key' => $batchKey],
            'review:'.$batchKey,
        );
        if ($session === null) {
            return;
        }
        $this->renderReview($action, $session->version, $summary, $candidates);
    }

    private function confirm(TelegramInteractionAction $action): void
    {
        if ($action->callbackPayload !== [] || $action->sessionState !== self::STATE_REVIEW) {
            throw new RuntimeException('Telegram Agent bulk purchase confirmation callback is invalid.');
        }
        $summary = $this->activeAgentSummary($action);
        [$page, $selected, $batchKey] = $this->reviewState($action->sessionPayload);
        try {
            $this->bulkPurchases->candidatesForSelf($action->userId, $action->userId, $selected);
        } catch (AuthorizationException|DomainException) {
            $this->recoverUnavailableSubmission($action, $action->sessionVersion, [
                'page' => $page,
                'selected' => $selected,
                'batch_key' => $batchKey,
                'operation_key' => hash('sha256', $action->requestKey),
                'mode' => 'confirm',
                'previous_bulk_order_public_id' => null,
                'previous_succeeded' => 0,
                'previous_failed' => 0,
            ], $summary);

            return;
        }

        $operationKey = hash('sha256', $action->requestKey);
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_SUBMITTING,
                [
                    'page' => $page,
                    'selected' => $selected,
                    'batch_key' => $batchKey,
                    'operation_key' => $operationKey,
                    'mode' => 'confirm',
                    'previous_bulk_order_public_id' => null,
                    'previous_succeeded' => 0,
                    'previous_failed' => 0,
                ],
                'tg-agent-bulk-confirm-claim:'.$operationKey,
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->completeSubmitting($action, $session->version, $session->payload);
    }

    private function retry(TelegramInteractionAction $action): void
    {
        if ($action->callbackPayload !== [] || $action->sessionState !== self::STATE_RESULT) {
            throw new RuntimeException('Telegram Agent bulk purchase retry callback is invalid.');
        }
        $summary = $this->agentSummary($action);
        [$page, $selected, $batchKey, $operationKey, $bulkOrderPublicId, $succeeded, $failed] = $this->resultState($action->sessionPayload);
        if ($failed < 1) {
            throw new RuntimeException('Telegram Agent bulk purchase has no failed child to retry.');
        }
        $operationKey = hash('sha256', $action->requestKey);
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_SUBMITTING,
                [
                    'page' => $page,
                    'selected' => $selected,
                    'batch_key' => $batchKey,
                    'operation_key' => $operationKey,
                    'mode' => 'retry',
                    'previous_bulk_order_public_id' => $bulkOrderPublicId,
                    'previous_succeeded' => $succeeded,
                    'previous_failed' => $failed,
                ],
                'tg-agent-bulk-retry-claim:'.$operationKey,
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->completeSubmitting($action, $session->version, $session->payload, $summary);
    }

    /** @param array<string,mixed> $payload */
    private function completeSubmitting(
        TelegramInteractionAction $action,
        int $sessionVersion,
        array $payload,
        ?CustomerAccountSummary $knownSummary = null,
    ): void {
        $state = $this->submittingState($payload);
        try {
            $summary = $knownSummary ?? $this->activeAgentSummary($action);
            $result = $this->bulkPurchases->executeForSelf(
                $action->userId,
                $action->userId,
                $state['batch_key'],
                $state['selected'],
                'tg-bulk:'.substr($state['operation_key'], 0, 48),
            );
        } catch (AuthorizationException|DomainException) {
            $this->recoverUnavailableSubmission($action, $sessionVersion, $state, $knownSummary);

            return;
        }

        $resultPayload = [
            'page' => $state['page'],
            'selected' => $state['selected'],
            'batch_key' => $state['batch_key'],
            'operation_key' => $state['operation_key'],
            'bulk_order_public_id' => $result->bulkOrderPublicId,
            'succeeded' => $result->succeededCount,
            'failed' => $result->failedCount,
        ];
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $sessionVersion,
                self::STATE_RESULT,
                $resultPayload,
                'tg-agent-bulk-submit-complete:'.$state['operation_key'],
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderResult(
            $action,
            $session->version,
            $summary,
            $result->bulkOrderPublicId,
            $result->succeededCount,
            $result->failedCount,
            $result->replayed,
            null,
        );
    }

    /**
     * @param  array{page:int,selected:list<string>,batch_key:string,operation_key:string,mode:string,previous_bulk_order_public_id:?string,previous_succeeded:int,previous_failed:int}  $state
     */
    private function recoverUnavailableSubmission(
        TelegramInteractionAction $action,
        int $sessionVersion,
        array $state,
        ?CustomerAccountSummary $knownSummary = null,
    ): void {
        if ($state['mode'] === 'retry' && $state['previous_bulk_order_public_id'] !== null) {
            try {
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $sessionVersion,
                    self::STATE_RESULT,
                    [
                        'page' => $state['page'],
                        'selected' => $state['selected'],
                        'batch_key' => $state['batch_key'],
                        'operation_key' => $state['operation_key'],
                        'bulk_order_public_id' => $state['previous_bulk_order_public_id'],
                        'succeeded' => $state['previous_succeeded'],
                        'failed' => $state['previous_failed'],
                    ],
                    'tg-agent-bulk-retry-unavailable:'.$state['operation_key'],
                );
            } catch (DomainException) {
                return;
            }
            $this->assertActor($action, $session->userId);
            $summary = $knownSummary ?? $this->agentSummary($action);
            $this->renderResult(
                $action,
                $session->version,
                $summary,
                $state['previous_bulk_order_public_id'],
                $state['previous_succeeded'],
                $state['previous_failed'],
                true,
                'retry_unavailable',
            );

            return;
        }

        try {
            $summary = $knownSummary ?? $this->activeAgentSummary($action);
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $sessionVersion,
                self::STATE_SELECT,
                ['page' => 1, 'selected' => []],
                'tg-agent-bulk-confirm-unavailable:'.$state['operation_key'],
            );
            $this->assertActor($action, $session->userId);
            $this->renderSelection($action, $session->version, $summary, 1, [], 'selection_stale');
        } catch (AuthorizationException|DomainException) {
            $this->returnAgentFromVersion($action, $sessionVersion, 'unavailable');
        }
    }

    private function recoverAdvancedReplay(TelegramInteractionAction $action): bool
    {
        $session = $this->sessions->activeForAccount($action->telegramAccountId);
        if ($session === null
            || $session->publicId !== $action->sessionPublicId
            || $session->userId !== $action->userId
            || $session->version <= $action->sessionVersion) {
            return false;
        }

        $operationKey = hash('sha256', $action->requestKey);
        if ($session->state === self::STATE_SUBMITTING) {
            $state = $this->submittingState($session->payload);
            if (hash_equals($state['operation_key'], $operationKey)) {
                $this->completeSubmitting($action, $session->version, $session->payload);
            }

            return true;
        }
        if ($session->state === self::STATE_RESULT) {
            [$page, $selected, $batchKey, $storedOperationKey, $bulkOrderPublicId, $succeeded, $failed] = $this->resultState($session->payload);
            unset($page, $selected, $batchKey);
            if (hash_equals($storedOperationKey, $operationKey)) {
                try {
                    $summary = $this->agentSummary($action);
                } catch (AuthorizationException) {
                    return true;
                }
                $this->renderResult(
                    $action,
                    $session->version,
                    $summary,
                    $bulkOrderPublicId,
                    $succeeded,
                    $failed,
                    true,
                    null,
                );
            }

            return true;
        }

        return true;
    }

    private function back(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === self::STATE_REVIEW) {
            [$page, $selected] = $this->reviewState($action->sessionPayload);
            $summary = $this->activeAgentSummary($action);
            $session = $this->transitionOrNull(
                $action,
                self::STATE_SELECT,
                ['page' => $page, 'selected' => $selected],
                'review-back',
            );
            if ($session !== null) {
                $this->renderSelection($action, $session->version, $summary, $page, $selected, null);
            }

            return;
        }
        if (in_array($action->sessionState, [self::STATE_SELECT, self::STATE_RESULT], true)) {
            $this->returnAgent($action, 'back');

            return;
        }
        if ($action->sessionState === self::STATE_SUBMITTING) {
            return;
        }

        throw new RuntimeException('Telegram Agent bulk purchase Back state is invalid.');
    }

    private function returnAgent(TelegramInteractionAction $action, string $reason): void
    {
        if ($action->callbackAction === self::ACTION_CANCEL && $action->callbackPayload !== []) {
            throw new RuntimeException('Telegram Agent bulk purchase cancel callback payload is invalid.');
        }
        $this->returnAgentFromVersion($action, $action->sessionVersion, $reason);
    }

    private function returnAgentFromVersion(TelegramInteractionAction $action, int $sessionVersion, string $reason): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $sessionVersion,
                'agent_cooperation',
                [],
                'tg-agent-bulk-'.$reason.':'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->agent->renderCurrentMenu($action, $session->version);
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        $session = $this->transitionOrNull($action, TelegramNavigationEntryGateway::STATE, [], 'home');
        if ($session === null) {
            return;
        }
        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':bulk-home',
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
            '/menu',
            null,
            null,
            [],
            $action->replayed,
            null,
            $action->messageAcceptedAt,
        ));
    }

    /** @param list<string> $selected */
    private function renderSelection(
        TelegramInteractionAction $action,
        int $sessionVersion,
        CustomerAccountSummary $summary,
        int $page,
        array $selected,
        ?string $notice,
        ?TelegramAgentBulkPurchasePage $catalog = null,
    ): void {
        $locale = $summary->locale === 'en' ? 'en' : 'fa';
        $catalog ??= $this->bulkPurchases->pageForSelf($action->userId, $action->userId, $page, self::PAGE_SIZE);
        $rows = [];
        foreach ($catalog->items as $candidate) {
            $selectedHere = in_array($candidate->purchaseSettlementPublicId, $selected, true);
            $rows[] = [$this->button(
                $action,
                $sessionVersion,
                self::ACTION_TOGGLE,
                ['settlement' => $candidate->purchaseSettlementPublicId],
                'toggle-'.$candidate->purchaseSettlementPublicId,
                $this->candidateButtonLabel($candidate, $selectedHere),
                $selectedHere ? TelegramInlineButtonStyle::Primary : null,
            )];
        }
        $pages = [];
        if ($catalog->page > 1) {
            $pages[] = $this->button($action, $sessionVersion, self::ACTION_PAGE, ['page' => $catalog->page - 1], 'prev', '‹');
        }
        if ($catalog->page < $catalog->totalPages) {
            $pages[] = $this->button($action, $sessionVersion, self::ACTION_PAGE, ['page' => $catalog->page + 1], 'next', '›');
        }
        if ($pages !== []) {
            $rows[] = $pages;
        }
        if ($selected !== []) {
            $rows[] = [$this->button(
                $action,
                $sessionVersion,
                self::ACTION_REVIEW,
                [],
                'review',
                $this->translation('telegram_agent.bulk.review_button', $locale, ['count' => (string) count($selected)]),
                TelegramInlineButtonStyle::Primary,
            )];
        }
        $rows[] = [$this->button($action, $sessionVersion, self::ACTION_CANCEL, [], 'cancel', $this->translation('telegram_agent.bulk.cancel_button', $locale))];

        $text = $this->translation('telegram_agent.bulk.select', $locale, [
            'selected' => (string) count($selected),
            'total' => (string) $catalog->totalItems,
            'page' => (string) $catalog->page,
            'pages' => (string) $catalog->totalPages,
        ]);
        if ($catalog->totalItems === 0) {
            $text .= "\n\n".$this->translation('telegram_agent.bulk.empty', $locale);
        }
        if ($notice !== null) {
            $text .= "\n\n".$this->translation('telegram_agent.bulk.notice.'.$notice, $locale);
        }
        $this->queueConfidential($action, $text, 'bulk-select-'.$catalog->page, new TelegramInlineKeyboardSnapshot($rows));
    }

    /** @param list<TelegramAgentBulkPurchaseCandidate> $candidates */
    private function renderReview(
        TelegramInteractionAction $action,
        int $sessionVersion,
        CustomerAccountSummary $summary,
        array $candidates,
    ): void {
        $locale = $summary->locale === 'en' ? 'en' : 'fa';
        $lines = [];
        $total = 0;
        foreach ($candidates as $index => $candidate) {
            $total += $candidate->amountIrr;
            if ($index < 10) {
                $lines[] = ($index + 1).'. '.$candidate->offeringCode.' — '.number_format($candidate->amountIrr, 0, '.', ',').' IRR';
            }
        }
        if (count($candidates) > 10) {
            $lines[] = $this->translation('telegram_agent.bulk.more_items', $locale, [
                'count' => (string) (count($candidates) - 10),
            ]);
        }
        $text = $this->translation('telegram_agent.bulk.review', $locale, [
            'count' => (string) count($candidates),
            'total' => number_format($total, 0, '.', ','),
            'items' => implode("\n", $lines),
        ]);
        $rows = [[
            $this->button($action, $sessionVersion, self::ACTION_CONFIRM, [], 'confirm', $this->translation('telegram_agent.bulk.confirm_button', $locale), TelegramInlineButtonStyle::Primary),
        ], [
            $this->button($action, $sessionVersion, self::ACTION_BACK, [], 'review-back', $this->translation('telegram.navigation.buttons.back', $locale)),
            $this->button($action, $sessionVersion, self::ACTION_CANCEL, [], 'review-cancel', $this->translation('telegram_agent.bulk.cancel_button', $locale)),
        ]];
        $this->queueConfidential($action, $text, 'bulk-review', new TelegramInlineKeyboardSnapshot($rows));
    }

    private function renderResult(
        TelegramInteractionAction $action,
        int $sessionVersion,
        CustomerAccountSummary $summary,
        string $bulkOrderPublicId,
        int $succeeded,
        int $failed,
        bool $replayed,
        ?string $notice,
    ): void {
        $locale = $summary->locale === 'en' ? 'en' : 'fa';
        $text = $this->translation('telegram_agent.bulk.result', $locale, [
            'bulk_order' => $bulkOrderPublicId,
            'succeeded' => (string) $succeeded,
            'failed' => (string) $failed,
            'replayed' => $replayed ? $this->translation('telegram_agent.bulk.yes', $locale) : $this->translation('telegram_agent.bulk.no', $locale),
        ]);
        if ($notice !== null) {
            $text .= "\n\n".$this->translation('telegram_agent.bulk.notice.'.$notice, $locale);
        }
        $rows = [];
        if ($failed > 0) {
            $rows[] = [$this->button($action, $sessionVersion, self::ACTION_RETRY, [], 'retry', $this->translation('telegram_agent.bulk.retry_button', $locale), TelegramInlineButtonStyle::Primary)];
        }
        $rows[] = [$this->button($action, $sessionVersion, self::ACTION_CANCEL, [], 'done', $this->translation('telegram_agent.bulk.done_button', $locale))];
        $this->queueConfidential($action, $text, 'bulk-result', new TelegramInlineKeyboardSnapshot($rows));
    }

    /** @param array<string,mixed> $payload
     * @return array{0:int,1:list<string>}
     */
    private function selectionState(array $payload): array
    {
        if (! $this->hasExactKeys($payload, ['page', 'selected']) || ! is_int($payload['page']) || $payload['page'] < 1 || ! is_array($payload['selected']) || ! array_is_list($payload['selected'])) {
            throw new RuntimeException('Telegram Agent bulk purchase selection state is invalid.');
        }

        return [$payload['page'], $this->selectedIds($payload['selected'])];
    }

    /** @param array<string,mixed> $payload
     * @return array{0:int,1:list<string>,2:string}
     */
    private function reviewState(array $payload): array
    {
        if (! $this->hasExactKeys($payload, ['page', 'selected', 'batch_key']) || ! is_string($payload['batch_key'])) {
            throw new RuntimeException('Telegram Agent bulk purchase review state is invalid.');
        }
        [$page, $selected] = $this->selectionState(['page' => $payload['page'], 'selected' => $payload['selected']]);
        $this->assertBatchKey($payload['batch_key']);

        return [$page, $selected, $payload['batch_key']];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{page:int,selected:list<string>,batch_key:string,operation_key:string,mode:string,previous_bulk_order_public_id:?string,previous_succeeded:int,previous_failed:int}
     */
    private function submittingState(array $payload): array
    {
        $expected = ['page', 'selected', 'batch_key', 'operation_key', 'mode', 'previous_bulk_order_public_id', 'previous_succeeded', 'previous_failed'];
        if (! $this->hasExactKeys($payload, $expected)
            || ! is_string($payload['operation_key'])
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['operation_key']) !== 1
            || ! is_string($payload['mode'])
            || ! in_array($payload['mode'], ['confirm', 'retry'], true)
            || ! is_int($payload['previous_succeeded'])
            || ! is_int($payload['previous_failed'])
            || $payload['previous_succeeded'] < 0
            || $payload['previous_failed'] < 0) {
            throw new RuntimeException('Telegram Agent bulk purchase submitting state is invalid.');
        }
        [$page, $selected, $batchKey] = $this->reviewState([
            'page' => $payload['page'],
            'selected' => $payload['selected'],
            'batch_key' => $payload['batch_key'],
        ]);
        $previousBulkOrderPublicId = $payload['previous_bulk_order_public_id'];
        if ($payload['mode'] === 'confirm') {
            if ($previousBulkOrderPublicId !== null || $payload['previous_succeeded'] !== 0 || $payload['previous_failed'] !== 0) {
                throw new RuntimeException('Telegram Agent bulk purchase confirmation recovery state is invalid.');
            }
        } else {
            if (! is_string($previousBulkOrderPublicId) || $payload['previous_failed'] < 1) {
                throw new RuntimeException('Telegram Agent bulk purchase retry recovery state is invalid.');
            }
            $this->assertUlid($previousBulkOrderPublicId, 'Telegram Agent bulk retry Order public ID');
        }

        return [
            'page' => $page,
            'selected' => $selected,
            'batch_key' => $batchKey,
            'operation_key' => $payload['operation_key'],
            'mode' => $payload['mode'],
            'previous_bulk_order_public_id' => $previousBulkOrderPublicId,
            'previous_succeeded' => $payload['previous_succeeded'],
            'previous_failed' => $payload['previous_failed'],
        ];
    }

    /** @param array<string,mixed> $payload
     * @return array{0:int,1:list<string>,2:string,3:string,4:string,5:int,6:int}
     */
    private function resultState(array $payload): array
    {
        if (! $this->hasExactKeys($payload, ['page', 'selected', 'batch_key', 'operation_key', 'bulk_order_public_id', 'succeeded', 'failed'])
            || ! is_string($payload['operation_key'])
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['operation_key']) !== 1
            || ! is_string($payload['bulk_order_public_id'])
            || ! is_int($payload['succeeded'])
            || ! is_int($payload['failed'])
            || $payload['succeeded'] < 0
            || $payload['failed'] < 0
            || ($payload['succeeded'] + $payload['failed']) < 1) {
            throw new RuntimeException('Telegram Agent bulk purchase result state is invalid.');
        }
        [$page, $selected, $batchKey] = $this->reviewState([
            'page' => $payload['page'],
            'selected' => $payload['selected'],
            'batch_key' => $payload['batch_key'],
        ]);
        $this->assertUlid($payload['bulk_order_public_id'], 'Telegram Agent bulk Order public ID');

        return [$page, $selected, $batchKey, $payload['operation_key'], $payload['bulk_order_public_id'], $payload['succeeded'], $payload['failed']];
    }

    /** @param list<mixed> $values
     * @return list<string>
     */
    private function selectedIds(array $values): array
    {
        if (count($values) > self::MAX_SELECTION) {
            throw new RuntimeException('Telegram Agent bulk purchase selection exceeds the allowed limit.');
        }
        $ids = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new RuntimeException('Telegram Agent bulk purchase selection state contains an invalid value.');
            }
            $id = strtoupper($value);
            $this->assertUlid($id, 'Telegram Agent bulk purchase settlement public ID');
            if (isset($ids[$id])) {
                throw new RuntimeException('Telegram Agent bulk purchase selection state contains duplicates.');
            }
            $ids[$id] = true;
        }

        return array_keys($ids);
    }

    /** @param array<string,mixed> $payload */
    private function settlementIdFromPayload(array $payload): string
    {
        if (! $this->hasExactKeys($payload, ['settlement']) || ! is_string($payload['settlement'])) {
            throw new RuntimeException('Telegram Agent bulk purchase settlement callback payload is invalid.');
        }
        $id = strtoupper($payload['settlement']);
        $this->assertUlid($id, 'Telegram Agent bulk purchase settlement callback');

        return $id;
    }

    /** @param array<string,mixed> $payload */
    private function pageFromCallbackPayload(array $payload): int
    {
        if (! $this->hasExactKeys($payload, ['page']) || ! is_int($payload['page']) || $payload['page'] < 1) {
            throw new RuntimeException('Telegram Agent bulk purchase page callback payload is invalid.');
        }

        return $payload['page'];
    }

    /** @param list<string> $selected */
    private function batchKey(string $sessionPublicId, array $selected): string
    {
        $json = json_encode($selected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return 'tg-bulk:'.hash('sha256', $sessionPublicId."\0".$json);
    }

    private function assertBatchKey(string $value): void
    {
        if (preg_match('/\Atg-bulk:[0-9a-f]{64}\z/', $value) !== 1) {
            throw new RuntimeException('Telegram Agent bulk purchase batch key is invalid.');
        }
    }

    private function agentSummary(TelegramInteractionAction $action): CustomerAccountSummary
    {
        $summary = $this->customers->forSelf($action->userId, $action->userId);
        if ($summary->accountType !== 'agent' || $summary->agentStatus === null) {
            throw new AuthorizationException('Telegram Agent bulk purchase requires an Agent account.');
        }

        return $summary;
    }

    private function activeAgentSummary(TelegramInteractionAction $action): CustomerAccountSummary
    {
        $summary = $this->agentSummary($action);
        if ($summary->accountStatus !== 'active' || $summary->agentStatus !== 'active') {
            throw new AuthorizationException('Telegram Agent bulk purchase requires an active Agent account.');
        }

        return $summary;
    }

    /** @param array<string,mixed> $payload */
    private function transitionOrNull(
        TelegramInteractionAction $action,
        string $state,
        array $payload,
        string $suffix,
    ): ?TelegramInteractionSessionReceipt {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                $state,
                $payload,
                'tg-agent-bulk-'.$suffix.':'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return null;
        }
        $this->assertActor($action, $session->userId);

        return $session;
    }

    private function assertActor(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new AuthorizationException('Telegram Agent bulk purchase actor binding changed.');
        }
    }

    private function candidateButtonLabel(TelegramAgentBulkPurchaseCandidate $candidate, bool $selected): string
    {
        $code = strlen($candidate->offeringCode) > 24
            ? substr($candidate->offeringCode, 0, 21).'...'
            : $candidate->offeringCode;

        return ($selected ? '☑ ' : '☐ ').$code.' · '.number_format($candidate->amountIrr, 0, '.', ',').' IRR';
    }

    /** @param array<string,int|string> $payload */
    private function button(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $callbackAction,
        array $payload,
        string $surface,
        string $label,
        ?TelegramInlineButtonStyle $style = null,
    ): TelegramInlineCallbackButton {
        $callback = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            $callbackAction,
            $payload,
            'tg-agent-bulk-'.$surface.':'.hash('sha256', $action->requestKey),
        );

        return new TelegramInlineCallbackButton($label, $callback->publicId, $style);
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
            'tg-agent-bulk-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-agent-bulk:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
            $keyboard,
        );
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === '' || $value === '['.$key.']') {
            throw new RuntimeException('Telegram Agent bulk purchase localization key is unavailable: '.$key);
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram Agent bulk purchase translation has an unresolved placeholder.');
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $expected
     */
    private function hasExactKeys(array $payload, array $expected): bool
    {
        $actual = array_keys($payload);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function assertUlid(string $value, string $label): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
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

    private function unsupportedCallback(): never
    {
        throw new RuntimeException('Telegram Agent bulk purchase callback action is unsupported.');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Agents\Application\AgentApplicationService;
use App\Modules\Agents\Application\AgentApplicationSubmissionRejected;
use App\Modules\Agents\Application\AgentChangeContext;
use App\Modules\Customers\Application\CustomerAccountSummary;
use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramAgentPurchaseCount;
use App\Modules\Telegram\Application\Contracts\TelegramAgentReport;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
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

    private const STATE_REPORT = 'agent_report';

    private const ACTION_AGENT = 'navigation.agent';

    private const ACTION_SUBMIT = 'navigation.agent.submit';

    private const ACTION_PURCHASE = 'navigation.agent.purchase';

    private const ACTION_SERVICES = 'navigation.agent.services';

    private const ACTION_REPORT = 'navigation.agent.report';

    private const ACTION_REPORT_RANGE = 'navigation.agent.report_range';

    private const ACTION_CANONICAL_PURCHASE = 'navigation.purchase';

    private const ACTION_CANONICAL_SERVICES = 'navigation.my_services';

    private const ACTION_CANONICAL_DISCOUNT = 'navigation.purchase.discount';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $confidentialPresentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private CustomerAccountSummaryService $customers,
        private AgentApplicationService $applications,
        private TelegramAgentPurchaseCount $purchaseCounts,
        private TelegramAgentReport $reports,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === TelegramNavigationEntryGateway::STATE
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_AGENT)
            || ($action->kind === TelegramInteractionActionKind::Callback
                && in_array($action->callbackAction, [
                    self::ACTION_PURCHASE,
                    self::ACTION_SERVICES,
                    self::ACTION_REPORT,
                    self::ACTION_REPORT_RANGE,
                    self::ACTION_CANONICAL_DISCOUNT,
                ], true))
            || in_array($action->sessionState, [self::STATE_AGENT, self::STATE_UNAVAILABLE, self::STATE_REPORT], true);
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_CANONICAL_DISCOUNT) {
            $this->handleCanonicalDiscount($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_PURCHASE) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Agent purchase callback payload is invalid.');
            }
            $this->openPurchase($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_SERVICES) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Agent Services callback payload is invalid.');
            }
            $this->openServices($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_REPORT) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Agent report callback payload is invalid.');
            }
            $this->openReport($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_REPORT_RANGE) {
            $this->changeReportPeriod($action, $this->reportPeriodFromPayload($action->callbackPayload));

            return;
        }

        if ($action->sessionState === TelegramNavigationEntryGateway::STATE) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Agent home callback payload is invalid.');
            }
            $this->showAgent($action);

            return;
        }

        if ($action->sessionState === self::STATE_REPORT) {
            if ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_BACK
                && $action->callbackPayload === []) {
                $this->returnAgent($action);

                return;
            }
            if ($action->kind === TelegramInteractionActionKind::Back) {
                $this->returnAgent($action);

                return;
            }
            if ($this->isEntryCommand($action->messageText)) {
                $this->returnHome($action);

                return;
            }
            if ($action->kind === TelegramInteractionActionKind::Callback) {
                throw new RuntimeException('Telegram Agent report callback action is unsupported.');
            }

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
        if ($action->replayed && $this->recoverAdvancedNavigationDelegation($action)) {
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

        $this->delegateToCanonicalNavigation($action, 'agent-purchase', self::ACTION_CANONICAL_PURCHASE);

    }

    private function openServices(TelegramInteractionAction $action): void
    {
        if ($action->replayed && $this->recoverAdvancedNavigationDelegation($action)) {
            return;
        }
        if ($action->sessionState !== self::STATE_AGENT) {
            throw new RuntimeException('Telegram Agent Services callback state is unsupported.');
        }

        $summary = $this->customers->forSelf($action->userId, $action->userId);
        if (! $this->canViewAgentServices($summary)) {
            $this->renderStatus($action, $action->sessionVersion, $summary, false);

            return;
        }

        $this->delegateToCanonicalNavigation($action, 'agent-services', self::ACTION_CANONICAL_SERVICES);

    }

    private function openReport(TelegramInteractionAction $action): void
    {
        if ($action->sessionState !== self::STATE_AGENT) {
            throw new RuntimeException('Telegram Agent report callback state is unsupported.');
        }

        $summary = $this->customers->forSelf($action->userId, $action->userId);
        if (! $this->canViewAgentReport($summary)) {
            $this->renderStatus($action, $action->sessionVersion, $summary, false);

            return;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_REPORT,
                ['period' => TelegramAgentReport::PERIOD_THIRTY_DAYS],
                'tg-agent-report-open:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderReport($action, $session->version, $summary, TelegramAgentReport::PERIOD_THIRTY_DAYS);
    }

    private function changeReportPeriod(TelegramInteractionAction $action, string $period): void
    {
        if ($action->sessionState !== self::STATE_REPORT) {
            throw new RuntimeException('Telegram Agent report-range callback state is unsupported.');
        }

        $summary = $this->customers->forSelf($action->userId, $action->userId);
        if (! $this->canViewAgentReport($summary)) {
            throw new AuthorizationException('Telegram Agent report access denied.');
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_REPORT,
                ['period' => $period],
                'tg-agent-report-range:'.hash('sha256', $action->requestKey.':'.$period),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderReport($action, $session->version, $summary, $period);
    }

    private function handleCanonicalDiscount(TelegramInteractionAction $action): void
    {
        if ($action->callbackPayload !== []) {
            throw new RuntimeException('Telegram purchase discount callback payload is invalid.');
        }

        $summary = $this->customers->forSelf($action->userId, $action->userId);
        if ($summary->accountType !== 'agent') {
            $this->navigation->handle($action);

            return;
        }

        $locale = $summary->locale === 'en' ? 'en' : 'fa';
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-agent-discount-unavailable-back:'.hash('sha256', $action->requestKey),
        );
        $this->queueConfidential(
            $action,
            $this->translation('telegram_agent.purchase.benefit_code_unavailable', $locale),
            'discount-unavailable',
            new TelegramInlineKeyboardSnapshot([[new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            )]]),
        );
    }

    private function recoverAdvancedNavigationDelegation(TelegramInteractionAction $action): bool
    {
        $session = $this->sessions->activeForAccount($action->telegramAccountId);

        return $session !== null
            && $session->publicId === $action->sessionPublicId
            && $session->userId === $action->userId
            && $session->version > $action->sessionVersion;
    }

    private function delegateToCanonicalNavigation(
        TelegramInteractionAction $action,
        string $requestSuffix,
        string $callbackAction,
    ): void {
        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Callback,
            $action->requestKey.':'.$requestSuffix,
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
            $callbackAction,
            [],
            $action->replayed,
            $action->callbackAcceptedAt,
            $action->messageAcceptedAt,
        ));
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

    private function returnAgent(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_AGENT,
                [],
                'tg-agent-report-back:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $summary = $this->customers->forSelf($action->userId, $action->userId);
        $this->renderStatus($action, $session->version, $summary, false);
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

        if ($this->canViewAgentServices($summary)) {
            $services = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_SERVICES,
                [],
                'tg-agent-services-button:'.hash('sha256', $action->requestKey),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram_agent.services.button', $locale),
                $services->publicId,
                TelegramInlineButtonStyle::Primary,
            )];
        }

        if ($this->canViewAgentReport($summary)) {
            $report = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_REPORT,
                [],
                'tg-agent-report-button:'.hash('sha256', $action->requestKey),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram_agent.report.button', $locale),
                $report->publicId,
                TelegramInlineButtonStyle::Primary,
            )];
        }

        $this->appendBack($action, $sessionVersion, $rows, $locale);

        if ($summary->accountType === 'agent' && $summary->agentStatus !== null) {
            $text = $this->translation('telegram_agent.agent.status', $locale, [
                'status' => $this->agentStatusLabel($summary->agentStatus, $locale),
                'joined_at' => $this->dateLabel($summary->joinedAt, $locale),
                'approved_at' => $this->dateLabel($summary->agentApprovedAt, $locale),
                'purchase_count' => (string) $this->purchaseCounts->forSelf($action->userId, $action->userId),
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

    private function renderReport(
        TelegramInteractionAction $action,
        int $sessionVersion,
        CustomerAccountSummary $summary,
        string $period,
    ): void {
        $locale = $summary->locale === 'en' ? 'en' : 'fa';
        $report = $this->reports->forSelf($action->userId, $action->userId, $period);
        $recent = $report->recentPurchasedOfferingCodes === []
            ? $this->translation('telegram_agent.not_available', $locale)
            : implode(', ', $report->recentPurchasedOfferingCodes);
        $text = $this->translation('telegram_agent.report.summary', $locale, [
            'period' => $this->reportPeriodLabel($period, $locale),
            'purchase_count' => (string) $report->purchaseCount,
            'spending_irr' => number_format($report->grossSpendingIrr, 0, '.', ','),
            'sales_count' => (string) $report->salesCount,
            'sales_irr' => number_format($report->grossSalesIrr, 0, '.', ','),
            'service_count' => (string) $report->purchasedServiceCount,
            'recent' => $recent,
        ]);

        $periodButtons = [];
        foreach (TelegramAgentReport::PERIODS as $candidate) {
            $callback = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_REPORT_RANGE,
                ['period' => $candidate],
                'tg-agent-report-period:'.hash('sha256', $action->requestKey.':'.$candidate),
            );
            $periodButtons[] = new TelegramInlineCallbackButton(
                $this->reportPeriodLabel($candidate, $locale),
                $callback->publicId,
                $candidate === $period ? TelegramInlineButtonStyle::Primary : null,
            );
        }
        $rows = [
            [$periodButtons[0], $periodButtons[1]],
            [$periodButtons[2], $periodButtons[3]],
        ];
        $this->appendBack($action, $sessionVersion, $rows, $locale);
        $this->queueConfidential(
            $action,
            $text,
            'report-'.$period,
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

    private function canViewAgentServices(CustomerAccountSummary $summary): bool
    {
        return $summary->accountType === 'agent'
            && $summary->agentStatus !== null;
    }

    private function canViewAgentReport(CustomerAccountSummary $summary): bool
    {
        return $summary->accountType === 'agent'
            && $summary->agentStatus !== null;
    }

    /** @param array<string,int|string> $payload */
    private function reportPeriodFromPayload(array $payload): string
    {
        if (count($payload) !== 1 || ! isset($payload['period']) || ! is_string($payload['period'])
            || ! in_array($payload['period'], TelegramAgentReport::PERIODS, true)) {
            throw new RuntimeException('Telegram Agent report period payload is invalid.');
        }

        return $payload['period'];
    }

    private function reportPeriodLabel(string $period, string $locale): string
    {
        if (! in_array($period, TelegramAgentReport::PERIODS, true)) {
            throw new RuntimeException('Telegram Agent report period is invalid.');
        }

        return $this->translation('telegram_agent.report.period.'.$period, $locale);
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
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === '' || $value === '['.$key.']') {
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

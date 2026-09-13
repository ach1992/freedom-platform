<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerTrialCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerTrialClaim;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Translation\Translator;
use RuntimeException;

/**
 * Customer Trial discovery and confirmed claim orchestration. Business authority
 * remains in Catalog/Orders/Provisioning; this handler owns only Telegram state,
 * safe opaque selections, explicit confirmation and confidential presentation.
 */
/**
 * @phpstan-type OfferingState array{page:int,selection:string}
 * @phpstan-type ProtocolState array{page:int,selection:string,route:?string}
 * @phpstan-type ConfirmationState array{page:int,selection:string,route:?string,protocol:?string}
 * @phpstan-type SubmittingState array{page:int,selection:string,route:?string,protocol:?string,operation_key:string,accepted_at:string,cancel_locked:true,expiry_locked:true}
 * @phpstan-type QueuedState array{operation_key:string,order_public_id:string,service_public_id:string,provisioning_public_id:string,data_bytes:int,duration_days:int,server_name_fa:string,server_name_en:?string,protocol_name_fa:string,protocol_name_en:?string,fallback_used:bool,fallback_disclosure_fa:?string,fallback_disclosure_en:?string}
 */
final readonly class TelegramTrialNavigationHandler
{
    private const STATE_CATALOG = 'trial_catalog';

    private const STATE_OFFERING = 'trial_offering';

    private const STATE_ROUTE = 'trial_route';

    private const STATE_PROTOCOL = 'trial_protocol';

    private const STATE_CONFIRM = 'trial_confirm';

    private const STATE_SUBMITTING = 'trial_claim_submitting';

    private const STATE_QUEUED = 'trial_claim_queued';

    private const ACTION_TRIAL = 'navigation.trial';

    private const ACTION_PAGE = 'navigation.trial.page';

    private const ACTION_OFFERING_PREFIX = 'navigation.trial.';

    private const ACTION_CLAIM = 'navigation.trial.claim';

    private const ACTION_ROUTE_AUTO = 'navigation.trial.r.auto';

    private const ACTION_ROUTE_PREFIX = 'navigation.trial.r.';

    private const ACTION_PROTOCOL_PREFIX = 'navigation.trial.p.';

    private const ACTION_CONFIRM = 'navigation.trial.confirm';

    private const ACTION_BACK = 'navigation.back';

    private const PAGE_SIZE = 6;

    public function __construct(
        private Translator $translator,
        private ConfidentialTelegramPresentationFactory $confidentialPresentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private CustomerAccountSummaryService $customers,
        private TelegramCustomerTrialCatalog $catalog,
        private TelegramCustomerTrialClaim $claims,
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === TelegramNavigationEntryGateway::STATE
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_TRIAL)
            || in_array($action->sessionState, [
                self::STATE_CATALOG,
                self::STATE_OFFERING,
                self::STATE_ROUTE,
                self::STATE_PROTOCOL,
                self::STATE_CONFIRM,
                self::STATE_SUBMITTING,
                self::STATE_QUEUED,
            ], true);
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->replayed && $this->recoverAdvancedClaim($action)) {
            return;
        }
        if ($action->replayed && $this->sessionAdvancedPast($action)) {
            return;
        }

        if ($action->sessionState === TelegramNavigationEntryGateway::STATE) {
            if ($action->kind !== TelegramInteractionActionKind::Callback
                || $action->callbackAction !== self::ACTION_TRIAL
                || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Trial home action is unsupported.');
            }
            $this->showCatalog($action, 1);

            return;
        }

        match ($action->sessionState) {
            self::STATE_CATALOG => $this->handleCatalog($action),
            self::STATE_OFFERING => $this->handleOffering($action),
            self::STATE_ROUTE => $this->handleRoute($action),
            self::STATE_PROTOCOL => $this->handleProtocol($action),
            self::STATE_CONFIRM => $this->handleConfirm($action),
            self::STATE_SUBMITTING => $this->completeSubmitting(
                $action,
                $action->sessionVersion,
                $action->sessionPayload,
            ),
            self::STATE_QUEUED => $this->handleQueued($action),
            default => throw new RuntimeException('Telegram Trial navigation state is unsupported.'),
        };
    }

    private function handleCatalog(TelegramInteractionAction $action): void
    {
        $this->catalogState($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_PAGE) {
                $this->showCatalog($action, $this->pageFromCallback($action->callbackPayload));

                return;
            }
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnHome($action);

                return;
            }
            $selection = $this->selectionFromAction($action->callbackAction, $action->callbackPayload);
            if ($selection !== null) {
                $this->showOffering($action, $selection);

                return;
            }

            throw new RuntimeException('Telegram Trial catalog callback action is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnHome($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleOffering(TelegramInteractionAction $action): void
    {
        $state = $this->offeringState($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Trial offering callback payload is invalid.');
            }
            if ($action->callbackAction === self::ACTION_BACK) {
                $this->showCatalog($action, $state['page']);

                return;
            }
            if ($action->callbackAction === self::ACTION_CLAIM) {
                $this->beginClaimSelection($action, $state);

                return;
            }
            throw new RuntimeException('Telegram Trial offering callback action is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showCatalog($action, $state['page']);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleRoute(TelegramInteractionAction $action): void
    {
        $state = $this->offeringState($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Trial route callback payload is invalid.');
            }
            if ($action->callbackAction === self::ACTION_BACK) {
                $this->showOfferingAtPage($action, $state['page'], $state['selection']);

                return;
            }
            $options = $this->claims->optionsForSelf(
                $action->userId,
                $action->userId,
                $state['selection'],
                null,
            );
            $route = null;
            if ($action->callbackAction === self::ACTION_ROUTE_AUTO) {
                if ($options->serverSelectionMode !== 'hybrid') {
                    throw new AuthorizationException('Telegram Trial automatic route selection is unavailable.');
                }
            } else {
                $route = $this->optionTokenFromAction($action->callbackAction, self::ACTION_ROUTE_PREFIX);
                if ($route === null || ! $this->containsRouteOption($options, $route)) {
                    throw new AuthorizationException('Telegram Trial route option is stale or unavailable.');
                }
            }
            $this->continueAfterRoute($action, $state['page'], $state['selection'], $route);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showOfferingAtPage($action, $state['page'], $state['selection']);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleProtocol(TelegramInteractionAction $action): void
    {
        $state = $this->protocolState($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Trial protocol callback payload is invalid.');
            }
            if ($action->callbackAction === self::ACTION_BACK) {
                $options = $this->claims->optionsForSelf(
                    $action->userId,
                    $action->userId,
                    $state['selection'],
                    $state['route'],
                );
                if (in_array($options->serverSelectionMode, ['customer_selects', 'hybrid'], true)) {
                    $this->showRouteSelection($action, $state['page'], $state['selection']);
                } else {
                    $this->showOfferingAtPage($action, $state['page'], $state['selection']);
                }

                return;
            }
            $protocol = $this->optionTokenFromAction($action->callbackAction, self::ACTION_PROTOCOL_PREFIX);
            $options = $this->claims->optionsForSelf(
                $action->userId,
                $action->userId,
                $state['selection'],
                $state['route'],
            );
            if ($protocol === null || ! $this->containsProtocolOption($options, $protocol)) {
                throw new AuthorizationException('Telegram Trial protocol option is stale or unavailable.');
            }
            $this->showConfirmation(
                $action,
                $state['page'],
                $state['selection'],
                $state['route'],
                $protocol,
            );

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showOfferingAtPage($action, $state['page'], $state['selection']);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleConfirm(TelegramInteractionAction $action): void
    {
        $state = $this->confirmationState($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Trial confirmation callback payload is invalid.');
            }
            if ($action->callbackAction === self::ACTION_BACK) {
                $options = $this->claims->optionsForSelf(
                    $action->userId,
                    $action->userId,
                    $state['selection'],
                    $state['route'],
                );
                if ($options->protocolSelectionMode === 'customer_selects') {
                    $this->showProtocolSelection(
                        $action,
                        $state['page'],
                        $state['selection'],
                        $state['route'],
                    );
                } elseif (in_array($options->serverSelectionMode, ['customer_selects', 'hybrid'], true)) {
                    $this->showRouteSelection($action, $state['page'], $state['selection']);
                } else {
                    $this->showOfferingAtPage($action, $state['page'], $state['selection']);
                }

                return;
            }
            if ($action->callbackAction === self::ACTION_CONFIRM) {
                $this->submitClaim($action, $state);

                return;
            }
            throw new RuntimeException('Telegram Trial confirmation callback action is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showOfferingAtPage($action, $state['page'], $state['selection']);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleQueued(TelegramInteractionAction $action): void
    {
        $state = $this->queuedState($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Trial queued callback action is unsupported.');
            }
            $this->returnHome($action);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        $this->renderQueuedState($action, $action->sessionVersion, $state, $this->locale($action->userId));
    }

    /** @param array{page:int,selection:string} $state */
    private function beginClaimSelection(TelegramInteractionAction $action, array $state): void
    {
        $options = $this->claims->optionsForSelf(
            $action->userId,
            $action->userId,
            $state['selection'],
            null,
        );
        if (in_array($options->serverSelectionMode, ['customer_selects', 'hybrid'], true)) {
            $this->showRouteSelection($action, $state['page'], $state['selection'], $options);

            return;
        }
        if ($options->protocolSelectionMode === 'customer_selects') {
            $this->showProtocolSelection($action, $state['page'], $state['selection'], null, $options);

            return;
        }
        $this->showConfirmation($action, $state['page'], $state['selection'], null, null, $options);
    }

    private function continueAfterRoute(
        TelegramInteractionAction $action,
        int $page,
        string $selection,
        ?string $route,
    ): void {
        $options = $this->claims->optionsForSelf($action->userId, $action->userId, $selection, $route);
        if ($options->protocolSelectionMode === 'customer_selects') {
            $this->showProtocolSelection($action, $page, $selection, $route, $options);

            return;
        }
        $this->showConfirmation($action, $page, $selection, $route, null, $options);
    }

    /** @param ConfirmationState $state */
    private function submitClaim(TelegramInteractionAction $action, array $state): void
    {
        if ($action->callbackAcceptedAt === null) {
            throw new RuntimeException('Telegram Trial confirmation acceptance timestamp is unavailable.');
        }
        $operationKey = hash('sha256', $action->requestKey);
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_SUBMITTING,
                [
                    ...$state,
                    'operation_key' => $operationKey,
                    'accepted_at' => $action->callbackAcceptedAt->format(DATE_ATOM),
                    'cancel_locked' => true,
                    'expiry_locked' => true,
                ],
                'tg-trial-claim-fence:'.$operationKey,
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->completeSubmitting($action, $session->version, $session->payload);
    }

    /** @param array<string,mixed> $payload */
    private function completeSubmitting(TelegramInteractionAction $action, int $sessionVersion, array $payload): void
    {
        $state = $this->submittingState($payload);
        try {
            $receipt = $this->claims->claimForSelf(
                $action->userId,
                $action->userId,
                $state['selection'],
                $state['route'],
                $state['protocol'],
                $state['operation_key'],
                new DateTimeImmutable($state['accepted_at']),
            );
        } catch (AuthorizationException|DomainException) {
            $this->recoverRejectedClaim($action, $sessionVersion, $state['page'], $state['operation_key']);

            return;
        }

        $queuedPayload = [
            'operation_key' => $state['operation_key'],
            'order_public_id' => $receipt->orderPublicId,
            'service_public_id' => $receipt->serviceSubscriptionPublicId,
            'provisioning_public_id' => $receipt->provisioningOperationPublicId,
            'data_bytes' => $receipt->dataBytes,
            'duration_days' => $receipt->durationDays,
            'server_name_fa' => $receipt->serverNameFa,
            'server_name_en' => $receipt->serverNameEn,
            'protocol_name_fa' => $receipt->protocolNameFa,
            'protocol_name_en' => $receipt->protocolNameEn,
            'fallback_used' => $receipt->fallbackUsed,
            'fallback_disclosure_fa' => $receipt->fallbackDisclosureFa,
            'fallback_disclosure_en' => $receipt->fallbackDisclosureEn,
        ];
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $sessionVersion,
                self::STATE_QUEUED,
                $queuedPayload,
                'tg-trial-claim-complete:'.$state['operation_key'],
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderQueuedState($action, $session->version, $this->queuedState($queuedPayload), $this->locale($action->userId));
    }

    private function recoverRejectedClaim(
        TelegramInteractionAction $action,
        int $sessionVersion,
        int $page,
        string $operationKey,
    ): void {
        $this->assertActiveCustomer($action->userId);
        try {
            $catalog = $this->catalog->pageForSelf($action->userId, $action->userId, max(1, $page), self::PAGE_SIZE);
        } catch (DomainException|AuthorizationException) {
            $catalog = $this->catalog->pageForSelf($action->userId, $action->userId, 1, self::PAGE_SIZE);
        }
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $sessionVersion,
                self::STATE_CATALOG,
                ['page' => $catalog->page],
                'tg-trial-claim-rejected:'.$operationKey,
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $locale = $this->locale($action->userId);
        $this->renderCatalog(
            $action,
            $session->version,
            $catalog,
            $locale,
            $this->translation('telegram_trial.claim_unavailable', $locale),
        );
    }

    private function showCatalog(TelegramInteractionAction $action, int $page): void
    {
        $this->assertActiveCustomer($action->userId);
        $catalog = $this->catalog->pageForSelf($action->userId, $action->userId, $page, self::PAGE_SIZE);
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_CATALOG,
                ['page' => $catalog->page],
                'tg-trial-catalog:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderCatalog($action, $session->version, $catalog, $this->locale($action->userId));
    }

    private function showOffering(TelegramInteractionAction $action, string $selectionToken): void
    {
        $this->showOfferingAtPage($action, $this->catalogState($action->sessionPayload), $selectionToken);
    }

    private function showOfferingAtPage(TelegramInteractionAction $action, int $page, string $selectionToken): void
    {
        $offering = $this->catalog->offeringForSelf($action->userId, $action->userId, $selectionToken);
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_OFFERING,
                ['page' => $page, 'selection' => $selectionToken],
                'tg-trial-offering:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderOffering($action, $session->version, $offering, $this->locale($action->userId));
    }

    private function showRouteSelection(
        TelegramInteractionAction $action,
        int $page,
        string $selection,
        ?TelegramCustomerTrialClaimOptions $options = null,
    ): void {
        $options ??= $this->claims->optionsForSelf($action->userId, $action->userId, $selection, null);
        if (! in_array($options->serverSelectionMode, ['customer_selects', 'hybrid'], true)) {
            throw new RuntimeException('Telegram Trial route selection state is not required.');
        }
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_ROUTE,
                ['page' => $page, 'selection' => $selection],
                'tg-trial-route-state:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderRouteSelection($action, $session->version, $options, $this->locale($action->userId));
    }

    private function showProtocolSelection(
        TelegramInteractionAction $action,
        int $page,
        string $selection,
        ?string $route,
        ?TelegramCustomerTrialClaimOptions $options = null,
    ): void {
        $options ??= $this->claims->optionsForSelf($action->userId, $action->userId, $selection, $route);
        if ($options->protocolSelectionMode !== 'customer_selects') {
            throw new RuntimeException('Telegram Trial protocol selection state is not required.');
        }
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_PROTOCOL,
                ['page' => $page, 'selection' => $selection, 'route' => $route],
                'tg-trial-protocol-state:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderProtocolSelection($action, $session->version, $options, $this->locale($action->userId));
    }

    private function showConfirmation(
        TelegramInteractionAction $action,
        int $page,
        string $selection,
        ?string $route,
        ?string $protocol,
        ?TelegramCustomerTrialClaimOptions $options = null,
    ): void {
        $options ??= $this->claims->optionsForSelf($action->userId, $action->userId, $selection, $route);
        $this->assertChosenOptions($options, $route, $protocol);
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_CONFIRM,
                ['page' => $page, 'selection' => $selection, 'route' => $route, 'protocol' => $protocol],
                'tg-trial-confirm-state:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $offering = $this->catalog->offeringForSelf($action->userId, $action->userId, $selection);
        $this->renderConfirmation(
            $action,
            $session->version,
            $offering,
            $options,
            $route,
            $protocol,
            $this->locale($action->userId),
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
                'tg-trial-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':trial-home',
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

    private function renderCatalog(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramCustomerTrialCatalogPage $catalog,
        string $locale,
        ?string $preface = null,
    ): void {
        $rows = [];
        foreach ($catalog->items as $offset => $offering) {
            $callback = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_OFFERING_PREFIX.$offering->selectionToken,
                [],
                'tg-trial-item:'.hash('sha256', $action->requestKey.':'.($offset + 1)),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram_trial.offering_button', $locale, [
                    'number' => ($catalog->page - 1) * self::PAGE_SIZE + $offset + 1,
                ]),
                $callback->publicId,
            )];
        }

        $pagination = [];
        if ($catalog->page > 1) {
            $previous = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PAGE,
                ['page' => $catalog->page - 1],
                'tg-trial-previous:'.hash('sha256', $action->requestKey),
            );
            $pagination[] = new TelegramInlineCallbackButton($this->translation('telegram_trial.previous', $locale), $previous->publicId);
        }
        if ($catalog->page < $catalog->totalPages) {
            $next = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PAGE,
                ['page' => $catalog->page + 1],
                'tg-trial-next:'.hash('sha256', $action->requestKey),
            );
            $pagination[] = new TelegramInlineCallbackButton($this->translation('telegram_trial.next', $locale), $next->publicId);
        }
        if ($pagination !== []) {
            $rows[] = $pagination;
        }
        $this->appendBack($action, $sessionVersion, $rows, $locale, 'catalog');
        $text = $this->catalogText($catalog, $locale);
        if ($preface !== null) {
            $text = $preface."\n\n".$text;
        }
        $this->queueConfidential($action, $text, 'catalog', new TelegramInlineKeyboardSnapshot($rows));
    }

    private function renderOffering(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramCustomerTrialOffering $offering,
        string $locale,
    ): void {
        $claim = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_CLAIM,
            [],
            'tg-trial-claim-start:'.hash('sha256', $action->requestKey),
        );
        $rows = [[new TelegramInlineCallbackButton(
            $this->translation('telegram_trial.start_claim', $locale),
            $claim->publicId,
            TelegramInlineButtonStyle::Primary,
        )]];
        $this->appendBack($action, $sessionVersion, $rows, $locale, 'offering');
        $this->queueConfidential(
            $action,
            $this->translation('telegram_trial.detail', $locale, [
                'category' => $this->localizedLabel($offering->categoryNameFa, $offering->categoryNameEn, $locale),
                'plan' => $this->planLabel($offering, $locale),
                'mode' => $this->localizedLabel($offering->serviceModeLabelFa, $offering->serviceModeLabelEn, $locale),
                'data' => $this->formatBytes($offering->trialDataBytes),
                'duration' => $offering->trialDurationDays,
                'phone' => $this->translation('telegram_trial.phone.'.$offering->phoneVerificationPolicy, $locale),
                'membership' => $this->translation('telegram_trial.membership.'.($offering->membershipRequired ? 'required' : 'not_required'), $locale),
            ]),
            'offering',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderRouteSelection(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramCustomerTrialClaimOptions $options,
        string $locale,
    ): void {
        $rows = [];
        if ($options->serverSelectionMode === 'hybrid') {
            $auto = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_ROUTE_AUTO,
                [],
                'tg-trial-route-auto:'.hash('sha256', $action->requestKey),
            );
            $rows[] = [new TelegramInlineCallbackButton($this->translation('telegram_trial.route_auto', $locale), $auto->publicId)];
        }
        foreach ($options->routeOptions as $index => $option) {
            $callback = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_ROUTE_PREFIX.$option->selectionToken,
                [],
                'tg-trial-route-option:'.hash('sha256', $action->requestKey.':'.$index),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->localizedLabel($option->nameFa, $option->nameEn, $locale),
                $callback->publicId,
            )];
        }
        $this->appendBack($action, $sessionVersion, $rows, $locale, 'route');
        $this->queueConfidential(
            $action,
            $this->translation('telegram_trial.route_prompt', $locale),
            'route',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderProtocolSelection(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramCustomerTrialClaimOptions $options,
        string $locale,
    ): void {
        $rows = [];
        foreach ($options->protocolOptions as $index => $option) {
            $callback = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PROTOCOL_PREFIX.$option->selectionToken,
                [],
                'tg-trial-protocol-option:'.hash('sha256', $action->requestKey.':'.$index),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->localizedLabel($option->nameFa, $option->nameEn, $locale),
                $callback->publicId,
            )];
        }
        $this->appendBack($action, $sessionVersion, $rows, $locale, 'protocol');
        $this->queueConfidential(
            $action,
            $this->translation('telegram_trial.protocol_prompt', $locale),
            'protocol',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderConfirmation(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramCustomerTrialOffering $offering,
        TelegramCustomerTrialClaimOptions $options,
        ?string $route,
        ?string $protocol,
        string $locale,
    ): void {
        $confirm = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_CONFIRM,
            [],
            'tg-trial-confirm:'.hash('sha256', $action->requestKey),
        );
        $rows = [[new TelegramInlineCallbackButton(
            $this->translation('telegram_trial.confirm_button', $locale),
            $confirm->publicId,
            TelegramInlineButtonStyle::Primary,
        )]];
        $this->appendBack($action, $sessionVersion, $rows, $locale, 'confirm');
        $this->queueConfidential(
            $action,
            $this->translation('telegram_trial.confirm', $locale, [
                'plan' => $this->planLabel($offering, $locale),
                'data' => $this->formatBytes($offering->trialDataBytes),
                'duration' => $offering->trialDurationDays,
                'server' => $this->selectedRouteLabel($options, $route, $locale),
                'protocol' => $this->selectedProtocolLabel($options, $protocol, $locale),
                'fallback' => $this->fallbackText($options, $locale),
            ]),
            'confirm',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    /** @param array<string,mixed> $state */
    private function renderQueuedState(
        TelegramInteractionAction $action,
        int $sessionVersion,
        array $state,
        string $locale,
    ): void {
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-trial-queued-back:'.hash('sha256', $state['operation_key']),
        );
        $fallback = $state['fallback_used']
            ? ($locale === 'en' && $state['fallback_disclosure_en'] !== null
                ? $state['fallback_disclosure_en']
                : ($state['fallback_disclosure_fa'] ?? $this->translation('telegram_trial.fallback.used', $locale)))
            : $this->translation('telegram_trial.fallback.not_used', $locale);
        $this->queueConfidential(
            $action,
            $this->translation('telegram_trial.queued', $locale, [
                'service' => $state['service_public_id'],
                'data' => $this->formatBytes($state['data_bytes']),
                'duration' => $state['duration_days'],
                'server' => $this->localizedLabel($state['server_name_fa'], $state['server_name_en'], $locale),
                'protocol' => $this->localizedLabel($state['protocol_name_fa'], $state['protocol_name_en'], $locale),
                'fallback' => $fallback,
            ]),
            'queued',
            new TelegramInlineKeyboardSnapshot([[new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            )]]),
        );
    }

    private function catalogText(TelegramCustomerTrialCatalogPage $catalog, string $locale): string
    {
        if ($catalog->items === []) {
            return $this->translation('telegram_trial.empty', $locale);
        }
        $lines = [];
        foreach ($catalog->items as $offset => $offering) {
            $lines[] = $this->translation('telegram_trial.list_item', $locale, [
                'number' => ($catalog->page - 1) * self::PAGE_SIZE + $offset + 1,
                'plan' => $this->planLabel($offering, $locale),
                'data' => $this->formatBytes($offering->trialDataBytes),
                'duration' => $offering->trialDurationDays,
            ]);
        }

        return $this->translation('telegram_trial.list', $locale, [
            'items' => implode("\n\n", $lines),
            'page' => $catalog->page,
            'total_pages' => $catalog->totalPages,
            'total_items' => $catalog->totalItems,
        ]);
    }

    private function selectedRouteLabel(TelegramCustomerTrialClaimOptions $options, ?string $token, string $locale): string
    {
        if ($token === null) {
            return $this->translation('telegram_trial.route_auto', $locale);
        }
        foreach ($options->routeOptions as $option) {
            if (hash_equals($option->selectionToken, $token)) {
                return $this->localizedLabel($option->nameFa, $option->nameEn, $locale);
            }
        }
        throw new AuthorizationException('Telegram Trial selected route is stale.');
    }

    private function selectedProtocolLabel(TelegramCustomerTrialClaimOptions $options, ?string $token, string $locale): string
    {
        if ($token === null) {
            return $this->translation('telegram_trial.protocol_auto', $locale);
        }
        foreach ($options->protocolOptions as $option) {
            if (hash_equals($option->selectionToken, $token)) {
                return $this->localizedLabel($option->nameFa, $option->nameEn, $locale);
            }
        }
        throw new AuthorizationException('Telegram Trial selected protocol is stale.');
    }

    private function fallbackText(TelegramCustomerTrialClaimOptions $options, string $locale): string
    {
        if (! $options->fallbackAllowed) {
            return $this->translation('telegram_trial.fallback.not_allowed', $locale);
        }
        if ($options->fallbackDisclosures === []) {
            return $this->translation('telegram_trial.fallback.allowed', $locale);
        }
        $items = [];
        foreach ($options->fallbackDisclosures as $item) {
            $server = $this->localizedLabel($item->serverNameFa, $item->serverNameEn, $locale);
            $disclosure = $locale === 'en' && $item->disclosureEn !== null ? $item->disclosureEn : $item->disclosureFa;
            $items[] = $server.': '.$disclosure;
        }

        return $this->translation('telegram_trial.fallback.allowed_with_details', $locale, [
            'details' => implode(' | ', $items),
        ]);
    }

    private function assertChosenOptions(
        TelegramCustomerTrialClaimOptions $options,
        ?string $route,
        ?string $protocol,
    ): void {
        if ($options->serverSelectionMode === 'customer_selects' && ($route === null || ! $this->containsRouteOption($options, $route))) {
            throw new AuthorizationException('Telegram Trial route selection is required.');
        }
        if ($options->serverSelectionMode === 'system_selects' && $route !== null) {
            throw new AuthorizationException('Telegram Trial route selection is not allowed.');
        }
        if ($route !== null && ! $this->containsRouteOption($options, $route)) {
            throw new AuthorizationException('Telegram Trial route selection is unavailable.');
        }
        if ($options->protocolSelectionMode === 'customer_selects' && ($protocol === null || ! $this->containsProtocolOption($options, $protocol))) {
            throw new AuthorizationException('Telegram Trial protocol selection is required.');
        }
        if ($options->protocolSelectionMode !== 'customer_selects' && $protocol !== null) {
            throw new AuthorizationException('Telegram Trial protocol selection is not allowed.');
        }
    }

    private function containsRouteOption(TelegramCustomerTrialClaimOptions $options, string $token): bool
    {
        foreach ($options->routeOptions as $option) {
            if (hash_equals($option->selectionToken, $token)) {
                return true;
            }
        }

        return false;
    }

    private function containsProtocolOption(TelegramCustomerTrialClaimOptions $options, string $token): bool
    {
        foreach ($options->protocolOptions as $option) {
            if (hash_equals($option->selectionToken, $token)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<list<TelegramInlineCallbackButton>> $rows */
    private function appendBack(
        TelegramInteractionAction $action,
        int $sessionVersion,
        array &$rows,
        string $locale,
        string $surface,
    ): void {
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-trial-back:'.hash('sha256', $action->requestKey.':'.$surface),
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
            'tg-trial-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-trial:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
            $keyboard,
        );
    }

    private function recoverAdvancedClaim(TelegramInteractionAction $action): bool
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
            if (! hash_equals($state['operation_key'], $operationKey)) {
                return true;
            }
            $this->completeSubmitting($action, $session->version, $session->payload);

            return true;
        }
        if ($session->state === self::STATE_QUEUED) {
            $state = $this->queuedState($session->payload);
            if (hash_equals($state['operation_key'], $operationKey)) {
                $this->renderQueuedState($action, $session->version, $state, $this->locale($action->userId));
            }

            return true;
        }

        return false;
    }

    private function assertActiveCustomer(int $userId): void
    {
        $summary = $this->customers->forSelf($userId, $userId);
        if ($summary->accountType !== 'customer' || $summary->accountStatus !== 'active') {
            throw new AuthorizationException('Telegram Trial journey requires an active customer account.');
        }
    }

    private function locale(int $userId): string
    {
        $summary = $this->customers->forSelf($userId, $userId);

        return $summary->locale === 'en' ? 'en' : 'fa';
    }

    private function planLabel(TelegramCustomerTrialOffering $offering, string $locale): string
    {
        $product = $this->localizedLabel($offering->productNameFa, $offering->productNameEn, $locale);
        $variant = $locale === 'en' ? $offering->variantNameEn : $offering->variantNameFa;
        if ($variant === null) {
            $variant = $offering->variantNameFa ?? $offering->variantNameEn;
        }

        return $variant === null ? $product : $product.' — '.$variant;
    }

    private function localizedLabel(string $fa, ?string $en, string $locale): string
    {
        return $locale === 'en' && $en !== null ? $en : $fa;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1) {
            throw new RuntimeException('Telegram Trial byte value is invalid.');
        }
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, '.', '').' KiB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, '.', '').' MiB';
        }

        return number_format($bytes / (1024 * 1024 * 1024), 2, '.', '').' GiB';
    }

    /** @param array<string,mixed> $payload */
    private function catalogState(array $payload): int
    {
        if (array_keys($payload) !== ['page'] || ! is_int($payload['page']) || $payload['page'] < 1) {
            throw new RuntimeException('Telegram Trial catalog state is invalid.');
        }

        return $payload['page'];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return OfferingState
     */
    private function offeringState(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['page', 'selection']
            || ! is_int($payload['page'] ?? null)
            || $payload['page'] < 1
            || ! $this->validSelectionToken($payload['selection'] ?? null)) {
            throw new RuntimeException('Telegram Trial offering state is invalid.');
        }

        return ['page' => $payload['page'], 'selection' => $payload['selection']];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return ProtocolState
     */
    private function protocolState(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['page', 'route', 'selection']
            || ! is_int($payload['page'] ?? null)
            || $payload['page'] < 1
            || ! $this->validSelectionToken($payload['selection'] ?? null)
            || ! $this->validOptionalSelectionToken($payload['route'] ?? null)) {
            throw new RuntimeException('Telegram Trial protocol state is invalid.');
        }

        return ['page' => $payload['page'], 'selection' => $payload['selection'], 'route' => $payload['route']];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return ConfirmationState
     */
    private function confirmationState(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['page', 'protocol', 'route', 'selection']
            || ! is_int($payload['page'] ?? null)
            || $payload['page'] < 1
            || ! $this->validSelectionToken($payload['selection'] ?? null)
            || ! $this->validOptionalSelectionToken($payload['route'] ?? null)
            || ! $this->validOptionalSelectionToken($payload['protocol'] ?? null)) {
            throw new RuntimeException('Telegram Trial confirmation state is invalid.');
        }

        return [
            'page' => $payload['page'],
            'selection' => $payload['selection'],
            'route' => $payload['route'],
            'protocol' => $payload['protocol'],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return SubmittingState
     */
    private function submittingState(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['accepted_at', 'cancel_locked', 'expiry_locked', 'operation_key', 'page', 'protocol', 'route', 'selection']
            || ! is_int($payload['page'] ?? null)
            || $payload['page'] < 1
            || ! $this->validSelectionToken($payload['selection'] ?? null)
            || ! $this->validOptionalSelectionToken($payload['route'] ?? null)
            || ! $this->validOptionalSelectionToken($payload['protocol'] ?? null)
            || ! is_string($payload['operation_key'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['operation_key']) !== 1
            || ! is_string($payload['accepted_at'] ?? null)
            || ($payload['cancel_locked'] ?? null) !== true
            || ($payload['expiry_locked'] ?? null) !== true) {
            throw new RuntimeException('Telegram Trial submitting state is invalid.');
        }
        try {
            new DateTimeImmutable($payload['accepted_at']);
        } catch (\Exception $exception) {
            throw new RuntimeException('Telegram Trial submitting timestamp is invalid.', previous: $exception);
        }

        return [
            'page' => $payload['page'],
            'selection' => $payload['selection'],
            'route' => $payload['route'],
            'protocol' => $payload['protocol'],
            'operation_key' => $payload['operation_key'],
            'accepted_at' => $payload['accepted_at'],
            'cancel_locked' => true,
            'expiry_locked' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return QueuedState
     */
    private function queuedState(array $payload): array
    {
        $expected = [
            'data_bytes', 'duration_days', 'fallback_disclosure_en', 'fallback_disclosure_fa', 'fallback_used',
            'operation_key', 'order_public_id', 'protocol_name_en', 'protocol_name_fa', 'provisioning_public_id',
            'server_name_en', 'server_name_fa', 'service_public_id',
        ];
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== $expected
            || ! is_string($payload['operation_key'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['operation_key']) !== 1
            || ! $this->validUlid($payload['order_public_id'] ?? null)
            || ! $this->validUlid($payload['service_public_id'] ?? null)
            || ! $this->validUlid($payload['provisioning_public_id'] ?? null)
            || ! is_int($payload['data_bytes'] ?? null) || $payload['data_bytes'] < 1
            || ! is_int($payload['duration_days'] ?? null) || $payload['duration_days'] < 1
            || ! is_string($payload['server_name_fa'] ?? null) || $payload['server_name_fa'] === ''
            || ! is_string($payload['protocol_name_fa'] ?? null) || $payload['protocol_name_fa'] === ''
            || ! $this->validOptionalLabel($payload['server_name_en'] ?? null)
            || ! $this->validOptionalLabel($payload['protocol_name_en'] ?? null)
            || ! is_bool($payload['fallback_used'] ?? null)
            || ! $this->validOptionalLabel($payload['fallback_disclosure_fa'] ?? null)
            || ! $this->validOptionalLabel($payload['fallback_disclosure_en'] ?? null)) {
            throw new RuntimeException('Telegram Trial queued state is invalid.');
        }

        return [
            'operation_key' => $payload['operation_key'],
            'order_public_id' => $payload['order_public_id'],
            'service_public_id' => $payload['service_public_id'],
            'provisioning_public_id' => $payload['provisioning_public_id'],
            'data_bytes' => $payload['data_bytes'],
            'duration_days' => $payload['duration_days'],
            'server_name_fa' => $payload['server_name_fa'],
            'server_name_en' => $payload['server_name_en'],
            'protocol_name_fa' => $payload['protocol_name_fa'],
            'protocol_name_en' => $payload['protocol_name_en'],
            'fallback_used' => $payload['fallback_used'],
            'fallback_disclosure_fa' => $payload['fallback_disclosure_fa'],
            'fallback_disclosure_en' => $payload['fallback_disclosure_en'],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function pageFromCallback(array $payload): int
    {
        if (array_keys($payload) !== ['page'] || ! is_int($payload['page']) || $payload['page'] < 1) {
            throw new RuntimeException('Telegram Trial page callback is invalid.');
        }

        return $payload['page'];
    }

    /** @param array<string,mixed> $payload */
    private function selectionFromAction(?string $action, array $payload): ?string
    {
        if ($action === null || $payload !== []) {
            return null;
        }
        if (preg_match('/\Anavigation\.trial\.([0-9a-f]{40})\z/', $action, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function optionTokenFromAction(?string $action, string $prefix): ?string
    {
        if ($action === null || ! str_starts_with($action, $prefix)) {
            return null;
        }
        $token = substr($action, strlen($prefix));

        return preg_match('/\A[0-9a-f]{40}\z/', $token) === 1 ? $token : null;
    }

    private function validSelectionToken(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{40}\z/', $value) === 1;
    }

    private function validOptionalSelectionToken(mixed $value): bool
    {
        return $value === null || $this->validSelectionToken($value);
    }

    private function validUlid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) === 1;
    }

    private function validOptionalLabel(mixed $value): bool
    {
        return $value === null || (is_string($value) && $value !== '' && mb_check_encoding($value, 'UTF-8'));
    }

    private function sessionAdvancedPast(TelegramInteractionAction $action): bool
    {
        $session = $this->sessions->activeForAccount($action->telegramAccountId);

        return $session !== null
            && $session->publicId === $action->sessionPublicId
            && $session->userId === $action->userId
            && $session->version > $action->sessionVersion;
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->translator->get($key, $replace, $locale);
        if (! is_string($value) || $value === '' || $value === $key) {
            $value = $this->translator->get($key, $replace, 'en');
        }
        if (! is_string($value) || $value === '' || $value === $key) {
            throw new RuntimeException('Telegram Trial translation is unavailable.');
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram Trial translation has an unresolved placeholder.');
        }

        return $value;
    }

    private function assertActor(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new RuntimeException('Telegram Trial session actor binding is invalid.');
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

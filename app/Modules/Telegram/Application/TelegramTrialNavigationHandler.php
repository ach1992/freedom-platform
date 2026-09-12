<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerTrialCatalog;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Translation\Translator;
use RuntimeException;

/**
 * Read-only customer Trial discovery. Trial claim/capacity/order/provisioning
 * authority deliberately remains outside this handler.
 */
final readonly class TelegramTrialNavigationHandler
{
    private const STATE_CATALOG = 'trial_catalog';

    private const STATE_OFFERING = 'trial_offering';

    private const ACTION_TRIAL = 'navigation.trial';

    private const ACTION_PAGE = 'navigation.trial.page';

    private const ACTION_OFFERING_PREFIX = 'navigation.trial.';

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
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === TelegramNavigationEntryGateway::STATE
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_TRIAL)
            || in_array($action->sessionState, [self::STATE_CATALOG, self::STATE_OFFERING], true);
    }

    public function handle(TelegramInteractionAction $action): void
    {
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

        if ($action->sessionState === self::STATE_CATALOG) {
            $this->handleCatalog($action);

            return;
        }

        if ($action->sessionState === self::STATE_OFFERING) {
            $this->handleOffering($action);

            return;
        }

        throw new RuntimeException('Telegram Trial navigation state is unsupported.');
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
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Trial offering callback action is unsupported.');
            }
            $this->showCatalog($action, $state['page']);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showCatalog($action, $state['page']);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
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
        $page = $this->catalogState($action->sessionPayload);
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
            $pagination[] = new TelegramInlineCallbackButton(
                $this->translation('telegram_trial.previous', $locale),
                $previous->publicId,
            );
        }
        if ($catalog->page < $catalog->totalPages) {
            $next = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PAGE,
                ['page' => $catalog->page + 1],
                'tg-trial-next:'.hash('sha256', $action->requestKey),
            );
            $pagination[] = new TelegramInlineCallbackButton(
                $this->translation('telegram_trial.next', $locale),
                $next->publicId,
            );
        }
        if ($pagination !== []) {
            $rows[] = $pagination;
        }
        $this->appendBack($action, $sessionVersion, $rows, $locale, 'catalog');

        $this->queueConfidential(
            $action,
            $this->catalogText($catalog, $locale),
            'catalog',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderOffering(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramCustomerTrialOffering $offering,
        string $locale,
    ): void {
        $rows = [];
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
     * @return array{page:int,selection:string}
     */
    private function offeringState(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['page', 'selection']
            || ! is_int($payload['page'] ?? null)
            || $payload['page'] < 1
            || ! is_string($payload['selection'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $payload['selection']) !== 1) {
            throw new RuntimeException('Telegram Trial offering state is invalid.');
        }

        return ['page' => $payload['page'], 'selection' => $payload['selection']];
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

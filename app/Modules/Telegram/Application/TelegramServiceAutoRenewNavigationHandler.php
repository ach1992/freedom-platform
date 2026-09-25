<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceAutoRenewManager;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceProjection;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/** @requirement SVC-007 ACL-002 DAT-002 DAT-003 SEC-002 SEC-003 QUA-001 QUA-004 */
final readonly class TelegramServiceAutoRenewNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.service.auto_renew';

    private const STATE_OVERVIEW = 'service_auto_renew';

    private const STATE_CONFIRM = 'service_auto_renew_confirm';

    private const STATE_RESULT = 'service_auto_renew_result';

    private const ACTION_SELECT = 'navigation.service.auto_renew.select';

    private const ACTION_DISABLE = 'navigation.service.auto_renew.disable';

    private const ACTION_CONFIRM = 'navigation.service.auto_renew.confirm';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramOwnedServiceProjection $services,
        private TelegramOwnedServiceAutoRenewManager $autoRenew,
        private CustomerAccountSummaryService $customers,
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === 'service_detail'
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_ENTRY)
            || in_array($action->sessionState, [self::STATE_OVERVIEW, self::STATE_CONFIRM, self::STATE_RESULT], true);
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'service_detail') {
            if (array_keys($action->callbackPayload) !== ['service_selection']) {
                throw new RuntimeException('Telegram Service auto-renew entry payload is invalid.');
            }
            $selection = $this->selection($action->callbackPayload['service_selection'] ?? null);
            $this->showOverview($action, $selection, $this->page($action->sessionPayload));

            return;
        }

        match ($action->sessionState) {
            self::STATE_OVERVIEW => $this->handleOverview($action),
            self::STATE_CONFIRM => $this->handleConfirm($action),
            self::STATE_RESULT => $this->handleResult($action),
            default => throw new RuntimeException('Telegram Service auto-renew state is unsupported.'),
        };
    }

    private function handleOverview(TelegramInteractionAction $action): void
    {
        [$page, $selection] = $this->overviewState($action->sessionPayload);
        if ($this->isBack($action)) {
            $this->navigation->showOwnedServiceDetailForPage($action, $selection, $page);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback) {
            if ($this->isEntryCommand($action->messageText)) {
                $this->navigation->returnHomeFromExtension($action);
            }

            return;
        }

        $detail = $this->currentDetail($action, $selection);
        $snapshot = $this->autoRenew->snapshotForSelf($action->userId, $detail->publicId);
        if ($action->callbackAction === self::ACTION_SELECT) {
            if (array_keys($action->callbackPayload) !== ['package'] || ! is_string($action->callbackPayload['package'] ?? null)) {
                throw new RuntimeException('Telegram Service auto-renew package payload is invalid.');
            }
            $package = $snapshot->package($action->callbackPayload['package']);
            if ($package === null) {
                $this->showOverview($action, $selection, $page);

                return;
            }
            $this->showConfirmation($action, $selection, $page, $package->code, true);

            return;
        }
        if ($action->callbackAction === self::ACTION_DISABLE && $action->callbackPayload === []) {
            if (! $snapshot->enabled || $snapshot->configuredPackageCode === null) {
                $this->showOverview($action, $selection, $page);

                return;
            }
            $this->showConfirmation($action, $selection, $page, $snapshot->configuredPackageCode, false);

            return;
        }

        throw new RuntimeException('Telegram Service auto-renew overview action is unsupported.');
    }

    private function handleConfirm(TelegramInteractionAction $action): void
    {
        [$page, $selection, $packageCode, $enabled] = $this->confirmState($action->sessionPayload);
        if ($this->isBack($action)) {
            $this->showOverview($action, $selection, $page);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback
            || $action->callbackAction !== self::ACTION_CONFIRM
            || $action->callbackPayload !== []) {
            throw new RuntimeException('Telegram Service auto-renew confirmation action is unsupported.');
        }

        try {
            $detail = $this->currentDetail($action, $selection);
            $callbackId = $this->callbackPublicId($action);
            $result = $this->autoRenew->configureForSelf(
                $action->userId,
                $detail->publicId,
                $packageCode,
                $enabled,
                'tg-service-auto-'.substr(hash('sha256', $callbackId), 0, 48),
                'tg-service-auto-'.substr(hash('sha256', $callbackId.':correlation'), 0, 40),
            );
        } catch (AuthorizationException|DomainException) {
            $this->showOverview($action, $selection, $page, true);

            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_RESULT,
            ['page' => $page, 'service_selection' => $selection],
            'result',
        );
        if ($session === null) {
            return;
        }
        $this->renderResult($action, $session->version, $selection, $page, $result);
    }

    private function handleResult(TelegramInteractionAction $action): void
    {
        [$page, $selection] = $this->overviewState($action->sessionPayload);
        if ($this->isBack($action)) {
            $this->navigation->showOwnedServiceDetailForPage($action, $selection, $page);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->navigation->returnHomeFromExtension($action);
        }
    }

    private function showOverview(
        TelegramInteractionAction $action,
        string $selection,
        int $page,
        bool $stale = false,
    ): void {
        try {
            $detail = $this->currentDetail($action, $selection);
            if (! $detail->autoRenewAvailable) {
                throw new DomainException('Telegram Service auto-renew is unavailable.');
            }
            $snapshot = $this->autoRenew->snapshotForSelf($action->userId, $detail->publicId);
        } catch (AuthorizationException|DomainException) {
            $this->navigation->showOwnedServiceDetailForPage($action, $selection, $page);

            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_OVERVIEW,
            ['page' => $page, 'service_selection' => $selection],
            'overview',
        );
        if ($session === null) {
            return;
        }

        $locale = $this->locale($action->userId);
        $rows = [];
        foreach ($snapshot->packages as $index => $package) {
            $callback = $this->callbacks->issue(
                $session->publicId,
                $session->version,
                self::ACTION_SELECT,
                ['package' => $package->code],
                'tg-service-auto-package:'.hash('sha256', $action->requestKey.':'.$package->code.':'.$index),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.services.auto_renew.package_button', $locale, [
                    'name' => $locale === 'en' && $package->nameEn !== null ? $package->nameEn : $package->nameFa,
                    'price' => number_format($package->priceIrr),
                ]),
                $callback->publicId,
                $snapshot->enabled && $snapshot->configuredPackageCode === $package->code
                    ? TelegramInlineButtonStyle::Success
                    : TelegramInlineButtonStyle::Primary,
            )];
        }
        if ($snapshot->enabled) {
            $disable = $this->callbacks->issue(
                $session->publicId,
                $session->version,
                self::ACTION_DISABLE,
                [],
                'tg-service-auto-disable:'.hash('sha256', $action->requestKey),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.services.auto_renew.disable_button', $locale),
                $disable->publicId,
                TelegramInlineButtonStyle::Danger,
            )];
        }
        $back = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_BACK,
            [],
            'tg-service-auto-back:'.hash('sha256', $action->requestKey),
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )];

        $status = $snapshot->enabled
            ? $this->translation('telegram.navigation.services.auto_renew.enabled', $locale, [
                'package' => (string) $snapshot->configuredPackageCode,
                'price' => number_format((int) $snapshot->acceptedPriceIrr),
            ])
            : $this->translation('telegram.navigation.services.auto_renew.disabled', $locale);
        $text = $this->translation('telegram.navigation.services.auto_renew.overview', $locale, [
            'status' => $status,
            'notice' => $stale ? $this->translation('telegram.navigation.services.auto_renew.stale', $locale) : '',
        ]);
        $this->queue($action, $text, 'overview', new TelegramInlineKeyboardSnapshot($rows));
    }

    private function showConfirmation(
        TelegramInteractionAction $action,
        string $selection,
        int $page,
        string $packageCode,
        bool $enabled,
    ): void {
        $detail = $this->currentDetail($action, $selection);
        $snapshot = $this->autoRenew->snapshotForSelf($action->userId, $detail->publicId);
        $package = $snapshot->package($packageCode);
        if ($package === null || (! $enabled && (! $snapshot->enabled || $snapshot->configuredPackageCode !== $packageCode))) {
            $this->showOverview($action, $selection, $page, true);

            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_CONFIRM,
            ['page' => $page, 'service_selection' => $selection, 'package' => $packageCode, 'enabled' => $enabled],
            'confirm',
        );
        if ($session === null) {
            return;
        }
        $confirm = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_CONFIRM,
            [],
            'tg-service-auto-confirm:'.hash('sha256', $action->requestKey.':'.$packageCode.':'.($enabled ? '1' : '0')),
        );
        $back = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_BACK,
            [],
            'tg-service-auto-confirm-back:'.hash('sha256', $action->requestKey),
        );
        $locale = $this->locale($action->userId);
        $this->queue(
            $action,
            $this->translation(
                $enabled
                    ? 'telegram.navigation.services.auto_renew.confirm_enable'
                    : 'telegram.navigation.services.auto_renew.confirm_disable',
                $locale,
                [
                    'name' => $locale === 'en' && $package->nameEn !== null ? $package->nameEn : $package->nameFa,
                    'price' => number_format($package->priceIrr),
                ],
            ),
            'confirm',
            new TelegramInlineKeyboardSnapshot([
                [new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.services.auto_renew.confirm_button', $locale),
                    $confirm->publicId,
                    $enabled ? TelegramInlineButtonStyle::Success : TelegramInlineButtonStyle::Danger,
                )],
                [new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.buttons.back', $locale),
                    $back->publicId,
                )],
            ]),
        );
    }

    private function renderResult(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $selection,
        int $page,
        TelegramOwnedServiceAutoRenewResult $result,
    ): void {
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-service-auto-result-back:'.hash('sha256', $action->requestKey),
        );
        $locale = $this->locale($action->userId);
        $this->queue(
            $action,
            $this->translation(
                $result->enabled
                    ? 'telegram.navigation.services.auto_renew.result_enabled'
                    : 'telegram.navigation.services.auto_renew.result_disabled',
                $locale,
                ['package' => $result->packageCode, 'price' => number_format($result->acceptedPriceIrr)],
            ),
            'result',
            new TelegramInlineKeyboardSnapshot([[
                new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.buttons.back', $locale),
                    $back->publicId,
                ),
            ]]),
        );
    }

    private function currentDetail(TelegramInteractionAction $action, string $selection): TelegramOwnedServiceDetail
    {
        return $this->services->detailForSelf($action->userId, $action->userId, $selection);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{int,string}
     */
    private function overviewState(array $payload): array
    {
        if (array_keys($payload) !== ['page', 'service_selection']) {
            throw new RuntimeException('Stored Telegram Service auto-renew state is invalid.');
        }

        return [$this->page($payload), $this->selection($payload['service_selection'] ?? null)];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{int,string,string,bool}
     */
    private function confirmState(array $payload): array
    {
        if (! array_key_exists('page', $payload)
            || ! array_key_exists('service_selection', $payload)
            || ! array_key_exists('package', $payload)
            || ! array_key_exists('enabled', $payload)
            || count($payload) !== 4
            || ! is_string($payload['package'])
            || preg_match('/\A[a-z0-9][a-z0-9._-]{1,63}\z/', $payload['package']) !== 1
            || ! is_bool($payload['enabled'])) {
            throw new RuntimeException('Stored Telegram Service auto-renew confirmation state is invalid.');
        }

        return [
            $this->page($payload),
            $this->selection($payload['service_selection']),
            $payload['package'],
            $payload['enabled'],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function page(array $payload): int
    {
        $page = filter_var($payload['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page === false) {
            throw new RuntimeException('Telegram Service auto-renew page is invalid.');
        }

        return (int) $page;
    }

    private function selection(mixed $selection): string
    {
        if (! is_string($selection) || preg_match('/\A[0-9a-f]{40}\z/', $selection) !== 1) {
            throw new RuntimeException('Telegram Service auto-renew selection is invalid.');
        }

        return $selection;
    }

    /** @param array<string,mixed> $payload */
    private function transition(TelegramInteractionAction $action, string $state, array $payload, string $surface): ?TelegramInteractionSessionReceipt
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                $state,
                $payload,
                'tg-service-auto-transition:'.hash('sha256', $action->requestKey.':'.$surface),
            );
        } catch (DomainException) {
            return null;
        }
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram Service auto-renew actor binding changed.');
        }

        return $session;
    }

    private function queue(TelegramInteractionAction $action, string $text, string $surface, TelegramInlineKeyboardSnapshot $keyboard): void
    {
        $source = new readonly class($text) implements ConfidentialTelegramPresentationSource
        {
            public function __construct(private string $text) {}

            public function confidentialTelegramText(): string
            {
                return $this->text;
            }
        };
        $this->delivery->send(
            $action->telegramUserId,
            $this->presentations->fromSource($source),
            'tg-service-auto-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-service-auto:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 40),
            $keyboard,
        );
    }

    private function callbackPublicId(TelegramInteractionAction $action): string
    {
        if ($action->callbackPublicId === null || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $action->callbackPublicId) !== 1) {
            throw new RuntimeException('Telegram Service auto-renew callback identity is invalid.');
        }

        return $action->callbackPublicId;
    }

    private function isBack(TelegramInteractionAction $action): bool
    {
        return $action->kind === TelegramInteractionActionKind::Back
            || ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_BACK
                && $action->callbackPayload === []);
    }

    private function locale(int $userId): string
    {
        return $this->customers->forSelf($userId, $userId)->locale === 'en' ? 'en' : 'fa';
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === '' || $value === '['.$key.']' || preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram Service auto-renew translation is unavailable.');
        }

        return $value;
    }

    private function isEntryCommand(?string $text): bool
    {
        if ($text === null) {
            return false;
        }
        $text = trim($text);

        return preg_match('/\A\/menu(?:@[A-Za-z0-9_]+)?\z/u', $text) === 1
            || preg_match('/\A\/start(?:@[A-Za-z0-9_]+)?(?:\s+[A-Za-z0-9_-]{1,64})?\z/u', $text) === 1;
    }
}

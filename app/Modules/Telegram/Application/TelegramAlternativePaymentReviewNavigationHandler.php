<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramAlternativePaymentReview;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramAlternativePaymentReviewNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.admin.payment_reviews';

    private const ACTION_SELECT = 'navigation.admin.payment_reviews.select';

    private const ACTION_APPROVE = 'navigation.admin.payment_reviews.approve';

    private const ACTION_REJECT = 'navigation.admin.payment_reviews.reject';

    private const ACTION_REFRESH = 'navigation.admin.payment_reviews.refresh';

    private const ACTION_BACK = 'navigation.back';

    private const STATE_LIST = 'admin_payment_reviews';

    private const STATE_DETAIL = 'admin_payment_review_detail';

    private const STATE_INPUT = 'admin_payment_review_input';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramAlternativePaymentReview $reviews,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === 'admin_control'
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_ENTRY)
            || in_array($action->sessionState, [self::STATE_LIST, self::STATE_DETAIL, self::STATE_INPUT], true);
    }

    /** @requirement C2C-002 C2C-005 GFT-002 GFT-004 USDT-003 ACL-002 PAY-002 PAY-003 SEC-002 SEC-003 DAT-003 QUA-004 */
    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'admin_control') {
            if ($action->kind !== TelegramInteractionActionKind::Callback
                || $action->callbackAction !== self::ACTION_ENTRY
                || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram alternative-payment review entry is invalid.');
            }
            $this->showList($action);

            return;
        }

        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }

        if ($action->sessionState === self::STATE_LIST) {
            $this->handleList($action);

            return;
        }
        if ($action->sessionState === self::STATE_DETAIL) {
            $this->handleDetail($action);

            return;
        }
        if ($action->sessionState === self::STATE_INPUT) {
            $this->handleInput($action);

            return;
        }

        throw new RuntimeException('Telegram alternative-payment review state is unsupported.');
    }

    private function handleList(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->navigation->showAdminControl($action);

                return;
            }
            if ($action->callbackAction === self::ACTION_REFRESH && $action->callbackPayload === []) {
                $this->renderList($action, $action->sessionVersion, null);

                return;
            }
            if ($action->callbackAction === self::ACTION_SELECT) {
                [$kind, $reviewPublicId] = $this->selection($action->callbackPayload);
                $this->showDetail($action, $kind, $reviewPublicId);

                return;
            }

            throw new RuntimeException('Telegram alternative-payment review list callback is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->navigation->showAdminControl($action);
        }
    }

    private function handleDetail(TelegramInteractionAction $action): void
    {
        [$kind, $reviewPublicId] = $this->selection($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnList($action);

                return;
            }
            if ($action->callbackAction === self::ACTION_REJECT && $action->callbackPayload === []) {
                $this->beginInput($action, $kind, $reviewPublicId, 'reject', null);

                return;
            }
            if ($action->callbackAction === self::ACTION_APPROVE) {
                $reservation = null;
                if ($kind === 'c2c') {
                    $candidate = $action->callbackPayload['reservation'] ?? null;
                    if (! is_string($candidate)) {
                        throw new RuntimeException('Telegram C2C review approval candidate is missing.');
                    }
                    $reservation = strtoupper($candidate);
                } elseif ($action->callbackPayload !== []) {
                    throw new RuntimeException('Telegram alternative-payment approval payload is invalid.');
                }
                $this->beginInput($action, $kind, $reviewPublicId, 'approve', $reservation);

                return;
            }

            throw new RuntimeException('Telegram alternative-payment review detail callback is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnList($action);
        }
    }

    private function handleInput(TelegramInteractionAction $action): void
    {
        $payload = $action->sessionPayload;
        [$kind, $reviewPublicId] = $this->selection($payload);
        $mode = $payload['mode'] ?? null;
        if (! is_string($mode) || ! in_array($mode, ['approve', 'reject'], true)) {
            throw new RuntimeException('Telegram alternative-payment review input mode is invalid.');
        }

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnDetail($action, $kind, $reviewPublicId);

                return;
            }

            throw new RuntimeException('Telegram alternative-payment review input callback is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnDetail($action, $kind, $reviewPublicId);

            return;
        }

        if ($action->messageText === null) {
            return;
        }

        try {
            if ($mode === 'reject') {
                $this->reviews->reject(
                    $action->userId,
                    $kind,
                    $reviewPublicId,
                    trim($action->messageText),
                    $action->requestKey,
                );
            } elseif ($kind === 'c2c') {
                $reservation = $payload['reservation'] ?? null;
                if (! is_string($reservation)) {
                    throw new DomainException('C2C review approval candidate is unavailable.');
                }
                $this->reviews->approveC2c(
                    $action->userId,
                    $reviewPublicId,
                    $reservation,
                    trim($action->messageText),
                    $action->requestKey,
                );
            } elseif ($kind === 'gift_card') {
                $parts = array_map('trim', explode('|', $action->messageText, 2));
                if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                    throw new DomainException('Gift-card manual approval input is invalid.');
                }
                $this->reviews->approveGiftCard(
                    $action->userId,
                    $reviewPublicId,
                    $parts[0],
                    $parts[1],
                    $action->requestKey,
                );
            } elseif ($kind === 'usdt') {
                $parts = array_map('trim', explode('|', $action->messageText, 3));
                $confirmations = $parts[0] ?? '';
                if (count($parts) !== 3 || ! ctype_digit($confirmations) || $parts[1] === '' || $parts[2] === '') {
                    throw new DomainException('USDT manual approval input is invalid.');
                }
                $this->reviews->approveUsdt(
                    $action->userId,
                    $reviewPublicId,
                    (int) $confirmations,
                    $parts[1],
                    $parts[2],
                    $action->requestKey,
                );
            } else {
                throw new RuntimeException('Telegram alternative-payment review approval kind is unsupported.');
            }
        } catch (AuthorizationException) {
            $this->navigation->showAdminControl($action);

            return;
        } catch (DomainException|RuntimeException) {
            $this->renderInput($action, $action->sessionVersion, $kind, $mode, true);

            return;
        }

        $this->completeDecision($action);
    }

    private function showList(TelegramInteractionAction $action): void
    {
        if (! $this->reviews->availableFor($action->userId)) {
            $this->navigation->showAdminControl($action);

            return;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_LIST,
                [],
                'tg-admin-payment-reviews-list:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderList($action, $session->version, null);
    }

    private function showDetail(TelegramInteractionAction $action, string $kind, string $reviewPublicId): void
    {
        try {
            $case = $this->reviews->find($action->userId, $kind, $reviewPublicId);
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_DETAIL,
                ['kind' => $kind, 'review' => $reviewPublicId],
                'tg-admin-payment-review-detail:'.hash('sha256', $action->requestKey),
            );
        } catch (AuthorizationException) {
            $this->navigation->showAdminControl($action);

            return;
        } catch (DomainException) {
            $this->renderList($action, $action->sessionVersion, 'stale');

            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderDetail($action, $session->version, $case);
    }

    private function beginInput(
        TelegramInteractionAction $action,
        string $kind,
        string $reviewPublicId,
        string $mode,
        ?string $reservationPublicId,
    ): void {
        try {
            $case = $this->reviews->find($action->userId, $kind, $reviewPublicId);
        } catch (AuthorizationException) {
            $this->navigation->showAdminControl($action);

            return;
        } catch (DomainException) {
            $this->returnList($action);

            return;
        }

        if ($kind === 'c2c') {
            if ($reservationPublicId === null
                || ! in_array($reservationPublicId, $case->candidateReservationPublicIds, true)) {
                $this->renderDetail($action, $action->sessionVersion, $case, true);

                return;
            }
        }

        $payload = ['kind' => $kind, 'review' => $reviewPublicId, 'mode' => $mode];
        if ($reservationPublicId !== null) {
            $payload['reservation'] = $reservationPublicId;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_INPUT,
                $payload,
                'tg-admin-payment-review-input:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderInput($action, $session->version, $kind, $mode, false);
    }

    private function completeDecision(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_LIST,
                [],
                'tg-admin-payment-review-complete:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderList($action, $session->version, 'changed');
    }

    private function returnList(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_LIST,
                [],
                'tg-admin-payment-review-return-list:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderList($action, $session->version, null);
    }

    private function returnDetail(TelegramInteractionAction $action, string $kind, string $reviewPublicId): void
    {
        try {
            $case = $this->reviews->find($action->userId, $kind, $reviewPublicId);
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_DETAIL,
                ['kind' => $kind, 'review' => $reviewPublicId],
                'tg-admin-payment-review-return-detail:'.hash('sha256', $action->requestKey),
            );
        } catch (AuthorizationException) {
            $this->navigation->showAdminControl($action);

            return;
        } catch (DomainException) {
            $this->returnList($action);

            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderDetail($action, $session->version, $case);
    }

    private function renderList(TelegramInteractionAction $action, int $sessionVersion, ?string $notice): void
    {
        try {
            $cases = $this->reviews->pending($action->userId);
        } catch (AuthorizationException) {
            $this->navigation->showAdminControl($action);

            return;
        }

        $locale = $this->locale($action->userId);
        $key = 'telegram.navigation.admin.payment_reviews.';
        $lines = [];
        $rows = [];
        foreach ($cases as $offset => $case) {
            $lines[] = $this->translation($key.'item', $locale, [
                'number' => $offset + 1,
                'kind' => $this->translation($key.'kinds.'.$case->kind, $locale),
                'id' => $case->reviewPublicId,
                'subject' => $case->subjectPublicId,
                'provider' => $case->providerCode,
                'reference' => $case->reference ?? $this->translation($key.'not_available', $locale),
                'amount' => $case->amount === null
                    ? $this->translation($key.'not_available', $locale)
                    : number_format($case->amount).' '.($case->currency ?? ''),
                'private' => $this->translation($key.($case->privateEvidenceAvailable ? 'yes' : 'no'), $locale),
            ]);
            $open = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_SELECT,
                ['kind' => $case->kind, 'review' => $case->reviewPublicId],
                'tg-admin-payment-review-open:'.hash('sha256', $action->requestKey.':'.$case->reviewPublicId),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation($key.'buttons.open', $locale, ['number' => $offset + 1]),
                $open->publicId,
                TelegramInlineButtonStyle::Primary,
            )];
        }

        $refresh = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_REFRESH,
            [],
            'tg-admin-payment-review-refresh:'.hash('sha256', $action->requestKey),
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation($key.'buttons.refresh', $locale),
            $refresh->publicId,
        )];

        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-admin-payment-review-back:'.hash('sha256', $action->requestKey),
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )];

        $text = $cases === []
            ? $this->translation($key.'empty', $locale)
            : $this->translation($key.'list', $locale, ['items' => implode("\n\n", $lines)]);
        if ($notice !== null) {
            $text = $this->translation($key.'notices.'.$notice, $locale)."\n\n".$text;
        }

        $this->send($action, $text, $rows, 'list:'.$sessionVersion.':'.($notice ?? 'none'));
    }

    private function renderDetail(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramAlternativePaymentReviewCase $case,
        bool $staleCandidate = false,
    ): void {
        $locale = $this->locale($action->userId);
        $key = 'telegram.navigation.admin.payment_reviews.';
        $candidates = $case->candidateReservationPublicIds === []
            ? $this->translation($key.'not_available', $locale)
            : implode("\n", array_map(
                static fn (string $id, int $offset): string => ($offset + 1).') '.$id,
                $case->candidateReservationPublicIds,
                array_keys($case->candidateReservationPublicIds),
            ));
        $text = $this->translation($key.'detail', $locale, [
            'kind' => $this->translation($key.'kinds.'.$case->kind, $locale),
            'id' => $case->reviewPublicId,
            'subject' => $case->subjectPublicId,
            'provider' => $case->providerCode,
            'reference' => $case->reference ?? $this->translation($key.'not_available', $locale),
            'amount' => $case->amount === null
                ? $this->translation($key.'not_available', $locale)
                : number_format($case->amount).' '.($case->currency ?? ''),
            'private' => $this->translation($key.($case->privateEvidenceAvailable ? 'yes' : 'no'), $locale),
            'candidates' => $candidates,
            'confirmations' => $case->minimumConfirmations === null
                ? $this->translation($key.'not_available', $locale)
                : (string) $case->minimumConfirmations,
        ]);
        if ($staleCandidate) {
            $text = $this->translation($key.'notices.stale', $locale)."\n\n".$text;
        }

        $rows = [];
        if ($case->kind === 'c2c') {
            foreach ($case->candidateReservationPublicIds as $offset => $reservationPublicId) {
                $approve = $this->callbacks->issue(
                    $action->sessionPublicId,
                    $sessionVersion,
                    self::ACTION_APPROVE,
                    ['reservation' => $reservationPublicId],
                    'tg-admin-payment-review-c2c-approve:'.hash('sha256', $action->requestKey.':'.$reservationPublicId),
                );
                $rows[] = [new TelegramInlineCallbackButton(
                    $this->translation($key.'buttons.approve_candidate', $locale, ['number' => $offset + 1]),
                    $approve->publicId,
                    TelegramInlineButtonStyle::Success,
                )];
            }
        } else {
            $approve = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_APPROVE,
                [],
                'tg-admin-payment-review-approve:'.hash('sha256', $action->requestKey.':'.$case->reviewPublicId),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation($key.'buttons.approve', $locale),
                $approve->publicId,
                TelegramInlineButtonStyle::Success,
            )];
        }

        $reject = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_REJECT,
            [],
            'tg-admin-payment-review-reject:'.hash('sha256', $action->requestKey.':'.$case->reviewPublicId),
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation($key.'buttons.reject', $locale),
            $reject->publicId,
            TelegramInlineButtonStyle::Danger,
        )];
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-admin-payment-review-detail-back:'.hash('sha256', $action->requestKey),
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )];

        $this->send($action, $text, $rows, 'detail:'.$case->reviewPublicId.':'.$sessionVersion);
    }

    private function renderInput(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $kind,
        string $mode,
        bool $invalid,
    ): void {
        $locale = $this->locale($action->userId);
        $key = 'telegram.navigation.admin.payment_reviews.';
        $promptKey = $mode === 'reject'
            ? 'input.reject'
            : 'input.'.$kind;
        $text = $this->translation($key.$promptKey, $locale);
        if ($invalid) {
            $text = $this->translation($key.'input.invalid', $locale)."\n\n".$text;
        }

        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-admin-payment-review-input-back:'.hash('sha256', $action->requestKey),
        );
        $this->send($action, $text, [[new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )]], 'input:'.$kind.':'.$mode.':'.$sessionVersion);
    }

    /**
     * @param  list<list<TelegramInlineCallbackButton>>  $rows
     */
    private function send(TelegramInteractionAction $action, string $text, array $rows, string $suffix): void
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
            'tg-admin-payment-review-delivery:'.hash('sha256', $action->requestKey.':'.$suffix),
            'tg-admin-payment-review:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$suffix), 0, 40),
            new TelegramInlineKeyboardSnapshot($rows),
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
                'tg-admin-payment-review-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':admin-payment-review-home',
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

    /**
     * @param  array<string,mixed>  $payload
     * @return array{0:string,1:string}
     */
    private function selection(array $payload): array
    {
        $kind = $payload['kind'] ?? null;
        $review = $payload['review'] ?? null;
        if (! is_string($kind)
            || ! in_array($kind, ['c2c', 'gift_card', 'usdt'], true)
            || ! is_string($review)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $review) !== 1) {
            throw new RuntimeException('Telegram alternative-payment review selection is invalid.');
        }

        return [$kind, strtoupper($review)];
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
            throw new RuntimeException('Telegram alternative-payment review translation is unavailable.');
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram alternative-payment review translation has an unresolved placeholder.');
        }

        return $value;
    }

    private function assertActor(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new RuntimeException('Telegram alternative-payment review actor binding is invalid.');
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

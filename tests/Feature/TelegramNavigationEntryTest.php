<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agents\Application\AgentApplicationService;
use App\Modules\Agents\Application\AgentChangeContext;
use App\Modules\Promotions\Application\ReferralAttributionService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardPayment;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseDiscountQuote;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseQuote;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseWalletPayment;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceDeliveryResender;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceProjection;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardDestination;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardReservation;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCatalogPage;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseDiscountQuotePreview;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseOffering;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseOrderReceipt;
use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodsDecision;
use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodSelection;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseQuotePreview;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseQuoteRefreshRequired;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseWalletPaid;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseWalletReservation;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseWalletUnavailable;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramInteractionAction;
use App\Modules\Telegram\Application\TelegramInteractionCallbackReceipt;
use App\Modules\Telegram\Application\TelegramInteractionCallbackService;
use App\Modules\Telegram\Application\TelegramInteractionUpdateBindingReceipt;
use App\Modules\Telegram\Application\TelegramInteractionUpdateBindingService;
use App\Modules\Telegram\Application\TelegramNavigationEntryGateway;
use App\Modules\Telegram\Application\TelegramNavigationHandler;
use App\Modules\Telegram\Application\TelegramOwnedServiceDeliveryResendStatus;
use App\Modules\Telegram\Application\TelegramOwnedServiceDetail;
use App\Modules\Telegram\Application\TelegramOwnedServiceListItem;
use App\Modules\Telegram\Application\TelegramOwnedServicePage;
use App\Modules\Telegram\Application\TelegramOwnedServiceSearchResult;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Shared\Application\RestrictedValue;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class TelegramNavigationCustomerPurchaseCatalog implements TelegramCustomerPurchaseCatalog
{
    /** @var list<array{actor_user_id:int,subject_user_id:int,page:int,page_size:int}> */
    public array $pageCalls = [];

    /** @var list<array{actor_user_id:int,subject_user_id:int,selection_token:string}> */
    public array $offeringCalls = [];

    /** @var list<TelegramCustomerPurchaseOffering> */
    private array $items;

    public string $mode = 'normal';

    public bool $offeringAvailable = true;

    public function __construct(string $accountType = 'customer')
    {
        $this->items = [
            new TelegramCustomerPurchaseOffering(
                str_repeat('c', 40),
                'purchase-standard',
                'دسته خرید',
                'Purchase category',
                'پلن خرید',
                'Purchase plan',
                'نسخه پایه',
                'Base variant',
                'استاندارد',
                'Standard',
                900_000,
                30,
                50 * 1024 * 1024 * 1024,
                2,
                $accountType,
            ),
            new TelegramCustomerPurchaseOffering(
                str_repeat('d', 40),
                'purchase-economy',
                'دسته دوم',
                'Second category',
                'پلن دوم',
                'Second plan',
                null,
                null,
                'اقتصادی',
                'Economy',
                700_000,
                15,
                null,
                null,
                $accountType,
            ),
        ];
    }

    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramCustomerPurchaseCatalogPage
    {
        $this->pageCalls[] = [
            'actor_user_id' => $actorUserId,
            'subject_user_id' => $subjectUserId,
            'page' => $page,
            'page_size' => $pageSize,
        ];
        if ($actorUserId !== $subjectUserId || $pageSize !== 6) {
            throw new RuntimeException('Unexpected Telegram purchase catalog page request.');
        }
        if ($this->mode === 'empty') {
            if ($page !== 1) {
                throw new RuntimeException('Unexpected empty Telegram purchase catalog page request.');
            }

            return new TelegramCustomerPurchaseCatalogPage([], 1, 1, 0);
        }
        if ($this->mode === 'paginated') {
            if (! in_array($page, [1, 2], true)) {
                throw new RuntimeException('Unexpected paginated Telegram purchase catalog page request.');
            }

            return new TelegramCustomerPurchaseCatalogPage([$this->items[$page - 1]], $page, 2, 2);
        }
        if ($page !== 1) {
            throw new RuntimeException('Unexpected Telegram purchase catalog page request.');
        }

        return new TelegramCustomerPurchaseCatalogPage($this->items, 1, 1, count($this->items));
    }

    public function offeringForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramCustomerPurchaseOffering
    {
        $this->offeringCalls[] = [
            'actor_user_id' => $actorUserId,
            'subject_user_id' => $subjectUserId,
            'selection_token' => $selectionToken,
        ];
        if ($actorUserId !== $subjectUserId) {
            throw new RuntimeException('Unexpected Telegram purchase catalog cross-actor detail request.');
        }
        if (! $this->offeringAvailable) {
            throw new AuthorizationException('Telegram purchase offering is unavailable for this actor.');
        }
        foreach ($this->items as $item) {
            if ($item->selectionToken === $selectionToken) {
                return $item;
            }
        }

        throw new RuntimeException('Unexpected Telegram purchase catalog selection token.');
    }
}

final class TelegramNavigationCustomerPurchaseQuote implements TelegramCustomerPurchaseQuote
{
    /** @var list<array{actor_user_id:int,subject_user_id:int,selection_token:string,accepted_at:DateTimeImmutable,quote_key:string,correlation_id:string}> */
    public array $calls = [];

    public function __construct(private readonly TelegramNavigationCustomerPurchaseCatalog $catalog) {}

    public function previewForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        string $quotePublicId,
        string $quoteConfigurationHash,
    ): TelegramCustomerPurchaseQuotePreview {
        if ($quotePublicId !== str_pad('01K', 26, '0') || $quoteConfigurationHash !== str_repeat('a', 64)) {
            throw new AuthorizationException('Telegram purchase Quote preview is unavailable.');
        }
        $offering = $this->catalog->offeringForSelf($actorUserId, $subjectUserId, $offeringSelectionToken);
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new TelegramCustomerPurchaseQuotePreview(
            $offering,
            $quotePublicId,
            $quoteConfigurationHash,
            $offering->basePriceIrr,
            $offering->basePriceIrr,
            0,
            $offering->basePriceIrr,
            'IRR',
            $now,
            $now->modify('+15 minutes'),
            true,
            $offering->accountType,
        );
    }

    public function quoteForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        DateTimeImmutable $acceptedAt,
        string $quoteKey,
        string $correlationId,
    ): TelegramCustomerPurchaseQuotePreview {
        $this->calls[] = [
            'actor_user_id' => $actorUserId,
            'subject_user_id' => $subjectUserId,
            'selection_token' => $offeringSelectionToken,
            'accepted_at' => $acceptedAt,
            'quote_key' => $quoteKey,
            'correlation_id' => $correlationId,
        ];
        $offering = $this->catalog->offeringForSelf($actorUserId, $subjectUserId, $offeringSelectionToken);

        return new TelegramCustomerPurchaseQuotePreview(
            $offering,
            str_pad('01K', 26, '0'),
            str_repeat('a', 64),
            $offering->basePriceIrr,
            $offering->basePriceIrr,
            0,
            $offering->basePriceIrr,
            'IRR',
            $acceptedAt,
            $acceptedAt->modify('+15 minutes'),
            false,
            $offering->accountType,
        );
    }

    /** @return array{actor_user_id:int,subject_user_id:int,selection_token:string,accepted_at:DateTimeImmutable,quote_key:string,correlation_id:string} */
    public function latestCall(): array
    {
        if ($this->calls === []) {
            throw new RuntimeException('Telegram purchase Quote test double has no call.');
        }

        return $this->calls[array_key_last($this->calls)];
    }
}

final class TelegramNavigationCustomerPurchaseDiscountQuote implements TelegramCustomerPurchaseDiscountQuote
{
    /** @var list<array{actor_user_id:int,subject_user_id:int,selection_token:string,source_quote_public_id:string,source_quote_configuration_hash:string,code:string,accepted_at:DateTimeImmutable,operation_key:string}> */
    public array $calls = [];

    public bool $reject = false;

    public bool $refreshRequired = false;

    public function __construct(private readonly TelegramNavigationCustomerPurchaseCatalog $catalog) {}

    public function requoteForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        string $sourceQuotePublicId,
        string $sourceQuoteConfigurationHash,
        string $code,
        DateTimeImmutable $acceptedAt,
        string $operationKey,
    ): TelegramCustomerPurchaseDiscountQuotePreview {
        $this->calls[] = [
            'actor_user_id' => $actorUserId,
            'subject_user_id' => $subjectUserId,
            'selection_token' => $offeringSelectionToken,
            'source_quote_public_id' => $sourceQuotePublicId,
            'source_quote_configuration_hash' => $sourceQuoteConfigurationHash,
            'code' => $code,
            'accepted_at' => $acceptedAt,
            'operation_key' => $operationKey,
        ];
        if ($this->refreshRequired) {
            throw new TelegramCustomerPurchaseQuoteRefreshRequired('Discount source Quote requires refresh.');
        }
        if ($this->reject) {
            throw new \DomainException('Discount rejected.');
        }
        $offering = $this->catalog->offeringForSelf($actorUserId, $subjectUserId, $offeringSelectionToken);
        $discount = 90_000;

        return new TelegramCustomerPurchaseDiscountQuotePreview(
            new TelegramCustomerPurchaseQuotePreview(
                $offering,
                str_pad('01D', 26, '0'),
                str_repeat('d', 64),
                $offering->basePriceIrr,
                $offering->basePriceIrr,
                $discount,
                $offering->basePriceIrr - $discount,
                'IRR',
                $acceptedAt,
                $acceptedAt->modify('+15 minutes'),
                false,
                $offering->accountType,
            ),
            str_pad('01C', 26, '0'),
            str_repeat('c', 64),
            str_pad('01R', 26, '0'),
            false,
        );
    }
}

final class TelegramNavigationCustomerPurchasePaymentMethods implements TelegramCustomerPurchasePaymentMethods
{
    /** @var list<array{actor_user_id:int,subject_user_id:int,quote_public_id:string,quote_configuration_hash:string,decision_key:string}> */
    public array $calls = [];

    /** @var list<string> */
    public array $methodCodes = ['wallet', 'zarinpal', 'future_gateway'];

    public bool $available = true;

    public string $quotePublicId;

    public string $quoteConfigurationHash;

    public function __construct()
    {
        $this->quotePublicId = str_pad('01K', 26, '0');
        $this->quoteConfigurationHash = str_repeat('a', 64);
    }

    public function discoverForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionKey,
    ): TelegramCustomerPurchasePaymentMethodsDecision {
        if (! $this->available) {
            throw new AuthorizationException('Telegram purchase payment methods are unavailable.');
        }
        if ($actorUserId !== $subjectUserId
            || $quotePublicId !== $this->quotePublicId
            || $quoteConfigurationHash !== $this->quoteConfigurationHash
            || preg_match('/\Atelegram-purchase-payment-methods:[0-9A-HJKMNP-TV-Z]{26}\z/i', $decisionKey) !== 1) {
            throw new RuntimeException('Unexpected Telegram purchase payment-method discovery request.');
        }
        $this->calls[] = [
            'actor_user_id' => $actorUserId,
            'subject_user_id' => $subjectUserId,
            'quote_public_id' => $quotePublicId,
            'quote_configuration_hash' => $quoteConfigurationHash,
            'decision_key' => $decisionKey,
        ];

        return new TelegramCustomerPurchasePaymentMethodsDecision(
            str_pad('01P', 26, '0'),
            $quotePublicId,
            $quoteConfigurationHash,
            str_repeat('b', 64),
            $this->methodCodes,
            false,
        );
    }

    public function currentForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
    ): TelegramCustomerPurchasePaymentMethodsDecision {
        if (! $this->available
            || $actorUserId !== $subjectUserId
            || $quotePublicId !== $this->quotePublicId
            || $quoteConfigurationHash !== $this->quoteConfigurationHash
            || $decisionPublicId !== str_pad('01P', 26, '0')
            || $decisionConfigurationHash !== str_repeat('b', 64)) {
            throw new AuthorizationException('Telegram purchase payment methods are unavailable.');
        }

        return new TelegramCustomerPurchasePaymentMethodsDecision(
            $decisionPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionConfigurationHash,
            $this->methodCodes,
            true,
        );
    }

    public function selectForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $methodCode,
    ): TelegramCustomerPurchasePaymentMethodSelection {
        $decision = $this->currentForSelf(
            $actorUserId,
            $subjectUserId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
        );
        if (! in_array($methodCode, $decision->methodCodes, true)) {
            throw new AuthorizationException('Telegram purchase payment method is unavailable.');
        }

        return new TelegramCustomerPurchasePaymentMethodSelection($decisionPublicId, $quotePublicId, $methodCode);
    }

    /** @return array{actor_user_id:int,subject_user_id:int,quote_public_id:string,quote_configuration_hash:string,decision_key:string} */
    public function latestCall(): array
    {
        if ($this->calls === []) {
            throw new RuntimeException('Telegram purchase payment-method test double has no call.');
        }

        return $this->calls[array_key_last($this->calls)];
    }
}

final class TelegramNavigationCustomerPurchaseOrder implements TelegramCustomerPurchaseOrder
{
    /** @var list<array{actor_user_id:int,subject_user_id:int,quote_public_id:string,quote_configuration_hash:string,correlation_id:string}> */
    public array $openCalls = [];

    public string $orderPublicId;

    public string $quotePublicId;

    public string $quoteConfigurationHash;

    public function __construct()
    {
        $this->orderPublicId = str_pad('01N', 26, '0');
        $this->quotePublicId = str_pad('01K', 26, '0');
        $this->quoteConfigurationHash = str_repeat('a', 64);
    }

    public function openForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $correlationId,
    ): TelegramCustomerPurchaseOrderReceipt {
        if ($actorUserId !== $subjectUserId
            || $quotePublicId !== $this->quotePublicId
            || $quoteConfigurationHash !== $this->quoteConfigurationHash
            || preg_match('/\Atelegram-order:[0-9A-HJKMNP-TV-Z]{26}\z/i', $correlationId) !== 1) {
            throw new AuthorizationException('Telegram purchase Order is unavailable.');
        }
        $this->openCalls[] = [
            'actor_user_id' => $actorUserId,
            'subject_user_id' => $subjectUserId,
            'quote_public_id' => $quotePublicId,
            'quote_configuration_hash' => $quoteConfigurationHash,
            'correlation_id' => $correlationId,
        ];

        return new TelegramCustomerPurchaseOrderReceipt(
            $this->orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            910_000,
            'IRR',
            count($this->openCalls) > 1,
        );
    }

    public function currentForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
    ): TelegramCustomerPurchaseOrderReceipt {
        if ($actorUserId !== $subjectUserId
            || $orderPublicId !== $this->orderPublicId
            || $quotePublicId !== $this->quotePublicId
            || $quoteConfigurationHash !== $this->quoteConfigurationHash) {
            throw new AuthorizationException('Telegram purchase Order is unavailable.');
        }

        return new TelegramCustomerPurchaseOrderReceipt(
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            910_000,
            'IRR',
            true,
        );
    }
}

final class TelegramNavigationCustomerPurchaseCardToCardPayment implements TelegramCustomerPurchaseCardToCardPayment
{
    /** @var list<array<string, int|string>> */
    public array $reserveCalls = [];

    public function reserveForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseCardToCardReservation {
        if ($actorUserId !== $subjectUserId
            || $orderPublicId !== str_pad('01N', 26, '0')
            || $quotePublicId !== str_pad('01K', 26, '0')
            || $quoteConfigurationHash !== str_repeat('a', 64)
            || $decisionPublicId !== str_pad('01P', 26, '0')
            || $decisionConfigurationHash !== str_repeat('b', 64)
            || preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Unexpected Telegram card-to-card reserve request.');
        }
        $this->reserveCalls[] = compact(
            'actorUserId',
            'subjectUserId',
            'orderPublicId',
            'quotePublicId',
            'decisionPublicId',
            'operationKey',
        );

        return new TelegramCustomerPurchaseCardToCardReservation(
            str_pad('01C', 26, '0'),
            str_pad('01R', 26, '0'),
            $orderPublicId,
            $quotePublicId,
            $decisionPublicId,
            910_000,
            1_000,
            911_000,
            '424242******4242',
            now('UTC')->addHour()->toDateTimeImmutable(),
            now('UTC')->addDay()->toDateTimeImmutable(),
            count($this->reserveCalls) > 1,
        );
    }

    public function destinationForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $reservationPublicId,
    ): TelegramCustomerPurchaseCardToCardDestination {
        if ($actorUserId !== $subjectUserId || $reservationPublicId !== str_pad('01R', 26, '0')) {
            throw new AuthorizationException('Telegram card-to-card destination is unavailable.');
        }

        return new TelegramCustomerPurchaseCardToCardDestination(
            $reservationPublicId,
            RestrictedValue::fromString('4242424242424242'),
            '424242******4242',
            911_000,
            new DateTimeImmutable('2026-09-04T12:30:00+00:00'),
        );
    }
}

final class TelegramNavigationCustomerPurchaseWalletPayment implements TelegramCustomerPurchaseWalletPayment
{
    /** @var list<array<string, int|string>> */
    public array $reserveCalls = [];

    /** @var list<array<string, int|string>> */
    public array $captureCalls = [];

    /** @var list<array<string, int|string>> */
    public array $cancelCalls = [];

    public bool $captureUnavailable = false;

    public function reserveForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseWalletReservation {
        if ($actorUserId !== $subjectUserId
            || $orderPublicId !== str_pad('01N', 26, '0')
            || $quotePublicId !== str_pad('01K', 26, '0')
            || $quoteConfigurationHash !== str_repeat('a', 64)
            || $decisionPublicId !== str_pad('01P', 26, '0')
            || $decisionConfigurationHash !== str_repeat('b', 64)
            || preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Unexpected Telegram wallet reserve request.');
        }
        $this->reserveCalls[] = compact(
            'actorUserId',
            'subjectUserId',
            'orderPublicId',
            'quotePublicId',
            'decisionPublicId',
            'operationKey',
        );

        return new TelegramCustomerPurchaseWalletReservation(
            str_pad('01W', 26, '0'),
            $orderPublicId,
            $quotePublicId,
            $decisionPublicId,
            910_000,
            590_000,
            count($this->reserveCalls) > 1,
        );
    }

    public function captureForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $paymentIntentPublicId,
        string $operationKey,
    ): TelegramCustomerPurchaseWalletPaid {
        if ($actorUserId !== $subjectUserId
            || $orderPublicId !== str_pad('01N', 26, '0')
            || $quotePublicId !== str_pad('01K', 26, '0')
            || $quoteConfigurationHash !== str_repeat('a', 64)
            || $decisionPublicId !== str_pad('01P', 26, '0')
            || $decisionConfigurationHash !== str_repeat('b', 64)
            || $paymentIntentPublicId !== str_pad('01W', 26, '0')
            || preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Unexpected Telegram wallet capture request.');
        }
        $this->captureCalls[] = compact(
            'actorUserId',
            'subjectUserId',
            'orderPublicId',
            'quotePublicId',
            'decisionPublicId',
            'paymentIntentPublicId',
            'operationKey',
        );
        if ($this->captureUnavailable) {
            throw new TelegramCustomerPurchaseWalletUnavailable('Telegram wallet payment is no longer confirmable.');
        }

        return new TelegramCustomerPurchaseWalletPaid(
            $paymentIntentPublicId,
            $orderPublicId,
            str_pad('01S', 26, '0'),
            $quotePublicId,
            910_000,
            count($this->captureCalls) > 1,
        );
    }

    public function cancelForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $decisionPublicId,
        string $paymentIntentPublicId,
        string $operationKey,
    ): void {
        if ($actorUserId !== $subjectUserId
            || $orderPublicId !== str_pad('01N', 26, '0')
            || $quotePublicId !== str_pad('01K', 26, '0')
            || $decisionPublicId !== str_pad('01P', 26, '0')
            || $paymentIntentPublicId !== str_pad('01W', 26, '0')
            || preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Unexpected Telegram wallet cancel request.');
        }
        $this->cancelCalls[] = compact(
            'actorUserId',
            'subjectUserId',
            'orderPublicId',
            'quotePublicId',
            'decisionPublicId',
            'paymentIntentPublicId',
            'operationKey',
        );
    }
}

final class TelegramNavigationOwnedServiceSearchProjection implements TelegramOwnedServiceProjection
{
    public bool $detailAvailable = true;

    public function __construct(
        private readonly string $selectionToken,
        private readonly string $servicePublicId,
    ) {}

    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramOwnedServicePage
    {
        if ($actorUserId !== $subjectUserId || $page !== 1 || $pageSize !== 6) {
            throw new RuntimeException('Unexpected searchable My Services page request.');
        }

        return new TelegramOwnedServicePage([
            new TelegramOwnedServiceListItem(
                $this->selectionToken,
                $this->servicePublicId,
                'active',
                'پلن جستجو',
                'Search plan',
                'سرور جستجو',
                'Search server',
                '2026-09-01 04:00:00.000000',
            ),
        ], 1, 1, 1);
    }

    public function detailForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramOwnedServiceDetail
    {
        if ($actorUserId !== $subjectUserId || $selectionToken !== $this->selectionToken || ! $this->detailAvailable) {
            throw new RuntimeException('Search detail projection is unavailable.');
        }

        return new TelegramOwnedServiceDetail(
            $this->servicePublicId,
            'active',
            'پلن جستجو',
            'Search plan',
            'سرور جستجو',
            'Search server',
            '2026-09-01 04:00:00.000000',
            'cached',
            'unavailable',
            'active',
            10 * 1024 * 1024 * 1024,
            3 * 1024 * 1024 * 1024,
            '2026-10-01 04:00:00.000000',
            '2026-08-31 23:55:00.000000',
        );
    }

    public function searchForSelf(int $actorUserId, int $subjectUserId, string $searchTerm): TelegramOwnedServiceSearchResult
    {
        if ($actorUserId !== $subjectUserId) {
            throw new RuntimeException('Unexpected cross-actor search request.');
        }

        return match ($searchTerm) {
            'match-search', 'cached-en' => TelegramOwnedServiceSearchResult::matched($this->selectionToken),
            'ambiguous-search' => TelegramOwnedServiceSearchResult::ambiguous(),
            default => TelegramOwnedServiceSearchResult::notFound(),
        };
    }
}

final class TelegramNavigationOwnedServiceDeliveryResender implements TelegramOwnedServiceDeliveryResender
{
    /** @var list<array{actor_user_id:int,service_public_id:string,request_key:string,correlation_id:string}> */
    public array $calls = [];

    public TelegramOwnedServiceDeliveryResendStatus $status = TelegramOwnedServiceDeliveryResendStatus::Queued;

    public function resendForSelf(
        int $actorUserId,
        string $servicePublicId,
        string $requestKey,
        string $correlationId,
    ): TelegramOwnedServiceDeliveryResendStatus {
        $this->calls[] = [
            'actor_user_id' => $actorUserId,
            'service_public_id' => $servicePublicId,
            'request_key' => $requestKey,
            'correlation_id' => $correlationId,
        ];

        return $this->status;
    }
}

/** @requirement ONB-002 ONB-003 USR-001 BUY-001 BUY-003 AGT-001 CAT-002 CAT-003 CAT-008 ADM-002 ACL-001 ACL-002 ACL-003 USDT-002 IPG-002 ARCH-003 ARCH-004 DAT-002 DAT-003 SEC-002 SEC-003 LOC-001 OPS-003 QUA-001 QUA-004 */
final class TelegramNavigationEntryTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram navigation entry verification requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php'))->up();
        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();
        (require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php'))->up();
        $this->seed();
        Queue::fake();
        config([
            'app.url' => 'https://bot.example.test',
            'telegram.bot_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'telegram.webhook_secret' => self::SECRET,
            'telegram.webhook_path' => 'api/telegram/webhook',
            'telegram.max_body_bytes' => 1_048_576,
            'telegram.queue' => 'critical',
            'telegram.processing_lease_seconds' => 120,
            'telegram.api_base_url' => 'https://api.telegram.org',
            'telegram.api_timeout_seconds' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_6402');
                DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_6903');
                DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_7013');
                DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_admin_rate_finalize');
                DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_6105');
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_start_creates_one_shared_navigation_session_and_one_persian_interactive_delivery_on_exact_replay(): void
    {
        $this->accept($this->payload(6101, 9601, 'navigation_fa', 'fa', '/start referral_1'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $processor->process('123456789', 6101);
        $processor->process('123456789', 6101);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', 9601)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $this->assertDatabaseHas('users', ['id' => (int) $account->user_id, 'locale' => 'fa']);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'telegram_account_id' => (int) $account->id,
            'flow' => TelegramNavigationEntryGateway::FLOW,
            'state' => TelegramNavigationEntryGateway::STATE,
            'status' => 'active',
            'version' => 1,
        ]);
        $sessionId = (int) DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->value('id');
        $this->assertDatabaseHas('telegram_interaction_update_bindings', [
            'bot_id' => '123456789',
            'update_id' => 6101,
            'telegram_interaction_session_id' => $sessionId,
            'session_version' => 1,
            'kind' => 'message',
        ]);

        $operation = DB::table('telegram_delivery_operations')->first();
        self::assertNotNull($operation);
        self::assertSame('send', (string) $operation->action);
        self::assertSame(9601, (int) $operation->recipient_chat_id);
        self::assertSame(trans('telegram.navigation.home', locale: 'fa'), (string) $operation->presentation_text);
        $callback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.my_account')
            ->first(['public_id', 'session_version', 'token_ciphertext']);
        self::assertNotNull($callback);
        self::assertSame(1, (int) $callback->session_version);
        $rawToken = $this->app->make(StringEncrypter::class)->decryptString((string) $callback->token_ciphertext);

        $snapshot = DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $operation->public_id)
            ->first(['keyboard_snapshot']);
        self::assertNotNull($snapshot);
        self::assertStringContainsString((string) $callback->public_id, (string) $snapshot->keyboard_snapshot);
        self::assertStringNotContainsString($rawToken, (string) $snapshot->keyboard_snapshot);
        $outbox = DB::table('outbox_messages')->where('aggregate_id', (string) $operation->public_id)->first();
        self::assertNotNull($outbox);
        self::assertSame(TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_INTERACTIVE, (int) $outbox->contract_version);
        self::assertSame(
            '{"telegram_delivery_operation_public_id":"'.(string) $operation->public_id.'"}',
            (string) $outbox->payload,
        );
        self::assertStringNotContainsString($rawToken, (string) $outbox->payload);
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());
        self::assertSame(1, DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_account')->count());
        self::assertSame(1, DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 6101,
            'state' => 'processed',
            'attempt_count' => 1,
        ]);
    }

    public function test_my_account_uses_same_session_confidential_v3_owner_boundaries_and_back_without_common_plaintext_leakage(): void
    {
        $telegramUserId = 9620;
        $this->accept($this->payload(6200, $telegramUserId, 'navigation_account', 'fa', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 6200);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $userId = (int) $account->user_id;
        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->first(['id', 'public_id']);
        self::assertNotNull($session);
        $sessionId = (int) $session->id;
        $userPublicId = (string) DB::table('users')->where('id', $userId)->value('public_id');

        $rawNationalId = '1234567891';
        $lookupHash = hash('sha256', 'navigation-account-national-lookup');
        $now = now('UTC');
        DB::table('identity_items')->insert([
            'user_id' => $userId,
            'type' => 'national_id',
            'encrypted_value' => 'encrypted:'.$rawNationalId,
            'lookup_hash' => $lookupHash,
            'active_lookup_hash' => $lookupHash,
            'hash_key_version' => 1,
            'masked_value' => '******7891',
            'state' => 'verified',
            'ownership_check_required' => false,
            'ownership_check_status' => 'not_required',
            'version' => 1,
            'submitted_at' => $now,
            'verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('customer_profiles')->where('user_id', $userId)->update([
            'identity_verification_status' => 'verified',
            'updated_at' => $now,
        ]);

        $assetId = $this->ledgerAccount('navigation.asset.'.$userId, 'asset');
        $cashId = $this->ledgerAccount('navigation.cash.'.$userId, 'liability', $userId, 'cash');
        $promoId = $this->ledgerAccount('navigation.promo.'.$userId, 'liability', $userId, 'promotional');
        $ledger = $this->app->make(LedgerPostingService::class);
        $ledger->post(
            'navigation-account-cash-credit',
            'wallet_topup_capture',
            'navigation-account-cash-correlation',
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive(700_000)),
                new LedgerEntryDraft($cashId, LedgerDirection::Credit, IrrMoney::positive(700_000)),
            ],
        );
        $ledger->post(
            'navigation-account-promo-credit',
            'wallet_promotion_credit',
            'navigation-account-promo-correlation',
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive(125_000)),
                new LedgerEntryDraft($promoId, LedgerDirection::Credit, IrrMoney::positive(125_000)),
            ],
        );
        $referralToken = $this->app->make(ReferralAttributionService::class)->identityForUser($userId);
        $walletCounts = $this->walletMutationCounts();
        $referralCounts = $this->referralMutationCounts();

        $homeCallback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.my_account')
            ->first(['public_id', 'token_ciphertext']);
        self::assertNotNull($homeCallback);
        $homeToken = $this->app->make(StringEncrypter::class)->decryptString((string) $homeCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6201, $telegramUserId, 'navigation_account', 'fa', $homeToken));
        $processor->process('123456789', 6201);

        $sameSession = DB::table('telegram_interaction_sessions')->where('id', $sessionId)->first(['state', 'version']);
        self::assertNotNull($sameSession);
        self::assertSame('my_account', (string) $sameSession->state);
        self::assertSame(2, (int) $sameSession->version);
        self::assertSame(1, DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->count());
        self::assertSame($walletCounts, $this->walletMutationCounts());
        self::assertSame($referralCounts, $this->referralMutationCounts());

        $operations = DB::table('telegram_delivery_operations')->orderBy('id')->get();
        self::assertCount(2, $operations);
        $accountOperation = $operations[1];
        self::assertSame('[CONFIDENTIAL_TELEGRAM_PRESENTATION]', (string) $accountOperation->presentation_text);
        $accountOutbox = DB::table('outbox_messages')->where('aggregate_id', (string) $accountOperation->public_id)->first();
        self::assertNotNull($accountOutbox);
        self::assertSame(TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL, (int) $accountOutbox->contract_version);
        self::assertSame(
            '{"telegram_delivery_operation_public_id":"'.(string) $accountOperation->public_id.'"}',
            (string) $accountOutbox->payload,
        );
        $companion = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $accountOperation->public_id)
            ->first(['presentation_ciphertext', 'presentation_hash']);
        self::assertNotNull($companion);
        $presentation = $this->app->make(StringEncrypter::class)->decryptString((string) $companion->presentation_ciphertext);
        self::assertStringContainsString('حساب من', $presentation);
        self::assertStringContainsString('مشتری', $presentation);
        self::assertStringContainsString('فعال', $presentation);
        self::assertStringContainsString('تأییدنشده', $presentation);
        self::assertStringContainsString('تأییدشده', $presentation);
        self::assertStringContainsString('کد ملی', $presentation);
        self::assertStringNotContainsString('customer', $presentation);
        self::assertStringNotContainsString('national_id', $presentation);
        self::assertStringContainsString($userPublicId, $presentation);
        self::assertStringContainsString('******7891', $presentation);
        self::assertStringContainsString('700,000', $presentation);
        self::assertStringContainsString('125,000', $presentation);
        self::assertStringContainsString($referralToken, $presentation);
        self::assertStringNotContainsString($rawNationalId, $presentation);
        self::assertStringNotContainsString($lookupHash, $presentation);

        $backCallback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.back')
            ->first(['public_id', 'session_version', 'token_ciphertext']);
        self::assertNotNull($backCallback);
        self::assertSame(2, (int) $backCallback->session_version);
        $backToken = $this->app->make(StringEncrypter::class)->decryptString((string) $backCallback->token_ciphertext);
        $accountSnapshot = DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $accountOperation->public_id)
            ->first(['keyboard_snapshot']);
        self::assertNotNull($accountSnapshot);
        self::assertStringContainsString((string) $backCallback->public_id, (string) $accountSnapshot->keyboard_snapshot);

        $commonEvidence = json_encode([
            'operation' => (array) $accountOperation,
            'outbox' => (array) $accountOutbox,
            'keyboard' => (string) $accountSnapshot->keyboard_snapshot,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach ([$rawNationalId, $lookupHash, $homeToken, $backToken] as $secret) {
            self::assertStringNotContainsString($secret, $commonEvidence);
        }
        self::assertStringNotContainsString($rawNationalId, (string) $companion->presentation_ciphertext);
        self::assertStringNotContainsString($lookupHash, (string) $companion->presentation_ciphertext);

        // Synchronize a second actor, then prove they cannot execute the first actor's fresh Back token.
        $this->accept($this->payload(6202, 9720, 'navigation_other', 'fa', '/start'));
        $processor->process('123456789', 6202);
        $operationCountBeforeCrossActor = DB::table('telegram_delivery_operations')->count();
        $this->accept($this->callbackPayload(6203, 9720, 'navigation_other', 'fa', $backToken));
        $processor->process('123456789', 6203);
        self::assertSame($operationCountBeforeCrossActor, DB::table('telegram_delivery_operations')->count());
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => $sessionId, 'state' => 'my_account', 'version' => 2]);
        $this->assertDatabaseHas('telegram_interaction_callbacks', ['public_id' => (string) $backCallback->public_id, 'state' => 'pending']);

        $this->accept($this->callbackPayload(6204, $telegramUserId, 'navigation_account', 'fa', $backToken));
        $processor->process('123456789', 6204);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => $sessionId, 'state' => 'home', 'version' => 3]);
        self::assertSame($operationCountBeforeCrossActor + 1, DB::table('telegram_delivery_operations')->count());
        $backHomeOperation = DB::table('telegram_delivery_operations')
            ->where('request_key_hash', hash('sha256', 'nav-home-delivery:telegram-callback:'.(string) $backCallback->public_id))
            ->first(['public_id']);
        self::assertNotNull($backHomeOperation);
        $backHomeOutbox = DB::table('outbox_messages')
            ->where('aggregate_id', (string) $backHomeOperation->public_id)
            ->first(['contract_version']);
        self::assertNotNull($backHomeOutbox);
        self::assertSame(
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_INTERACTIVE,
            (int) $backHomeOutbox->contract_version,
        );

        // Re-clicking the already completed old My Account token is replay-only and must not recreate effects.
        $beforeStaleReplay = DB::table('telegram_delivery_operations')->count();
        $this->accept($this->callbackPayload(6205, $telegramUserId, 'navigation_account', 'fa', $homeToken));
        $processor->process('123456789', 6205);
        self::assertSame($beforeStaleReplay, DB::table('telegram_delivery_operations')->count());
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => $sessionId, 'state' => 'home', 'version' => 3]);
    }

    public function test_menu_and_my_account_use_english_copy_without_requiring_referral_or_wallet_creation(): void
    {
        $telegramUserId = 9630;
        $this->accept($this->payload(6300, $telegramUserId, 'navigation_en', 'en', '/menu@FreedomBot'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 6300);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['user_id']);
        self::assertNotNull($account);
        $userId = (int) $account->user_id;
        self::assertSame(0, DB::table('ledger_accounts')->where('owner_user_id', $userId)->count());
        $referralIdentityCount = DB::table('referral_identities')->where('user_id', $userId)->count();
        $referralToken = DB::table('referral_identities')->where('user_id', $userId)->value('token');

        $homeOperation = DB::table('telegram_delivery_operations')->first();
        self::assertNotNull($homeOperation);
        self::assertSame(trans('telegram.navigation.home', locale: 'en'), (string) $homeOperation->presentation_text);
        $callback = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_account')->first(['token_ciphertext']);
        self::assertNotNull($callback);
        $token = $this->app->make(StringEncrypter::class)->decryptString((string) $callback->token_ciphertext);
        $this->accept($this->callbackPayload(6301, $telegramUserId, 'navigation_en', 'en', $token));
        $processor->process('123456789', 6301);

        $operation = DB::table('telegram_delivery_operations')->orderByDesc('id')->first();
        self::assertNotNull($operation);
        $ciphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $operation->public_id)
            ->value('presentation_ciphertext');
        self::assertIsString($ciphertext);
        $presentation = $this->app->make(StringEncrypter::class)->decryptString($ciphertext);
        self::assertStringContainsString('My Account', $presentation);
        self::assertStringContainsString('Customer', $presentation);
        self::assertStringContainsString('Active', $presentation);
        self::assertStringContainsString('Unverified', $presentation);
        self::assertStringContainsString('Wallet', $presentation);
        self::assertStringContainsString('Referral', $presentation);
        if (is_string($referralToken)) {
            self::assertStringContainsString($referralToken, $presentation);
        } else {
            self::assertStringContainsString('Not available', $presentation);
        }
        self::assertSame(0, DB::table('ledger_accounts')->where('owner_user_id', $userId)->count());
        self::assertSame($referralIdentityCount, DB::table('referral_identities')->where('user_id', $userId)->count());
    }

    public function test_post_dispatch_failure_after_callback_completion_replays_without_duplicate_transition_back_callback_or_confidential_delivery(): void
    {
        $telegramUserId = 9640;
        $this->accept($this->payload(6401, $telegramUserId, 'navigation_callback_retry', 'fa', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 6401);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        $sessionId = (int) DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->value('id');
        $transitionCountBefore = DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', $sessionId)->count();
        $callback = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_account')->first(['public_id', 'token_ciphertext']);
        self::assertNotNull($callback);
        $token = $this->app->make(StringEncrypter::class)->decryptString((string) $callback->token_ciphertext);
        $this->accept($this->callbackPayload(6402, $telegramUserId, 'navigation_callback_retry', 'fa', $token));

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_navigation_test_fail_processed_6402
BEFORE UPDATE ON processed_telegram_updates
FOR EACH ROW
BEGIN
    IF OLD.bot_id = '123456789' AND OLD.update_id = 6402 AND NEW.state = 'processed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-post-navigation-callback-dispatch-failure';
    END IF;
END
SQL);
        try {
            try {
                $processor->process('123456789', 6402);
                self::fail('The simulated post-dispatch failure must keep the callback update retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_6402');
        }

        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6402, 'state' => 'failed', 'attempt_count' => 1]);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => $sessionId, 'state' => 'my_account', 'version' => 2]);
        $this->assertDatabaseHas('telegram_interaction_callbacks', ['public_id' => (string) $callback->public_id, 'state' => 'completed', 'accepted_update_id' => 6402]);
        $transitionCount = DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', $sessionId)->count();
        $backCallbackCount = DB::table('telegram_interaction_callbacks')->where('telegram_interaction_session_id', $sessionId)->where('action', 'navigation.back')->count();
        $operationCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $outboxCount = DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count();
        self::assertSame($transitionCountBefore + 1, $transitionCount);
        self::assertSame(1, $backCallbackCount);
        self::assertSame(2, $operationCount);
        self::assertSame(2, $outboxCount);

        $processor->process('123456789', 6402);

        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6402, 'state' => 'processed', 'attempt_count' => 2]);
        $this->assertDatabaseHas('telegram_interaction_callbacks', ['public_id' => (string) $callback->public_id, 'state' => 'completed', 'accepted_update_id' => 6402]);
        self::assertSame($transitionCount, DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', $sessionId)->count());
        self::assertSame($backCallbackCount, DB::table('telegram_interaction_callbacks')->where('telegram_interaction_session_id', $sessionId)->where('action', 'navigation.back')->count());
        self::assertSame($operationCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());
        self::assertSame($outboxCount, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());
    }

    public function test_my_services_list_and_detail_reuse_same_session_and_keep_service_identity_out_of_common_durable_state(): void
    {
        $selectionToken = str_repeat('a', 40);
        $servicePublicId = '01J00000000000000000000000';
        $projection = new class($selectionToken, $servicePublicId) implements TelegramOwnedServiceProjection
        {
            public bool $detailAvailable = true;

            public function __construct(
                private readonly string $selectionToken,
                private readonly string $servicePublicId,
            ) {}

            public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramOwnedServicePage
            {
                if ($actorUserId !== $subjectUserId || $page !== 1 || $pageSize !== 6) {
                    throw new RuntimeException('Unexpected My Services projection request.');
                }

                return new TelegramOwnedServicePage([
                    new TelegramOwnedServiceListItem(
                        $this->selectionToken,
                        $this->servicePublicId,
                        'active',
                        'پلن تست',
                        'Test plan',
                        'سرور تست',
                        'Test server',
                        '2026-09-01 04:00:00.000000',
                    ),
                ], 1, 1, 1);
            }

            public function detailForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramOwnedServiceDetail
            {
                if ($actorUserId !== $subjectUserId || $selectionToken !== $this->selectionToken) {
                    throw new RuntimeException('Unexpected Service detail projection request.');
                }
                if (! $this->detailAvailable) {
                    throw new RuntimeException('Service detail is no longer available to the actor.');
                }

                return new TelegramOwnedServiceDetail(
                    $this->servicePublicId,
                    'active',
                    'پلن تست',
                    'Test plan',
                    'سرور تست',
                    'Test server',
                    '2026-09-01 04:00:00.000000',
                    'current',
                    'present',
                    'active',
                    10 * 1024 * 1024 * 1024,
                    3 * 1024 * 1024 * 1024,
                    '2026-10-01 04:00:00.000000',
                    '2026-09-01 04:05:00.000000',
                );
            }

            public function searchForSelf(int $actorUserId, int $subjectUserId, string $searchTerm): TelegramOwnedServiceSearchResult
            {
                return TelegramOwnedServiceSearchResult::notFound();
            }
        };
        $this->app->instance(TelegramOwnedServiceProjection::class, $projection);

        $telegramUserId = 9650;
        $this->accept($this->payload(6500, $telegramUserId, 'navigation_services', 'fa', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 6500);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id', 'public_id', 'state', 'version']);
        self::assertNotNull($session);
        self::assertSame('home', (string) $session->state);
        self::assertSame(1, (int) $session->version);

        $servicesCallback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.my_services')
            ->first(['public_id', 'action_payload', 'token_ciphertext']);
        self::assertNotNull($servicesCallback);
        self::assertSame('{}', (string) $servicesCallback->action_payload);
        $servicesToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $servicesCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6501, $telegramUserId, 'navigation_services', 'fa', $servicesToken));
        $processor->process('123456789', 6501);

        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'my_services',
            'version' => 2,
            'payload' => '{"page":1}',
        ]);
        self::assertSame(1, DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->count());

        $listOperation = DB::table('telegram_delivery_operations')->orderByDesc('id')->first();
        self::assertNotNull($listOperation);
        self::assertSame('[CONFIDENTIAL_TELEGRAM_PRESENTATION]', (string) $listOperation->presentation_text);
        $listCiphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $listOperation->public_id)
            ->value('presentation_ciphertext');
        self::assertIsString($listCiphertext);
        $listText = $this->app->make(StringEncrypter::class)->decryptString($listCiphertext);
        self::assertStringContainsString('سرویس‌های من', $listText);
        self::assertStringContainsString($servicePublicId, $listText);
        self::assertStringContainsString('پلن تست', $listText);
        self::assertStringContainsString('سرور تست', $listText);

        $detailCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 2)
            ->where('action', 'navigation.service.'.$selectionToken)
            ->first(['public_id', 'action', 'action_payload', 'token_ciphertext']);
        self::assertNotNull($detailCallback);
        self::assertSame('{}', (string) $detailCallback->action_payload);
        self::assertStringNotContainsString($servicePublicId, (string) $detailCallback->action);
        $detailToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $detailCallback->token_ciphertext);

        $listSnapshot = DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $listOperation->public_id)
            ->value('keyboard_snapshot');
        self::assertIsString($listSnapshot);
        $listOutbox = DB::table('outbox_messages')
            ->where('aggregate_id', (string) $listOperation->public_id)
            ->value('payload');
        self::assertIsString($listOutbox);
        $commonEvidence = $listSnapshot."\n".$listOutbox."\n".(string) DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->value('payload')."\n".(string) $detailCallback->action_payload;
        self::assertStringNotContainsString($servicePublicId, $commonEvidence);
        self::assertStringNotContainsString($selectionToken, $commonEvidence);
        self::assertStringNotContainsString($detailToken, $commonEvidence);

        $this->accept($this->payload(6502, 9750, 'navigation_services_other', 'fa', '/start'));
        $processor->process('123456789', 6502);
        $operationCountBeforeCrossActor = DB::table('telegram_delivery_operations')->count();
        $this->accept($this->callbackPayload(6503, 9750, 'navigation_services_other', 'fa', $detailToken));
        $processor->process('123456789', 6503);
        self::assertSame($operationCountBeforeCrossActor, DB::table('telegram_delivery_operations')->count());
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'my_services',
            'version' => 2,
        ]);

        $projection->detailAvailable = false;
        $this->accept($this->callbackPayload(6504, $telegramUserId, 'navigation_services', 'fa', $detailToken));
        try {
            $processor->process('123456789', 6504);
            self::fail('Unavailable owner projection must keep the accepted callback retryable without advancing the session.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }
        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 6504,
            'state' => 'failed',
            'attempt_count' => 1,
        ]);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'my_services',
            'version' => 2,
            'payload' => '{"page":1}',
        ]);
        $this->assertDatabaseHas('telegram_interaction_callbacks', [
            'public_id' => (string) $detailCallback->public_id,
            'state' => 'accepted',
            'accepted_update_id' => 6504,
        ]);

        $projection->detailAvailable = true;
        $processor->process('123456789', 6504);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 6504,
            'state' => 'processed',
            'attempt_count' => 2,
        ]);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'service_detail',
            'version' => 3,
            'payload' => '{"page":1}',
        ]);

        $detailOperation = DB::table('telegram_delivery_operations')->orderByDesc('id')->first();
        self::assertNotNull($detailOperation);
        self::assertSame('[CONFIDENTIAL_TELEGRAM_PRESENTATION]', (string) $detailOperation->presentation_text);
        $detailCiphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $detailOperation->public_id)
            ->value('presentation_ciphertext');
        self::assertIsString($detailCiphertext);
        $detailText = $this->app->make(StringEncrypter::class)->decryptString($detailCiphertext);
        self::assertStringContainsString('جزئیات سرویس', $detailText);
        self::assertStringContainsString($servicePublicId, $detailText);
        self::assertStringContainsString('10.00 GiB', $detailText);
        self::assertStringContainsString('3.00 GiB', $detailText);
        self::assertStringContainsString('7.00 GiB', $detailText);
        self::assertStringContainsString('موجود', $detailText);

        $backCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 3)
            ->where('action', 'navigation.back')
            ->first(['token_ciphertext']);
        self::assertNotNull($backCallback);
        $backToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $backCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6505, $telegramUserId, 'navigation_services', 'fa', $backToken));
        $processor->process('123456789', 6505);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'my_services',
            'version' => 4,
            'payload' => '{"page":1}',
        ]);
    }

    public function test_my_services_secure_resend_uses_owner_bound_callback_stable_identity_and_safe_confirmation(): void
    {
        $selectionToken = str_repeat('e', 40);
        $servicePublicId = '01J00000000000000000000004';
        $projection = new TelegramNavigationOwnedServiceSearchProjection($selectionToken, $servicePublicId);
        $resender = new TelegramNavigationOwnedServiceDeliveryResender;
        $this->app->instance(TelegramOwnedServiceProjection::class, $projection);
        $this->app->instance(TelegramOwnedServiceDeliveryResender::class, $resender);

        $telegramUserId = 9680;
        $this->accept($this->payload(7000, $telegramUserId, 'navigation_resend', 'fa', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 7000);
        $ownerUserId = (int) DB::table('telegram_accounts')
            ->where('telegram_user_id', $telegramUserId)
            ->value('user_id');
        self::assertGreaterThan(0, $ownerUserId);

        $servicesCallback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.my_services')
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($servicesCallback);
        $servicesToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $servicesCallback->token_ciphertext);
        $this->accept($this->callbackPayload(7001, $telegramUserId, 'navigation_resend', 'fa', $servicesToken));
        $processor->process('123456789', 7001);

        $detailCallback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.service.'.$selectionToken)
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($detailCallback);
        $detailToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $detailCallback->token_ciphertext);
        $this->accept($this->callbackPayload(7002, $telegramUserId, 'navigation_resend', 'fa', $detailToken));
        $processor->process('123456789', 7002);

        $accountId = (int) DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->value('id');
        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $accountId)
            ->first(['id', 'state', 'version', 'payload']);
        self::assertNotNull($session);
        self::assertSame('service_detail', (string) $session->state);
        self::assertSame(3, (int) $session->version);
        self::assertSame('{"page":1}', (string) $session->payload);

        $resendCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 3)
            ->where('action', 'navigation.service.resend')
            ->first(['public_id', 'action_payload', 'token_ciphertext']);
        self::assertNotNull($resendCallback);
        self::assertSame(
            json_encode(['service_selection' => $selectionToken], JSON_THROW_ON_ERROR),
            (string) $resendCallback->action_payload,
        );
        self::assertStringNotContainsString($servicePublicId, (string) $resendCallback->action_payload);
        $resendToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $resendCallback->token_ciphertext);

        $this->accept($this->payload(7003, 9780, 'navigation_resend_other', 'fa', '/start'));
        $processor->process('123456789', 7003);
        $this->accept($this->callbackPayload(7004, 9780, 'navigation_resend_other', 'fa', $resendToken));
        $processor->process('123456789', 7004);
        self::assertSame([], $resender->calls);

        $operationCountBefore = DB::table('telegram_delivery_operations')->count();
        $this->accept($this->callbackPayload(7005, $telegramUserId, 'navigation_resend', 'fa', $resendToken));
        $processor->process('123456789', 7005);
        self::assertSame([[
            'actor_user_id' => $ownerUserId,
            'service_public_id' => $servicePublicId,
            'request_key' => 'telegram-service-resend:'.(string) $resendCallback->public_id,
            'correlation_id' => 'telegram-resend:'.(string) $resendCallback->public_id,
        ]], $resender->calls);
        self::assertSame($operationCountBefore + 1, DB::table('telegram_delivery_operations')->count());
        $confirmation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('مسیر محافظت‌شده', $confirmation);
        self::assertStringNotContainsString($servicePublicId, $confirmation);
        self::assertStringNotContainsString($selectionToken, $confirmation);
        self::assertStringNotContainsString('http', mb_strtolower($confirmation));
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'service_detail',
            'version' => 3,
            'payload' => '{"page":1}',
        ]);

        $this->accept($this->callbackPayload(7006, $telegramUserId, 'navigation_resend', 'fa', $resendToken));
        $processor->process('123456789', 7006);
        self::assertCount(1, $resender->calls);
        self::assertSame($operationCountBefore + 1, DB::table('telegram_delivery_operations')->count());

        $backCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 3)
            ->where('action', 'navigation.back')
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($backCallback);
        $backToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $backCallback->token_ciphertext);
        $this->accept($this->callbackPayload(7007, $telegramUserId, 'navigation_resend', 'fa', $backToken));
        $processor->process('123456789', 7007);

        $detailAgain = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 4)
            ->where('action', 'navigation.service.'.$selectionToken)
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($detailAgain);
        $detailAgainToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $detailAgain->token_ciphertext);
        $this->accept($this->callbackPayload(7008, $telegramUserId, 'navigation_resend', 'fa', $detailAgainToken));
        $processor->process('123456789', 7008);

        $blockedResend = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 5)
            ->where('action', 'navigation.service.resend')
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($blockedResend);
        $blockedToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $blockedResend->token_ciphertext);
        $resender->status = TelegramOwnedServiceDeliveryResendStatus::TemporarilyBlocked;
        $this->accept($this->callbackPayload(7009, $telegramUserId, 'navigation_resend', 'fa', $blockedToken));
        $processor->process('123456789', 7009);
        self::assertCount(2, $resender->calls);
        $blockedCopy = $this->latestConfidentialPresentation();
        self::assertStringContainsString('کمی بعد دوباره تلاش کنید', $blockedCopy);
        self::assertStringNotContainsString($servicePublicId, $blockedCopy);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'service_detail',
            'version' => 5,
            'payload' => '{"page":1}',
        ]);

        /** @var array<string, mixed> $english */
        $english = require resource_path('lang/en/telegram.php');
        self::assertStringContainsString('protected delivery', (string) $english['navigation']['services']['resend']['queued']);
        self::assertStringContainsString('try again later', mb_strtolower((string) $english['navigation']['services']['resend']['temporarily_blocked']));
    }

    public function test_my_services_empty_state_is_confidential_and_back_remains_deterministic(): void
    {
        $projection = new class implements TelegramOwnedServiceProjection
        {
            public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramOwnedServicePage
            {
                if ($actorUserId !== $subjectUserId || $page !== 1 || $pageSize !== 6) {
                    throw new RuntimeException('Unexpected empty My Services projection request.');
                }

                return new TelegramOwnedServicePage([], 1, 1, 0);
            }

            public function detailForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramOwnedServiceDetail
            {
                throw new RuntimeException('Empty My Services must not resolve a detail projection.');
            }

            public function searchForSelf(int $actorUserId, int $subjectUserId, string $searchTerm): TelegramOwnedServiceSearchResult
            {
                return TelegramOwnedServiceSearchResult::notFound();
            }
        };
        $this->app->instance(TelegramOwnedServiceProjection::class, $projection);

        $telegramUserId = 9650;
        $this->accept($this->payload(6550, $telegramUserId, 'navigation_services_empty', 'fa', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 6550);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id']);
        self::assertNotNull($session);
        $servicesCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('action', 'navigation.my_services')
            ->first(['token_ciphertext']);
        self::assertNotNull($servicesCallback);
        $servicesToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $servicesCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6551, $telegramUserId, 'navigation_services_empty', 'fa', $servicesToken));
        $processor->process('123456789', 6551);

        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'my_services',
            'version' => 2,
            'payload' => '{"page":1}',
        ]);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 2)
            ->where('action', 'like', 'navigation.service.%')
            ->count());

        $operation = DB::table('telegram_delivery_operations')->orderByDesc('id')->first(['public_id', 'presentation_text']);
        self::assertNotNull($operation);
        self::assertSame('[CONFIDENTIAL_TELEGRAM_PRESENTATION]', (string) $operation->presentation_text);
        $ciphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $operation->public_id)
            ->value('presentation_ciphertext');
        self::assertIsString($ciphertext);
        $text = $this->app->make(StringEncrypter::class)->decryptString($ciphertext);
        self::assertStringContainsString('سرویس‌های من', $text);
        self::assertStringContainsString('هنوز سرویسی برای شما ثبت نشده است.', $text);

        $backCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 2)
            ->where('action', 'navigation.back')
            ->first(['token_ciphertext']);
        self::assertNotNull($backCallback);
        $backToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $backCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6552, $telegramUserId, 'navigation_services_empty', 'fa', $backToken));
        $processor->process('123456789', 6552);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'home',
            'version' => 3,
            'payload' => '{}',
        ]);
    }

    public function test_my_services_uses_english_list_and_no_sync_detail_copy(): void
    {
        $selectionToken = str_repeat('b', 40);
        $servicePublicId = '01J00000000000000000000001';
        $projection = new class($selectionToken, $servicePublicId) implements TelegramOwnedServiceProjection
        {
            public function __construct(
                private readonly string $selectionToken,
                private readonly string $servicePublicId,
            ) {}

            public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramOwnedServicePage
            {
                if ($actorUserId !== $subjectUserId || $page !== 1 || $pageSize !== 6) {
                    throw new RuntimeException('Unexpected English My Services projection request.');
                }

                return new TelegramOwnedServicePage([
                    new TelegramOwnedServiceListItem(
                        $this->selectionToken,
                        $this->servicePublicId,
                        'retired',
                        'پلن فارسی',
                        'English plan',
                        'سرور فارسی',
                        'English server',
                        null,
                    ),
                ], 1, 1, 1);
            }

            public function detailForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramOwnedServiceDetail
            {
                if ($actorUserId !== $subjectUserId || $selectionToken !== $this->selectionToken) {
                    throw new RuntimeException('Unexpected English Service detail projection request.');
                }

                return new TelegramOwnedServiceDetail(
                    $this->servicePublicId,
                    'retired',
                    'پلن فارسی',
                    'English plan',
                    'سرور فارسی',
                    'English server',
                    null,
                    'none',
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                );
            }

            public function searchForSelf(int $actorUserId, int $subjectUserId, string $searchTerm): TelegramOwnedServiceSearchResult
            {
                return TelegramOwnedServiceSearchResult::notFound();
            }
        };
        $this->app->instance(TelegramOwnedServiceProjection::class, $projection);

        $telegramUserId = 9660;
        $this->accept($this->payload(6600, $telegramUserId, 'navigation_services_en', 'en', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 6600);

        $servicesCallback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.my_services')
            ->first(['token_ciphertext']);
        self::assertNotNull($servicesCallback);
        $servicesToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $servicesCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6601, $telegramUserId, 'navigation_services_en', 'en', $servicesToken));
        $processor->process('123456789', 6601);

        $listOperation = DB::table('telegram_delivery_operations')->orderByDesc('id')->first(['public_id']);
        self::assertNotNull($listOperation);
        $listCiphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $listOperation->public_id)
            ->value('presentation_ciphertext');
        self::assertIsString($listCiphertext);
        $listText = $this->app->make(StringEncrypter::class)->decryptString($listCiphertext);
        self::assertStringContainsString('My Services', $listText);
        self::assertStringContainsString('English plan', $listText);
        self::assertStringContainsString('English server', $listText);
        self::assertStringContainsString('Retired', $listText);
        self::assertStringContainsString($servicePublicId, $listText);
        self::assertStringNotContainsString('پلن فارسی', $listText);
        self::assertStringNotContainsString('سرور فارسی', $listText);

        $detailCallback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.service.'.$selectionToken)
            ->first(['token_ciphertext']);
        self::assertNotNull($detailCallback);
        $detailToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $detailCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6602, $telegramUserId, 'navigation_services_en', 'en', $detailToken));
        $processor->process('123456789', 6602);

        $detailOperation = DB::table('telegram_delivery_operations')->orderByDesc('id')->first(['public_id']);
        self::assertNotNull($detailOperation);
        $detailCiphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $detailOperation->public_id)
            ->value('presentation_ciphertext');
        self::assertIsString($detailCiphertext);
        $detailText = $this->app->make(StringEncrypter::class)->decryptString($detailCiphertext);
        self::assertStringContainsString('Service Details', $detailText);
        self::assertStringContainsString('English plan', $detailText);
        self::assertStringContainsString('English server', $detailText);
        self::assertStringContainsString('Retired', $detailText);
        self::assertStringContainsString('No synchronization evidence', $detailText);
        self::assertStringContainsString('Not available', $detailText);
        self::assertStringNotContainsString('پلن فارسی', $detailText);
        self::assertStringNotContainsString('سرور فارسی', $detailText);
    }

    public function test_my_services_search_keeps_query_transient_and_retries_match_before_transition(): void
    {
        $selectionToken = str_repeat('c', 40);
        $servicePublicId = '01J00000000000000000000002';
        $projection = new TelegramNavigationOwnedServiceSearchProjection($selectionToken, $servicePublicId);
        $this->app->instance(TelegramOwnedServiceProjection::class, $projection);
        $telegramUserId = 9670;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(6700, $telegramUserId, 'navigation_search_fa', 'fa', '/start'));
        $processor->process('123456789', 6700);
        $servicesCallback = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_services')->first(['token_ciphertext']);
        self::assertNotNull($servicesCallback);
        $servicesToken = $this->app->make(StringEncrypter::class)->decryptString((string) $servicesCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6701, $telegramUserId, 'navigation_search_fa', 'fa', $servicesToken));
        $processor->process('123456789', 6701);

        $searchCallback = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.services.search')->first(['token_ciphertext']);
        self::assertNotNull($searchCallback);
        $searchToken = $this->app->make(StringEncrypter::class)->decryptString((string) $searchCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6702, $telegramUserId, 'navigation_search_fa', 'fa', $searchToken));
        $processor->process('123456789', 6702);
        $session = DB::table('telegram_interaction_sessions')->where('telegram_user_id', $telegramUserId)->first(['id', 'state', 'version', 'payload']);
        self::assertNotNull($session);
        self::assertSame('service_search', (string) $session->state);
        self::assertSame(3, (int) $session->version);
        self::assertSame('{"page":1}', (string) $session->payload);
        $prompt = $this->latestConfidentialPresentation();
        self::assertStringContainsString('جستجوی سرویس‌های من', $prompt);
        self::assertStringContainsString('شناسه دقیق سرویس', $prompt);

        $rawQuery = 'private-search-query-NEVER-PERSIST';
        $this->accept($this->payload(6703, $telegramUserId, 'navigation_search_fa', 'fa', $rawQuery));
        $processor->process('123456789', 6703);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => (int) $session->id, 'state' => 'service_search', 'version' => 3, 'payload' => '{"page":1}']);
        $notFound = $this->latestConfidentialPresentation();
        self::assertStringContainsString('سرویس منطبقی در حساب شما پیدا نشد', $notFound);
        self::assertStringNotContainsString($rawQuery, $notFound);
        self::assertStringNotContainsString($rawQuery, $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId));
        $operationCountAfterNotFound = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $processor->process('123456789', 6703);
        self::assertSame($operationCountAfterNotFound, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());

        $this->accept($this->payload(6704, $telegramUserId, 'navigation_search_fa', 'fa', 'ambiguous-search'));
        $processor->process('123456789', 6704);
        self::assertStringContainsString('بیش از یک سرویس شما این نام کاربری را دارد', $this->latestConfidentialPresentation());
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => (int) $session->id, 'state' => 'service_search', 'version' => 3]);

        $projection->detailAvailable = false;
        $transitionCountBeforeMatch = DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', (int) $session->id)->count();
        $this->accept($this->payload(6705, $telegramUserId, 'navigation_search_fa', 'fa', 'match-search'));
        try {
            $processor->process('123456789', 6705);
            self::fail('Matched search must not transition while the owner detail projection is unavailable.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6705, 'state' => 'failed', 'attempt_count' => 1]);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => (int) $session->id, 'state' => 'service_search', 'version' => 3]);
        self::assertSame($transitionCountBeforeMatch, DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', (int) $session->id)->count());

        $projection->detailAvailable = true;
        $processor->process('123456789', 6705);
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6705, 'state' => 'processed', 'attempt_count' => 2]);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => (int) $session->id, 'state' => 'service_detail', 'version' => 4, 'payload' => '{"page":1}']);
        self::assertSame($transitionCountBeforeMatch + 1, DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', (int) $session->id)->count());
        $detail = $this->latestConfidentialPresentation();
        self::assertStringContainsString($servicePublicId, $detail);
        self::assertStringContainsString('ذخیره‌شده', $detail);
        self::assertStringContainsString('موقتاً در دسترس نیست', $detail);
        self::assertStringContainsString('10.00 GiB', $detail);
        self::assertStringContainsString('3.00 GiB', $detail);
        self::assertStringNotContainsString('match-search', $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId));
    }

    public function test_my_services_search_post_dispatch_failure_replays_without_duplicate_callback_or_delivery(): void
    {
        $projection = new TelegramNavigationOwnedServiceSearchProjection(str_repeat('e', 40), '01J00000000000000000000004');
        $this->app->instance(TelegramOwnedServiceProjection::class, $projection);
        $telegramUserId = 9690;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(6900, $telegramUserId, 'navigation_search_retry', 'fa', '/start'));
        $processor->process('123456789', 6900);
        $services = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_services')->first(['token_ciphertext']);
        self::assertNotNull($services);
        $this->accept($this->callbackPayload(6901, $telegramUserId, 'navigation_search_retry', 'fa', $this->app->make(StringEncrypter::class)->decryptString((string) $services->token_ciphertext)));
        $processor->process('123456789', 6901);
        $search = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.services.search')->first(['token_ciphertext']);
        self::assertNotNull($search);
        $this->accept($this->callbackPayload(6902, $telegramUserId, 'navigation_search_retry', 'fa', $this->app->make(StringEncrypter::class)->decryptString((string) $search->token_ciphertext)));
        $processor->process('123456789', 6902);

        $session = DB::table('telegram_interaction_sessions')->where('telegram_user_id', $telegramUserId)->first(['id', 'state', 'version']);
        self::assertNotNull($session);
        self::assertSame('service_search', (string) $session->state);
        self::assertSame(3, (int) $session->version);

        $rawQuery = 'private-search-retry-NEVER-PERSIST';
        $this->accept($this->payload(6903, $telegramUserId, 'navigation_search_retry', 'fa', $rawQuery));
        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_navigation_test_fail_processed_6903
BEFORE UPDATE ON processed_telegram_updates
FOR EACH ROW
BEGIN
    IF OLD.bot_id = '123456789' AND OLD.update_id = 6903 AND NEW.state = 'processed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-post-service-search-dispatch-failure';
    END IF;
END
SQL);
        try {
            try {
                $processor->process('123456789', 6903);
                self::fail('The simulated post-search-dispatch failure must keep the search update retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
                self::assertStringNotContainsString($rawQuery, $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_6903');
        }

        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6903, 'state' => 'failed', 'attempt_count' => 1]);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => (int) $session->id, 'state' => 'service_search', 'version' => 3, 'payload' => '{"page":1}']);
        $errorEvidence = DB::table('processed_telegram_updates')->where('update_id', 6903)->first(['last_error_class', 'last_error_code']);
        self::assertNotNull($errorEvidence);
        self::assertStringNotContainsString($rawQuery, json_encode($errorEvidence, JSON_THROW_ON_ERROR));

        $transitionCount = DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', (int) $session->id)->count();
        $backCallbackCount = DB::table('telegram_interaction_callbacks')->where('telegram_interaction_session_id', (int) $session->id)->where('action', 'navigation.back')->count();
        $operationCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $outboxCount = DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count();
        $confidentialCount = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)->count();
        self::assertStringNotContainsString($rawQuery, $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId));

        $processor->process('123456789', 6903);

        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6903, 'state' => 'processed', 'attempt_count' => 2]);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => (int) $session->id, 'state' => 'service_search', 'version' => 3, 'payload' => '{"page":1}']);
        self::assertSame($transitionCount, DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', (int) $session->id)->count());
        self::assertSame($backCallbackCount, DB::table('telegram_interaction_callbacks')->where('telegram_interaction_session_id', (int) $session->id)->where('action', 'navigation.back')->count());
        self::assertSame($operationCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());
        self::assertSame($outboxCount, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());
        self::assertSame($confidentialCount, DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)->count());
        self::assertStringNotContainsString($rawQuery, $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId));
    }

    public function test_my_services_search_english_prompt_not_found_ambiguous_and_cached_detail_copy(): void
    {
        $selectionToken = str_repeat('d', 40);
        $projection = new TelegramNavigationOwnedServiceSearchProjection($selectionToken, '01J00000000000000000000003');
        $this->app->instance(TelegramOwnedServiceProjection::class, $projection);
        $telegramUserId = 9680;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(6800, $telegramUserId, 'navigation_search_en', 'en', '/start'));
        $processor->process('123456789', 6800);
        $services = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_services')->first(['token_ciphertext']);
        self::assertNotNull($services);
        $this->accept($this->callbackPayload(6801, $telegramUserId, 'navigation_search_en', 'en', $this->app->make(StringEncrypter::class)->decryptString((string) $services->token_ciphertext)));
        $processor->process('123456789', 6801);
        $search = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.services.search')->first(['token_ciphertext']);
        self::assertNotNull($search);
        $this->accept($this->callbackPayload(6802, $telegramUserId, 'navigation_search_en', 'en', $this->app->make(StringEncrypter::class)->decryptString((string) $search->token_ciphertext)));
        $processor->process('123456789', 6802);
        self::assertStringContainsString('Search My Services', $this->latestConfidentialPresentation());
        $searchBack = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.back')
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($searchBack);
        $this->accept($this->callbackPayload(6803, $telegramUserId, 'navigation_search_en', 'en', $this->app->make(StringEncrypter::class)->decryptString((string) $searchBack->token_ciphertext)));
        $processor->process('123456789', 6803);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['telegram_user_id' => $telegramUserId, 'state' => 'my_services', 'version' => 4, 'payload' => '{"page":1}']);
        $searchAgain = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.services.search')->orderByDesc('id')->first(['token_ciphertext']);
        self::assertNotNull($searchAgain);
        $this->accept($this->callbackPayload(6804, $telegramUserId, 'navigation_search_en', 'en', $this->app->make(StringEncrypter::class)->decryptString((string) $searchAgain->token_ciphertext)));
        $processor->process('123456789', 6804);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['telegram_user_id' => $telegramUserId, 'state' => 'service_search', 'version' => 5, 'payload' => '{"page":1}']);

        $this->accept($this->payload(6805, $telegramUserId, 'navigation_search_en', 'en', 'unknown-en'));
        $processor->process('123456789', 6805);
        self::assertStringContainsString('No matching service was found in your account.', $this->latestConfidentialPresentation());
        $this->accept($this->payload(6806, $telegramUserId, 'navigation_search_en', 'en', 'ambiguous-search'));
        $processor->process('123456789', 6806);
        self::assertStringContainsString('More than one of your services uses that username.', $this->latestConfidentialPresentation());
        $this->accept($this->payload(6807, $telegramUserId, 'navigation_search_en', 'en', 'cached-en'));
        $processor->process('123456789', 6807);
        $detail = $this->latestConfidentialPresentation();
        self::assertStringContainsString('Cached — current synchronization unavailable', $detail);
        self::assertStringContainsString('Temporarily unavailable', $detail);
        self::assertStringContainsString('Search plan', $detail);
        self::assertStringNotContainsString('پلن جستجو', $detail);
    }

    public function test_customer_purchase_catalog_uses_existing_navigation_confidential_delivery_and_replay_fences(): void
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog;
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $telegramUserId = 9690;
        $otherTelegramUserId = 9691;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(6950, $telegramUserId, 'navigation_purchase', 'fa', '/start'));
        $processor->process('123456789', 6950);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id', 'state', 'version', 'payload']);
        self::assertNotNull($session);
        self::assertSame('home', (string) $session->state);
        self::assertSame(1, (int) $session->version);

        $purchase = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('action', 'navigation.purchase')
            ->first(['action_payload', 'token_ciphertext']);
        self::assertNotNull($purchase);
        self::assertSame('{}', (string) $purchase->action_payload);
        $purchaseToken = $this->app->make(StringEncrypter::class)->decryptString((string) $purchase->token_ciphertext);
        $before = $this->purchaseMutationCounts();

        $this->accept($this->payload(6951, $otherTelegramUserId, 'navigation_purchase_other', 'fa', '/start'));
        $processor->process('123456789', 6951);
        $this->accept($this->callbackPayload(6952, $otherTelegramUserId, 'navigation_purchase_other', 'fa', $purchaseToken));
        $processor->process('123456789', 6952);
        self::assertSame([], $catalog->pageCalls);
        self::assertSame($before, $this->purchaseMutationCounts());

        $this->accept($this->callbackPayload(6953, $telegramUserId, 'navigation_purchase', 'fa', $purchaseToken));
        $processor->process('123456789', 6953);
        self::assertSame([[
            'actor_user_id' => (int) $account->user_id,
            'subject_user_id' => (int) $account->user_id,
            'page' => 1,
            'page_size' => 6,
        ]], $catalog->pageCalls);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'purchase_catalog',
            'version' => 2,
            'payload' => '{"page":1}',
        ]);
        $catalogPresentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('خرید سرویس', $catalogPresentation);
        self::assertStringContainsString('پلن خرید — نسخه پایه', $catalogPresentation);
        self::assertStringContainsString('900,000', $catalogPresentation);
        self::assertSame($before, $this->purchaseMutationCounts());

        $item = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 2)
            ->where('action', 'navigation.purchase.'.str_repeat('c', 40))
            ->first(['action_payload', 'token_ciphertext']);
        $staleSibling = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 2)
            ->where('action', 'navigation.purchase.'.str_repeat('d', 40))
            ->first(['token_ciphertext']);
        self::assertNotNull($item);
        self::assertNotNull($staleSibling);
        self::assertSame('{}', (string) $item->action_payload);
        $itemToken = $this->app->make(StringEncrypter::class)->decryptString((string) $item->token_ciphertext);
        $staleSiblingToken = $this->app->make(StringEncrypter::class)->decryptString((string) $staleSibling->token_ciphertext);
        $common = $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId);
        self::assertStringNotContainsString('plan_offering_id', $common);
        self::assertStringNotContainsString('sales_server_id', $common);
        self::assertStringNotContainsString('panel_service_target_id', $common);
        self::assertStringNotContainsString('panel_connection_id', $common);
        self::assertStringNotContainsString('پلن خرید — نسخه پایه', $common);
        self::assertStringNotContainsString('900,000', $common);

        $operationCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $processor->process('123456789', 6953);
        self::assertCount(1, $catalog->pageCalls);
        self::assertSame($operationCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());

        $this->accept($this->callbackPayload(6954, $telegramUserId, 'navigation_purchase', 'fa', $itemToken));
        $processor->process('123456789', 6954);
        self::assertSame([[
            'actor_user_id' => (int) $account->user_id,
            'subject_user_id' => (int) $account->user_id,
            'selection_token' => str_repeat('c', 40),
        ]], $catalog->offeringCalls);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'purchase_offering',
            'version' => 3,
            'payload' => json_encode([
                'offering_selection' => str_repeat('c', 40),
                'page' => 1,
            ], JSON_THROW_ON_ERROR),
        ]);
        $detail = $this->latestConfidentialPresentation();
        self::assertStringContainsString('گزینه سرویس', $detail);
        self::assertStringContainsString('هنوز هیچ پیش‌فاکتور، پرداخت، رزرو ظرفیت، سفارش یا پروویژنینگی ایجاد نشده است.', $detail);
        self::assertSame($before, $this->purchaseMutationCounts());

        $back = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 3)
            ->where('action', 'navigation.back')
            ->first(['token_ciphertext']);
        self::assertNotNull($back);
        $backToken = $this->app->make(StringEncrypter::class)->decryptString((string) $back->token_ciphertext);
        $this->accept($this->callbackPayload(6955, $telegramUserId, 'navigation_purchase', 'fa', $backToken));
        $processor->process('123456789', 6955);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'purchase_catalog',
            'version' => 4,
            'payload' => '{"page":1}',
        ]);
        self::assertCount(2, $catalog->pageCalls);

        $this->accept($this->callbackPayload(6956, $telegramUserId, 'navigation_purchase', 'fa', $staleSiblingToken));
        $processor->process('123456789', 6956);
        self::assertCount(1, $catalog->offeringCalls);
        self::assertSame($before, $this->purchaseMutationCounts());
    }

    public function test_customer_purchase_quote_preview_uses_callback_identity_confidential_state_and_replay_fences(): void
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog;
        $quotes = new TelegramNavigationCustomerPurchaseQuote($catalog);
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->app->instance(TelegramCustomerPurchaseQuote::class, $quotes);
        $telegramUserId = 9699;
        $otherTelegramUserId = 9700;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(6980, $telegramUserId, 'navigation_purchase_quote', 'fa', '/start'));
        $processor->process('123456789', 6980);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id', 'state', 'version']);
        self::assertNotNull($session);

        $purchase = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 1)
            ->where('action', 'navigation.purchase')
            ->first(['token_ciphertext']);
        self::assertNotNull($purchase);
        $purchaseToken = $this->app->make(StringEncrypter::class)->decryptString((string) $purchase->token_ciphertext);
        $this->accept($this->callbackPayload(6981, $telegramUserId, 'navigation_purchase_quote', 'fa', $purchaseToken));
        $processor->process('123456789', 6981);

        $offering = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 2)
            ->where('action', 'navigation.purchase.'.str_repeat('c', 40))
            ->first(['token_ciphertext']);
        self::assertNotNull($offering);
        $offeringToken = $this->app->make(StringEncrypter::class)->decryptString((string) $offering->token_ciphertext);
        $this->accept($this->callbackPayload(6982, $telegramUserId, 'navigation_purchase_quote', 'fa', $offeringToken));
        $processor->process('123456789', 6982);

        $quote = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 3)
            ->where('action', 'navigation.purchase.quote')
            ->first(['public_id', 'action_payload', 'token_ciphertext']);
        self::assertNotNull($quote);
        self::assertSame('{}', (string) $quote->action_payload);
        $quoteToken = $this->app->make(StringEncrypter::class)->decryptString((string) $quote->token_ciphertext);
        $before = $this->purchaseMutationCounts();

        $this->accept($this->payload(6983, $otherTelegramUserId, 'navigation_purchase_quote_other', 'fa', '/start'));
        $processor->process('123456789', 6983);
        $this->accept($this->callbackPayload(6984, $otherTelegramUserId, 'navigation_purchase_quote_other', 'fa', $quoteToken));
        $processor->process('123456789', 6984);
        self::assertSame([], $quotes->calls);
        self::assertSame($before, $this->purchaseMutationCounts());

        $this->accept($this->callbackPayload(6985, $telegramUserId, 'navigation_purchase_quote', 'fa', $quoteToken));
        $processor->process('123456789', 6985);
        self::assertCount(1, $quotes->calls);
        $quoteCall = $quotes->latestCall();
        self::assertSame((int) $account->user_id, $quoteCall['actor_user_id']);
        self::assertSame((int) $account->user_id, $quoteCall['subject_user_id']);
        self::assertSame(str_repeat('c', 40), $quoteCall['selection_token']);
        self::assertSame('telegram-purchase-quote:'.(string) $quote->public_id, $quoteCall['quote_key']);
        self::assertInstanceOf(DateTimeImmutable::class, $quoteCall['accepted_at']);
        self::assertSame('tg-purchase-quote:'.(string) $quote->public_id, $quoteCall['correlation_id']);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'purchase_quote',
            'version' => 5,
            'payload' => json_encode([
                'offering_selection' => str_repeat('c', 40),
                'page' => 1,
                'quote_configuration_hash' => str_repeat('a', 64),
                'quote_public_id' => str_pad('01K', 26, '0'),
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(1, DB::table('telegram_interaction_transitions')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('to_state', 'purchase_quote_submitting')
            ->count());
        $presentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('پیش‌فاکتور خرید سرویس', $presentation);
        self::assertStringContainsString(str_pad('01K', 26, '0'), $presentation);
        self::assertStringContainsString('900,000 IRR', $presentation);
        self::assertStringContainsString('تخفیف: 0 IRR', $presentation);
        self::assertStringContainsString('هنوز هیچ پرداخت، رزرو ظرفیت، سفارش یا پروویژنینگی ایجاد نشده است.', $presentation);
        self::assertSame($before, $this->purchaseMutationCounts());

        $common = $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId);
        self::assertStringContainsString(str_pad('01K', 26, '0'), $common);
        self::assertStringContainsString(str_repeat('a', 64), $common);
        self::assertStringNotContainsString('plan_offering_id', $common);
        self::assertStringNotContainsString('sales_server_id', $common);
        self::assertStringNotContainsString('panel_service_target_id', $common);
        self::assertStringNotContainsString('purchase-standard', $common);

        $operationCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $processor->process('123456789', 6985);
        self::assertCount(1, $quotes->calls);
        self::assertSame($operationCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());

        $back = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 5)
            ->where('action', 'navigation.back')
            ->first(['token_ciphertext']);
        self::assertNotNull($back);
        $backToken = $this->app->make(StringEncrypter::class)->decryptString((string) $back->token_ciphertext);
        $this->accept($this->callbackPayload(6986, $telegramUserId, 'navigation_purchase_quote', 'fa', $backToken));
        $processor->process('123456789', 6986);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'purchase_offering',
            'version' => 6,
            'payload' => json_encode([
                'offering_selection' => str_repeat('c', 40),
                'page' => 1,
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertStringContainsString('گزینه سرویس', $this->latestConfidentialPresentation());

        DB::table('users')->where('id', (int) $account->user_id)->update([
            'locale' => 'en',
            'updated_at' => now('UTC'),
        ]);
        $englishQuote = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 6)
            ->where('action', 'navigation.purchase.quote')
            ->first(['token_ciphertext']);
        self::assertNotNull($englishQuote);
        $englishQuoteToken = $this->app->make(StringEncrypter::class)->decryptString((string) $englishQuote->token_ciphertext);
        $this->accept($this->callbackPayload(6987, $telegramUserId, 'navigation_purchase_quote', 'en', $englishQuoteToken));
        $processor->process('123456789', 6987);
        self::assertCount(2, $quotes->calls);
        $englishPresentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('Service Purchase Quote', $englishPresentation);
        self::assertStringContainsString('Final amount: 900,000 IRR', $englishPresentation);
        self::assertStringContainsString('No payment, capacity reservation, order, or provisioning has been created yet.', $englishPresentation);
        self::assertStringNotContainsString('پیش‌فاکتور خرید سرویس', $englishPresentation);

        $this->accept($this->callbackPayload(6988, $telegramUserId, 'navigation_purchase_quote', 'en', $quoteToken));
        $processor->process('123456789', 6988);
        self::assertCount(2, $quotes->calls);
        self::assertSame($before, $this->purchaseMutationCounts());
    }

    public function test_customer_purchase_discount_requote_keeps_plaintext_out_of_durable_state_and_rebuilds_pay_001_from_new_quote(): void
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog;
        $quotes = new TelegramNavigationCustomerPurchaseQuote($catalog);
        $discounts = new TelegramNavigationCustomerPurchaseDiscountQuote($catalog);
        $paymentMethods = new TelegramNavigationCustomerPurchasePaymentMethods;
        $purchaseOrders = new TelegramNavigationCustomerPurchaseOrder;
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->app->instance(TelegramCustomerPurchaseQuote::class, $quotes);
        $this->app->instance(TelegramCustomerPurchaseDiscountQuote::class, $discounts);
        $this->app->instance(TelegramCustomerPurchasePaymentMethods::class, $paymentMethods);
        $this->app->instance(TelegramCustomerPurchaseOrder::class, $purchaseOrders);
        $telegramUserId = 9714;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7130, $telegramUserId, 'navigation_purchase_discount', 'fa', '/start'));
        $processor->process('123456789', 7130);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        foreach ([
            [7131, 'navigation.purchase'],
            [7132, 'navigation.purchase.'.str_repeat('c', 40)],
            [7133, 'navigation.purchase.quote'],
            [7134, 'navigation.purchase.discount'],
        ] as [$updateId, $action]) {
            $token = $this->callbackToken($action, (int) $account->id);
            $this->accept($this->callbackPayload($updateId, $telegramUserId, 'navigation_purchase_discount', 'fa', $token));
            $processor->process('123456789', $updateId);
        }

        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id', 'state', 'version', 'payload']);
        self::assertNotNull($session);
        self::assertSame('purchase_discount_input', (string) $session->state);
        self::assertSame(6, (int) $session->version);
        self::assertStringContainsString('کد تخفیف را در یک پیام ارسال کنید', $this->latestConfidentialPresentation());

        $rawCode = 'PrivateDiscount-7XQ';
        $this->accept($this->payload(7135, $telegramUserId, 'navigation_purchase_discount', 'fa', $rawCode));
        $processor->process('123456789', 7135);
        self::assertCount(1, $discounts->calls);
        $call = $discounts->calls[0];
        self::assertSame((int) $account->user_id, $call['actor_user_id']);
        self::assertSame((int) $account->user_id, $call['subject_user_id']);
        self::assertSame(str_repeat('c', 40), $call['selection_token']);
        self::assertSame(str_pad('01K', 26, '0'), $call['source_quote_public_id']);
        self::assertSame(str_repeat('a', 64), $call['source_quote_configuration_hash']);
        self::assertSame($rawCode, $call['code']);
        self::assertInstanceOf(DateTimeImmutable::class, $call['accepted_at']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $call['operation_key']);

        $after = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->first(['state', 'version', 'payload']);
        self::assertNotNull($after);
        self::assertSame('purchase_quote', (string) $after->state);
        self::assertSame(8, (int) $after->version);
        self::assertSame([
            'discount_consumption_configuration_hash' => str_repeat('c', 64),
            'discount_consumption_public_id' => str_pad('01C', 26, '0'),
            'offering_selection' => str_repeat('c', 40),
            'page' => 1,
            'promotion_resolution_public_id' => str_pad('01R', 26, '0'),
            'quote_configuration_hash' => str_repeat('d', 64),
            'quote_public_id' => str_pad('01D', 26, '0'),
        ], json_decode((string) $after->payload, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(1, DB::table('telegram_interaction_transitions')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('to_state', 'purchase_discount_submitting')
            ->count());
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 8)
            ->where('action', 'navigation.purchase.discount')
            ->count());

        $presentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('پیش‌فاکتور خرید سرویس', $presentation);
        self::assertStringContainsString('تخفیف: 90,000 IRR', $presentation);
        self::assertStringContainsString('مبلغ نهایی: 810,000 IRR', $presentation);
        self::assertStringNotContainsString($rawCode, $presentation);
        $common = $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId);
        self::assertStringNotContainsString($rawCode, $common);
        self::assertStringContainsString(str_pad('01C', 26, '0'), $common);
        self::assertStringContainsString(str_pad('01R', 26, '0'), $common);
        self::assertStringContainsString(str_pad('01D', 26, '0'), $common);
        self::assertStringNotContainsString('benefit_code_discount_grant_id', $common);
        self::assertStringNotContainsString('pricing_rule_version_id', $common);
        self::assertStringNotContainsString('rule_code', $common);

        $operationCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $processor->process('123456789', 7135);
        self::assertCount(1, $discounts->calls);
        self::assertSame($operationCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());

        $paymentMethods->quotePublicId = str_pad('01D', 26, '0');
        $paymentMethods->quoteConfigurationHash = str_repeat('d', 64);
        $purchaseOrders->quotePublicId = str_pad('01D', 26, '0');
        $purchaseOrders->quoteConfigurationHash = str_repeat('d', 64);
        $paymentToken = $this->callbackToken('navigation.purchase.payment_methods', (int) $account->id);
        $this->accept($this->callbackPayload(7136, $telegramUserId, 'navigation_purchase_discount', 'fa', $paymentToken));
        $processor->process('123456789', 7136);
        self::assertCount(1, $paymentMethods->calls);
        $paymentCall = $paymentMethods->latestCall();
        self::assertSame(str_pad('01D', 26, '0'), $paymentCall['quote_public_id']);
        self::assertSame(str_repeat('d', 64), $paymentCall['quote_configuration_hash']);
        $paymentState = DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->first(['state', 'payload']);
        self::assertNotNull($paymentState);
        self::assertSame('purchase_payment_methods', (string) $paymentState->state);
        $paymentPayload = json_decode((string) $paymentState->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(str_pad('01C', 26, '0'), $paymentPayload['discount_consumption_public_id']);
        self::assertSame(str_pad('01R', 26, '0'), $paymentPayload['promotion_resolution_public_id']);
        self::assertSame(str_pad('01D', 26, '0'), $paymentPayload['quote_public_id']);
        self::assertSame(str_pad('01N', 26, '0'), $paymentPayload['order_public_id']);
        self::assertCount(1, $purchaseOrders->openCalls);
        self::assertSame(str_pad('01D', 26, '0'), $purchaseOrders->openCalls[0]['quote_public_id']);
        self::assertSame(str_repeat('d', 64), $purchaseOrders->openCalls[0]['quote_configuration_hash']);
        self::assertStringNotContainsString($rawCode, (string) $paymentState->payload);
    }

    public function test_invalid_purchase_discount_keeps_input_state_and_never_echoes_plaintext(): void
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog;
        $quotes = new TelegramNavigationCustomerPurchaseQuote($catalog);
        $discounts = new TelegramNavigationCustomerPurchaseDiscountQuote($catalog);
        $discounts->reject = true;
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->app->instance(TelegramCustomerPurchaseQuote::class, $quotes);
        $this->app->instance(TelegramCustomerPurchaseDiscountQuote::class, $discounts);
        $telegramUserId = 9715;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7140, $telegramUserId, 'navigation_purchase_discount_reject', 'en', '/start'));
        $processor->process('123456789', 7140);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        foreach ([
            [7141, 'navigation.purchase'],
            [7142, 'navigation.purchase.'.str_repeat('c', 40)],
            [7143, 'navigation.purchase.quote'],
            [7144, 'navigation.purchase.discount'],
        ] as [$updateId, $action]) {
            $token = $this->callbackToken($action, (int) $account->id);
            $this->accept($this->callbackPayload($updateId, $telegramUserId, 'navigation_purchase_discount_reject', 'en', $token));
            $processor->process('123456789', $updateId);
        }
        $rawCode = 'RejectedSecret-Code-91';
        $this->accept($this->payload(7145, $telegramUserId, 'navigation_purchase_discount_reject', 'en', $rawCode));
        $processor->process('123456789', 7145);

        self::assertCount(1, $discounts->calls);
        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->first(['id', 'state', 'version', 'payload']);
        self::assertNotNull($session);
        self::assertSame('purchase_discount_input', (string) $session->state);
        self::assertSame(6, (int) $session->version);
        self::assertSame([
            'offering_selection' => str_repeat('c', 40),
            'page' => 1,
            'quote_configuration_hash' => str_repeat('a', 64),
            'quote_public_id' => str_pad('01K', 26, '0'),
        ], json_decode((string) $session->payload, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(0, DB::table('telegram_interaction_transitions')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('to_state', 'purchase_discount_submitting')
            ->count());
        $presentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('The discount code or current Quote cannot be applied.', $presentation);
        self::assertStringNotContainsString($rawCode, $presentation);
        self::assertStringNotContainsString($rawCode, $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId));
    }

    public function test_stale_discount_source_returns_to_fresh_catalog_without_failed_update_or_plaintext_leak(): void
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog;
        $quotes = new TelegramNavigationCustomerPurchaseQuote($catalog);
        $discounts = new TelegramNavigationCustomerPurchaseDiscountQuote($catalog);
        $discounts->refreshRequired = true;
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->app->instance(TelegramCustomerPurchaseQuote::class, $quotes);
        $this->app->instance(TelegramCustomerPurchaseDiscountQuote::class, $discounts);
        $telegramUserId = 9717;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7160, $telegramUserId, 'navigation_purchase_discount_refresh', 'en', '/start'));
        $processor->process('123456789', 7160);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        foreach ([
            [7161, 'navigation.purchase'],
            [7162, 'navigation.purchase.'.str_repeat('c', 40)],
            [7163, 'navigation.purchase.quote'],
            [7164, 'navigation.purchase.discount'],
        ] as [$updateId, $action]) {
            $token = $this->callbackToken($action, (int) $account->id);
            $this->accept($this->callbackPayload($updateId, $telegramUserId, 'navigation_purchase_discount_refresh', 'en', $token));
            $processor->process('123456789', $updateId);
        }

        $rawCode = 'StaleSourceSecret-Code-41';
        $this->accept($this->payload(7165, $telegramUserId, 'navigation_purchase_discount_refresh', 'en', $rawCode));
        $processor->process('123456789', 7165);

        self::assertCount(1, $discounts->calls);
        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->first(['id', 'state', 'version', 'payload']);
        self::assertNotNull($session);
        self::assertSame('purchase_catalog', (string) $session->state);
        self::assertSame(7, (int) $session->version);
        self::assertSame(['page' => 1], json_decode((string) $session->payload, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(0, DB::table('telegram_interaction_transitions')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('to_state', 'purchase_discount_submitting')
            ->count());
        $presentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('Buy Service', $presentation);
        self::assertStringNotContainsString('The discount code or current Quote cannot be applied.', $presentation);
        self::assertStringNotContainsString($rawCode, $presentation);
        self::assertStringNotContainsString($rawCode, $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId));
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 7165, 'state' => 'processed']);
    }

    public function test_prebound_discount_message_and_back_race_fails_closed_before_discount_authority(): void
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog;
        $quotes = new TelegramNavigationCustomerPurchaseQuote($catalog);
        $discounts = new TelegramNavigationCustomerPurchaseDiscountQuote($catalog);
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->app->instance(TelegramCustomerPurchaseQuote::class, $quotes);
        $this->app->instance(TelegramCustomerPurchaseDiscountQuote::class, $discounts);
        $telegramUserId = 9716;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7150, $telegramUserId, 'navigation_purchase_discount_race', 'en', '/start'));
        $processor->process('123456789', 7150);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        foreach ([
            [7151, 'navigation.purchase'],
            [7152, 'navigation.purchase.'.str_repeat('c', 40)],
            [7153, 'navigation.purchase.quote'],
            [7154, 'navigation.purchase.discount'],
        ] as [$updateId, $action]) {
            $token = $this->callbackToken($action, (int) $account->id);
            $this->accept($this->callbackPayload($updateId, $telegramUserId, 'navigation_purchase_discount_race', 'en', $token));
            $processor->process('123456789', $updateId);
        }
        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->first(['id', 'state', 'version']);
        self::assertNotNull($session);
        self::assertSame('purchase_discount_input', (string) $session->state);
        self::assertSame(6, (int) $session->version);

        $rawCode = 'RaceSecret-Code-31';
        $this->accept($this->payload(7155, $telegramUserId, 'navigation_purchase_discount_race', 'en', $rawCode));
        $backToken = $this->callbackToken('navigation.back', (int) $account->id);
        $this->accept($this->callbackPayload(7156, $telegramUserId, 'navigation_purchase_discount_race', 'en', $backToken));
        $bindings = $this->app->make(TelegramInteractionUpdateBindingService::class);
        $messageBinding = $bindings->bind(
            '123456789',
            7155,
            (int) $account->id,
            'message',
            'telegram-update:123456789:7155:message',
        );
        $backAcceptance = $this->app->make(TelegramInteractionCallbackService::class)
            ->accept('123456789', $telegramUserId, $backToken, 7156);
        self::assertSame($messageBinding->sessionVersion, $backAcceptance->sessionVersion);

        $handler = $this->app->make(TelegramNavigationHandler::class);
        $handler->handle($this->callbackActionFromAcceptance($backAcceptance, 7156, $telegramUserId));
        $handler->handle($this->messageActionFromBinding($messageBinding, 7155, $rawCode));

        self::assertSame([], $discounts->calls);
        $after = DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->first(['state', 'version', 'payload']);
        self::assertNotNull($after);
        self::assertSame('purchase_quote', (string) $after->state);
        self::assertSame(7, (int) $after->version);
        self::assertSame(0, DB::table('telegram_interaction_transitions')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('to_state', 'purchase_discount_submitting')
            ->count());
        self::assertStringNotContainsString($rawCode, $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId));
    }

    public function test_customer_purchase_payment_method_discovery_is_confidential_replay_safe_and_back_fails_closed(): void
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog;
        $quotes = new TelegramNavigationCustomerPurchaseQuote($catalog);
        $paymentMethods = new TelegramNavigationCustomerPurchasePaymentMethods;
        $purchaseOrders = new TelegramNavigationCustomerPurchaseOrder;
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->app->instance(TelegramCustomerPurchaseQuote::class, $quotes);
        $this->app->instance(TelegramCustomerPurchasePaymentMethods::class, $paymentMethods);
        $this->app->instance(TelegramCustomerPurchaseOrder::class, $purchaseOrders);
        $telegramUserId = 9710;
        $otherTelegramUserId = 9711;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7100, $telegramUserId, 'navigation_purchase_payment_methods', 'fa', '/start'));
        $processor->process('123456789', 7100);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);

        $purchaseToken = $this->callbackToken('navigation.purchase', (int) $account->id);
        $this->accept($this->callbackPayload(7101, $telegramUserId, 'navigation_purchase_payment_methods', 'fa', $purchaseToken));
        $processor->process('123456789', 7101);
        $offeringToken = $this->callbackToken('navigation.purchase.'.str_repeat('c', 40), (int) $account->id);
        $this->accept($this->callbackPayload(7102, $telegramUserId, 'navigation_purchase_payment_methods', 'fa', $offeringToken));
        $processor->process('123456789', 7102);
        $quoteToken = $this->callbackToken('navigation.purchase.quote', (int) $account->id);
        $this->accept($this->callbackPayload(7103, $telegramUserId, 'navigation_purchase_payment_methods', 'fa', $quoteToken));
        $processor->process('123456789', 7103);

        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id', 'state', 'version']);
        self::assertNotNull($session);
        self::assertSame('purchase_quote', (string) $session->state);
        self::assertSame(5, (int) $session->version);

        $paymentCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 5)
            ->where('action', 'navigation.purchase.payment_methods')
            ->first(['public_id', 'action_payload', 'token_ciphertext']);
        self::assertNotNull($paymentCallback);
        self::assertSame('{}', (string) $paymentCallback->action_payload);
        $paymentToken = $this->app->make(StringEncrypter::class)->decryptString((string) $paymentCallback->token_ciphertext);
        $before = $this->purchaseMutationCounts();

        $this->accept($this->payload(7104, $otherTelegramUserId, 'navigation_purchase_payment_methods_other', 'fa', '/start'));
        $processor->process('123456789', 7104);
        $this->accept($this->callbackPayload(7105, $otherTelegramUserId, 'navigation_purchase_payment_methods_other', 'fa', $paymentToken));
        $processor->process('123456789', 7105);
        self::assertSame([], $paymentMethods->calls);
        self::assertSame($before, $this->purchaseMutationCounts());

        $this->accept($this->callbackPayload(7106, $telegramUserId, 'navigation_purchase_payment_methods', 'fa', $paymentToken));
        $processor->process('123456789', 7106);
        self::assertCount(1, $paymentMethods->calls);
        $call = $paymentMethods->latestCall();
        self::assertSame((int) $account->user_id, $call['actor_user_id']);
        self::assertSame((int) $account->user_id, $call['subject_user_id']);
        self::assertSame(str_pad('01K', 26, '0'), $call['quote_public_id']);
        self::assertSame(str_repeat('a', 64), $call['quote_configuration_hash']);
        self::assertSame('telegram-purchase-payment-methods:'.(string) $paymentCallback->public_id, $call['decision_key']);

        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'purchase_payment_methods',
            'version' => 7,
            'payload' => json_encode([
                'offering_selection' => str_repeat('c', 40),
                'order_public_id' => str_pad('01N', 26, '0'),
                'page' => 1,
                'payment_decision_configuration_hash' => str_repeat('b', 64),
                'payment_decision_public_id' => str_pad('01P', 26, '0'),
                'quote_configuration_hash' => str_repeat('a', 64),
                'quote_public_id' => str_pad('01K', 26, '0'),
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(1, DB::table('telegram_interaction_transitions')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('to_state', 'purchase_payment_methods_submitting')
            ->count());

        $presentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('روش‌های پرداخت مجاز', $presentation);
        self::assertStringContainsString('1. کیف پول', $presentation);
        self::assertStringContainsString('2. زرین‌پال', $presentation);
        self::assertStringContainsString('3. روش پرداخت #3', $presentation);
        self::assertStringNotContainsString('future_gateway', $presentation);
        self::assertStringContainsString('سفارش در وضعیت انتظار پرداخت', $presentation);
        self::assertStringContainsString(str_pad('01N', 26, '0'), $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId));
        self::assertCount(1, $purchaseOrders->openCalls);
        self::assertSame($before, $this->purchaseMutationCounts());

        $common = $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId);
        self::assertStringContainsString(str_pad('01P', 26, '0'), $common);
        self::assertStringContainsString(str_repeat('b', 64), $common);
        self::assertStringContainsString('future_gateway', $common);
        self::assertStringNotContainsString('payment_method_version_id', $common);
        self::assertStringNotContainsString('rule_code', $common);
        self::assertStringNotContainsString('health', $common);

        $operationCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $processor->process('123456789', 7106);
        self::assertCount(1, $paymentMethods->calls);
        self::assertCount(1, $purchaseOrders->openCalls);
        self::assertSame($operationCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());

        $walletCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 7)
            ->where('action', 'navigation.purchase.payment_method.select')
            ->where('action_payload', json_encode(['method_code' => 'wallet'], JSON_THROW_ON_ERROR))
            ->first(['public_id', 'action_payload', 'token_ciphertext']);
        self::assertNotNull($walletCallback);
        self::assertSame('{"method_code":"wallet"}', (string) $walletCallback->action_payload);
        $walletToken = $this->app->make(StringEncrypter::class)->decryptString((string) $walletCallback->token_ciphertext);
        $this->accept($this->callbackPayload(7107, $telegramUserId, 'navigation_purchase_payment_methods', 'fa', $walletToken));
        $processor->process('123456789', 7107);

        $selected = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->first(['state', 'version', 'payload']);
        self::assertNotNull($selected);
        self::assertSame('purchase_payment_method_selected', (string) $selected->state);
        self::assertSame(9, (int) $selected->version);
        /** @var array<string,mixed> $selectedPayload */
        $selectedPayload = json_decode((string) $selected->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('wallet', $selectedPayload['payment_method_code'] ?? null);
        self::assertSame(str_pad('01N', 26, '0'), $selectedPayload['order_public_id'] ?? null);
        self::assertSame(str_pad('01P', 26, '0'), $selectedPayload['payment_decision_public_id'] ?? null);
        self::assertSame(str_pad('01K', 26, '0'), $selectedPayload['quote_public_id'] ?? null);
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertStringContainsString('کیف پول', $this->latestConfidentialPresentation());
        self::assertStringContainsString('انتظار پرداخت', $this->latestConfidentialPresentation());

        $selectedOperationCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $processor->process('123456789', 7107);
        self::assertSame(9, (int) DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->value('version'));
        self::assertSame($selectedOperationCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());

        $selectedBackToken = $this->callbackToken('navigation.back', (int) $account->id);
        $this->accept($this->callbackPayload(7108, $telegramUserId, 'navigation_purchase_payment_methods', 'fa', $selectedBackToken));
        $processor->process('123456789', 7108);
        $methodsAgain = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->first(['state', 'version', 'payload']);
        self::assertNotNull($methodsAgain);
        self::assertSame('purchase_payment_methods', (string) $methodsAgain->state);
        self::assertSame(10, (int) $methodsAgain->version);
        self::assertCount(1, $paymentMethods->calls);
        self::assertCount(1, $purchaseOrders->openCalls);
        self::assertSame(0, DB::table('payment_intents')->count());

        $catalog->offeringAvailable = false;
        $backToken = $this->callbackToken('navigation.back', (int) $account->id);
        $this->accept($this->callbackPayload(7109, $telegramUserId, 'navigation_purchase_payment_methods', 'fa', $backToken));
        $processor->process('123456789', 7109);
        $after = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->first(['state', 'version', 'payload']);
        self::assertNotNull($after);
        self::assertSame('purchase_catalog', (string) $after->state);
        self::assertSame(11, (int) $after->version);
        self::assertSame('{"page":1}', (string) $after->payload);
        self::assertCount(1, $paymentMethods->calls);
        self::assertCount(1, $purchaseOrders->openCalls);
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 7109, 'state' => 'processed']);
    }

    public function test_customer_card_to_card_payment_reserves_once_and_keeps_pan_out_of_durable_navigation_state(): void
    {
        [$processor, $accountId, $sessionId, $cardToCardPayments] = $this->prepareSelectedCardToCardJourney(
            9740,
            7260,
            'navigation_purchase_c2c_reserve',
        );
        $operationCountBefore = DB::table('telegram_delivery_operations')->where('recipient_chat_id', 9740)->count();

        $reserveToken = $this->callbackToken('navigation.purchase.card_to_card.reserve', $accountId);
        $this->accept($this->callbackPayload(7266, 9740, 'navigation_purchase_c2c_reserve', 'fa', $reserveToken));
        $processor->process('123456789', 7266);

        self::assertCount(1, $cardToCardPayments->reserveCalls);
        $instructions = DB::table('telegram_interaction_sessions')->where('id', $sessionId)->first(['state', 'version', 'payload', 'expires_at']);
        self::assertNotNull($instructions);
        self::assertSame('purchase_card_to_card_instructions', (string) $instructions->state);
        self::assertSame(11, (int) $instructions->version);
        $instructionExpiry = new DateTimeImmutable((string) $instructions->expires_at, new \DateTimeZone('UTC'));
        $remainingInstructionLifetime = $instructionExpiry->getTimestamp() - now('UTC')->getTimestamp();
        self::assertGreaterThan(23 * 3600, $remainingInstructionLifetime, 'C2C receipt input must not inherit the default 30-minute interaction TTL.');
        self::assertLessThanOrEqual(24 * 3600 + 5, $remainingInstructionLifetime, 'C2C receipt input must stay bounded to the payment late-review deadline.');
        /** @var array<string,mixed> $payload */
        $payload = json_decode((string) $instructions->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('card_to_card', $payload['payment_method_code'] ?? null);
        self::assertSame(str_pad('01C', 26, '0'), $payload['payment_intent_public_id'] ?? null);
        self::assertSame(str_pad('01R', 26, '0'), $payload['c2c_reservation_public_id'] ?? null);
        self::assertArrayNotHasKey('card_number', $payload);
        self::assertArrayNotHasKey('payable_amount_irr', $payload);
        self::assertArrayNotHasKey('destination_id', $payload);

        $durable = $this->navigationCommonDurableEvidence($sessionId, 9740);
        self::assertStringNotContainsString('4242424242424242', $durable);
        self::assertStringContainsString(str_pad('01R', 26, '0'), $durable);
        self::assertStringContainsString('911,000', $this->latestConfidentialPresentation());
        self::assertStringContainsString('424242******4242', $this->latestConfidentialPresentation());
        self::assertStringContainsString('تسویه فقط با شواهد معتبر بانکی', $this->latestConfidentialPresentation());

        self::assertSame(
            $operationCountBefore + 2,
            DB::table('telegram_delivery_operations')->where('recipient_chat_id', 9740)->count(),
        );
        $protectedOperation = DB::table('telegram_delivery_operations')
            ->where('recipient_chat_id', 9740)
            ->where('presentation_text', 'like', '[PROTECTED_TELEGRAM_REFERENCE:v1:%')
            ->latest('id')
            ->first(['presentation_text', 'outbox_event_id']);
        self::assertNotNull($protectedOperation);
        self::assertStringContainsString(str_pad('01R', 26, '0'), (string) $protectedOperation->presentation_text);
        self::assertStringNotContainsString('4242424242424242', (string) $protectedOperation->presentation_text);
        self::assertSame(
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_PROTECTED_REFERENCE,
            (int) DB::table('outbox_messages')->where('id', $protectedOperation->outbox_event_id)->value('contract_version'),
        );

        $operationsAfterReserve = DB::table('telegram_delivery_operations')->where('recipient_chat_id', 9740)->count();
        $processor->process('123456789', 7266);
        self::assertCount(1, $cardToCardPayments->reserveCalls);
        self::assertSame($operationsAfterReserve, DB::table('telegram_delivery_operations')->where('recipient_chat_id', 9740)->count());

        $backToken = $this->callbackToken('navigation.back', $accountId);
        $this->accept($this->callbackPayload(7267, 9740, 'navigation_purchase_c2c_reserve', 'fa', $backToken));
        $processor->process('123456789', 7267);
        $methods = DB::table('telegram_interaction_sessions')->where('id', $sessionId)->first(['state', 'version', 'payload']);
        self::assertNotNull($methods);
        self::assertSame('purchase_payment_methods', (string) $methods->state);
        self::assertSame(12, (int) $methods->version);
        /** @var array<string,mixed> $methodsPayload */
        $methodsPayload = json_decode((string) $methods->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('payment_method_code', $methodsPayload);
        self::assertArrayNotHasKey('payment_intent_public_id', $methodsPayload);
        self::assertArrayNotHasKey('c2c_reservation_public_id', $methodsPayload);
        self::assertCount(1, $cardToCardPayments->reserveCalls);
    }

    public function test_customer_wallet_payment_requires_confirm_and_exact_replay_does_not_duplicate_capture(): void
    {
        [$processor, $accountId, $sessionId, $walletPayments] = $this->prepareSelectedWalletJourney(
            9730,
            7200,
            'navigation_purchase_wallet_confirm',
        );

        $reserveToken = $this->callbackToken('navigation.purchase.wallet.reserve', $accountId);
        $this->accept($this->callbackPayload(7206, 9730, 'navigation_purchase_wallet_confirm', 'fa', $reserveToken));
        $processor->process('123456789', 7206);

        self::assertCount(1, $walletPayments->reserveCalls);
        self::assertSame([], $walletPayments->captureCalls);
        self::assertSame([], $walletPayments->cancelCalls);
        $confirm = DB::table('telegram_interaction_sessions')->where('id', $sessionId)->first(['state', 'version', 'payload']);
        self::assertNotNull($confirm);
        self::assertSame('purchase_wallet_confirm', (string) $confirm->state);
        self::assertSame(11, (int) $confirm->version);
        /** @var array<string,mixed> $confirmPayload */
        $confirmPayload = json_decode((string) $confirm->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(str_pad('01W', 26, '0'), $confirmPayload['payment_intent_public_id'] ?? null);
        self::assertSame('wallet', $confirmPayload['payment_method_code'] ?? null);
        self::assertArrayNotHasKey('wallet_account_id', $confirmPayload);
        self::assertArrayNotHasKey('ledger_account_id', $confirmPayload);
        self::assertArrayNotHasKey('available_balance_irr', $confirmPayload);
        self::assertStringContainsString('تأیید پرداخت با کیف پول', $this->latestConfidentialPresentation());
        self::assertStringContainsString('910,000', $this->latestConfidentialPresentation());
        self::assertStringNotContainsString('ledger_account_id', $this->navigationCommonDurableEvidence($sessionId, 9730));
        self::assertStringNotContainsString('wallet_account_id', $this->navigationCommonDurableEvidence($sessionId, 9730));

        $operationCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', 9730)->count();
        $processor->process('123456789', 7206);
        self::assertCount(1, $walletPayments->reserveCalls);
        self::assertSame($operationCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', 9730)->count());

        $confirmToken = $this->callbackToken('navigation.purchase.wallet.confirm', $accountId);
        $this->accept($this->callbackPayload(7207, 9730, 'navigation_purchase_wallet_confirm', 'fa', $confirmToken));
        $processor->process('123456789', 7207);

        self::assertCount(1, $walletPayments->captureCalls);
        $paid = DB::table('telegram_interaction_sessions')->where('id', $sessionId)->first(['state', 'version', 'payload']);
        self::assertNotNull($paid);
        self::assertSame('purchase_wallet_paid', (string) $paid->state);
        self::assertSame(13, (int) $paid->version);
        /** @var array<string,mixed> $paidPayload */
        $paidPayload = json_decode((string) $paid->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(str_pad('01S', 26, '0'), $paidPayload['purchase_settlement_public_id'] ?? null);
        self::assertSame(str_pad('01W', 26, '0'), $paidPayload['payment_intent_public_id'] ?? null);
        self::assertArrayNotHasKey('wallet_account_id', $paidPayload);
        self::assertStringContainsString('پرداخت کیف پول انجام شد', $this->latestConfidentialPresentation());
        self::assertStringContainsString(str_pad('01N', 26, '0'), $this->latestConfidentialPresentation());
        self::assertStringContainsString('هنوز پروویژنینگ', $this->latestConfidentialPresentation());

        $paidOperationCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', 9730)->count();
        $processor->process('123456789', 7207);
        self::assertCount(1, $walletPayments->captureCalls);
        self::assertSame($paidOperationCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', 9730)->count());
    }

    public function test_terminal_wallet_confirmation_returns_home_without_retry_loop_or_second_cancel(): void
    {
        [$processor, $accountId, $sessionId, $walletPayments] = $this->prepareSelectedWalletJourney(
            9732,
            7240,
            'navigation_purchase_wallet_terminal',
        );
        $reserveToken = $this->callbackToken('navigation.purchase.wallet.reserve', $accountId);
        $this->accept($this->callbackPayload(7246, 9732, 'navigation_purchase_wallet_terminal', 'fa', $reserveToken));
        $processor->process('123456789', 7246);
        $walletPayments->captureUnavailable = true;

        $confirmToken = $this->callbackToken('navigation.purchase.wallet.confirm', $accountId);
        $this->accept($this->callbackPayload(7247, 9732, 'navigation_purchase_wallet_terminal', 'fa', $confirmToken));
        $processor->process('123456789', 7247);

        self::assertCount(1, $walletPayments->captureCalls);
        self::assertSame([], $walletPayments->cancelCalls);
        $after = DB::table('telegram_interaction_sessions')->where('id', $sessionId)->first(['state', 'version', 'payload']);
        self::assertNotNull($after);
        self::assertSame(TelegramNavigationEntryGateway::STATE, (string) $after->state);
        self::assertSame(12, (int) $after->version);
        self::assertSame('{}', (string) $after->payload);
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 7247, 'state' => 'processed']);

        $operations = DB::table('telegram_delivery_operations')->where('recipient_chat_id', 9732)->count();
        $processor->process('123456789', 7247);
        self::assertCount(1, $walletPayments->captureCalls);
        self::assertSame($operations, DB::table('telegram_delivery_operations')->where('recipient_chat_id', 9732)->count());
    }

    public function test_prebound_wallet_confirm_and_back_race_cancels_hold_path_before_capture(): void
    {
        [$processor, $accountId, $sessionId, $walletPayments] = $this->prepareSelectedWalletJourney(
            9731,
            7220,
            'navigation_purchase_wallet_race',
        );
        $reserveToken = $this->callbackToken('navigation.purchase.wallet.reserve', $accountId);
        $this->accept($this->callbackPayload(7226, 9731, 'navigation_purchase_wallet_race', 'fa', $reserveToken));
        $processor->process('123456789', 7226);
        self::assertCount(1, $walletPayments->reserveCalls);

        $confirmToken = $this->callbackToken('navigation.purchase.wallet.confirm', $accountId);
        $backToken = $this->callbackToken('navigation.back', $accountId);
        $this->accept($this->callbackPayload(7227, 9731, 'navigation_purchase_wallet_race', 'fa', $confirmToken));
        $this->accept($this->callbackPayload(7228, 9731, 'navigation_purchase_wallet_race', 'fa', $backToken));
        $callbacks = $this->app->make(TelegramInteractionCallbackService::class);
        $confirmAcceptance = $callbacks->accept('123456789', 9731, $confirmToken, 7227);
        $backAcceptance = $callbacks->accept('123456789', 9731, $backToken, 7228);
        self::assertSame($confirmAcceptance->sessionVersion, $backAcceptance->sessionVersion);
        self::assertSame(11, $backAcceptance->sessionVersion);

        $handler = $this->app->make(TelegramNavigationHandler::class);
        $handler->handle($this->callbackActionFromAcceptance($backAcceptance, 7228, 9731));
        $handler->handle($this->callbackActionFromAcceptance($confirmAcceptance, 7227, 9731));

        self::assertCount(1, $walletPayments->cancelCalls);
        self::assertSame([], $walletPayments->captureCalls);
        $after = DB::table('telegram_interaction_sessions')->where('id', $sessionId)->first(['state', 'version', 'payload']);
        self::assertNotNull($after);
        self::assertSame('purchase_payment_methods', (string) $after->state);
        self::assertSame(13, (int) $after->version);
        /** @var array<string,mixed> $payload */
        $payload = json_decode((string) $after->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('payment_intent_public_id', $payload);
        self::assertArrayNotHasKey('payment_method_code', $payload);
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
    }

    public function test_customer_purchase_payment_method_empty_state_uses_english_fallback_without_financial_effect(): void
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog;
        $quotes = new TelegramNavigationCustomerPurchaseQuote($catalog);
        $paymentMethods = new TelegramNavigationCustomerPurchasePaymentMethods;
        $purchaseOrders = new TelegramNavigationCustomerPurchaseOrder;
        $paymentMethods->methodCodes = [];
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->app->instance(TelegramCustomerPurchaseQuote::class, $quotes);
        $this->app->instance(TelegramCustomerPurchasePaymentMethods::class, $paymentMethods);
        $this->app->instance(TelegramCustomerPurchaseOrder::class, $purchaseOrders);
        $telegramUserId = 9713;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7120, $telegramUserId, 'navigation_purchase_payment_methods_empty', 'en', '/start'));
        $processor->process('123456789', 7120);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        foreach ([
            [7121, 'navigation.purchase'],
            [7122, 'navigation.purchase.'.str_repeat('c', 40)],
            [7123, 'navigation.purchase.quote'],
            [7124, 'navigation.purchase.payment_methods'],
        ] as [$updateId, $action]) {
            $token = $this->callbackToken($action, (int) $account->id);
            $this->accept($this->callbackPayload($updateId, $telegramUserId, 'navigation_purchase_payment_methods_empty', 'en', $token));
            $processor->process('123456789', $updateId);
        }

        self::assertCount(1, $paymentMethods->calls);
        $presentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('Payment Methods', $presentation);
        self::assertStringContainsString('No payment method is currently eligible for this Quote.', $presentation);
        self::assertStringContainsString('No Order, Payment Intent, or financial effect has been created.', $presentation);
        self::assertStringNotContainsString('روش‌های پرداخت', $presentation);
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame([], $purchaseOrders->openCalls);
    }

    public function test_prebound_purchase_payment_methods_and_back_race_fails_closed_before_pay_001_decision(): void
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog;
        $quotes = new TelegramNavigationCustomerPurchaseQuote($catalog);
        $paymentMethods = new TelegramNavigationCustomerPurchasePaymentMethods;
        $purchaseOrders = new TelegramNavigationCustomerPurchaseOrder;
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->app->instance(TelegramCustomerPurchaseQuote::class, $quotes);
        $this->app->instance(TelegramCustomerPurchasePaymentMethods::class, $paymentMethods);
        $this->app->instance(TelegramCustomerPurchaseOrder::class, $purchaseOrders);
        $telegramUserId = 9712;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7110, $telegramUserId, 'navigation_purchase_payment_methods_race', 'en', '/start'));
        $processor->process('123456789', 7110);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        foreach ([
            [7111, 'navigation.purchase'],
            [7112, 'navigation.purchase.'.str_repeat('c', 40)],
            [7113, 'navigation.purchase.quote'],
        ] as [$updateId, $action]) {
            $token = $this->callbackToken($action, (int) $account->id);
            $this->accept($this->callbackPayload($updateId, $telegramUserId, 'navigation_purchase_payment_methods_race', 'en', $token));
            $processor->process('123456789', $updateId);
        }

        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->first(['id', 'state', 'version']);
        self::assertNotNull($session);
        self::assertSame('purchase_quote', (string) $session->state);
        self::assertSame(5, (int) $session->version);

        $paymentToken = $this->callbackToken('navigation.purchase.payment_methods', (int) $account->id);
        $backToken = $this->callbackToken('navigation.back', (int) $account->id);
        $this->accept($this->callbackPayload(7114, $telegramUserId, 'navigation_purchase_payment_methods_race', 'en', $paymentToken));
        $this->accept($this->callbackPayload(7115, $telegramUserId, 'navigation_purchase_payment_methods_race', 'en', $backToken));
        $callbacks = $this->app->make(TelegramInteractionCallbackService::class);
        $paymentAcceptance = $callbacks->accept('123456789', $telegramUserId, $paymentToken, 7114);
        $backAcceptance = $callbacks->accept('123456789', $telegramUserId, $backToken, 7115);
        self::assertSame($paymentAcceptance->sessionVersion, $backAcceptance->sessionVersion);

        $handler = $this->app->make(TelegramNavigationHandler::class);
        $handler->handle($this->callbackActionFromAcceptance($backAcceptance, 7115, $telegramUserId));
        $handler->handle($this->callbackActionFromAcceptance($paymentAcceptance, 7114, $telegramUserId));

        self::assertSame([], $paymentMethods->calls);
        self::assertSame([], $purchaseOrders->openCalls);
        $after = DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->first(['state', 'version']);
        self::assertNotNull($after);
        self::assertSame('purchase_offering', (string) $after->state);
        self::assertSame(6, (int) $after->version);
        self::assertSame(0, DB::table('telegram_interaction_transitions')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('to_state', 'purchase_payment_methods_submitting')
            ->count());
    }

    public function test_prebound_purchase_quote_and_back_race_fails_closed_before_quote_creation(): void
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog;
        $quotes = new TelegramNavigationCustomerPurchaseQuote($catalog);
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->app->instance(TelegramCustomerPurchaseQuote::class, $quotes);
        $telegramUserId = 9701;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(6990, $telegramUserId, 'navigation_purchase_quote_race', 'en', '/start'));
        $processor->process('123456789', 6990);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);

        $purchaseToken = $this->callbackToken('navigation.purchase', (int) $account->id);
        $this->accept($this->callbackPayload(6991, $telegramUserId, 'navigation_purchase_quote_race', 'en', $purchaseToken));
        $processor->process('123456789', 6991);
        $offeringToken = $this->callbackToken('navigation.purchase.'.str_repeat('c', 40), (int) $account->id);
        $this->accept($this->callbackPayload(6992, $telegramUserId, 'navigation_purchase_quote_race', 'en', $offeringToken));
        $processor->process('123456789', 6992);

        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id', 'state', 'version']);
        self::assertNotNull($session);
        self::assertSame('purchase_offering', (string) $session->state);
        self::assertSame(3, (int) $session->version);

        $quoteToken = $this->callbackToken('navigation.purchase.quote', (int) $account->id);
        $backToken = $this->callbackToken('navigation.back', (int) $account->id);
        $this->accept($this->callbackPayload(6993, $telegramUserId, 'navigation_purchase_quote_race', 'en', $quoteToken));
        $this->accept($this->callbackPayload(6994, $telegramUserId, 'navigation_purchase_quote_race', 'en', $backToken));

        $callbacks = $this->app->make(TelegramInteractionCallbackService::class);
        $quoteAcceptance = $callbacks->accept('123456789', $telegramUserId, $quoteToken, 6993);
        $backAcceptance = $callbacks->accept('123456789', $telegramUserId, $backToken, 6994);
        $quoteReplayAcceptance = $callbacks->accept('123456789', $telegramUserId, $quoteToken, 6993);
        self::assertTrue($quoteReplayAcceptance->replayed);
        self::assertEquals($quoteAcceptance->acceptedAt, $quoteReplayAcceptance->acceptedAt);
        self::assertSame($quoteAcceptance->sessionVersion, $backAcceptance->sessionVersion);
        self::assertNotNull($quoteAcceptance->acceptedAt);
        self::assertNotNull($backAcceptance->acceptedAt);
        $before = $this->purchaseMutationCounts();

        $handler = $this->app->make(TelegramNavigationHandler::class);
        $handler->handle($this->callbackActionFromAcceptance($backAcceptance, 6994, $telegramUserId));
        $handler->handle($this->callbackActionFromAcceptance($quoteAcceptance, 6993, $telegramUserId));

        self::assertSame([], $quotes->calls);
        self::assertSame($before, $this->purchaseMutationCounts());
        $after = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->first(['state', 'version']);
        self::assertNotNull($after);
        self::assertSame('purchase_catalog', (string) $after->state);
        self::assertSame(4, (int) $after->version);
        self::assertSame(0, DB::table('telegram_interaction_transitions')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('to_state', 'purchase_quote_submitting')
            ->count());
    }

    public function test_back_from_quote_falls_back_to_current_catalog_when_offering_becomes_ineligible(): void
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog;
        $quotes = new TelegramNavigationCustomerPurchaseQuote($catalog);
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->app->instance(TelegramCustomerPurchaseQuote::class, $quotes);
        $telegramUserId = 9702;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(6995, $telegramUserId, 'navigation_purchase_quote_stale_back', 'en', '/start'));
        $processor->process('123456789', 6995);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);

        $purchaseToken = $this->callbackToken('navigation.purchase', (int) $account->id);
        $this->accept($this->callbackPayload(6996, $telegramUserId, 'navigation_purchase_quote_stale_back', 'en', $purchaseToken));
        $processor->process('123456789', 6996);
        $offeringToken = $this->callbackToken('navigation.purchase.'.str_repeat('c', 40), (int) $account->id);
        $this->accept($this->callbackPayload(6997, $telegramUserId, 'navigation_purchase_quote_stale_back', 'en', $offeringToken));
        $processor->process('123456789', 6997);
        $quoteToken = $this->callbackToken('navigation.purchase.quote', (int) $account->id);
        $this->accept($this->callbackPayload(6998, $telegramUserId, 'navigation_purchase_quote_stale_back', 'en', $quoteToken));
        $processor->process('123456789', 6998);

        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id', 'state', 'version']);
        self::assertNotNull($session);
        self::assertSame('purchase_quote', (string) $session->state);
        self::assertSame(5, (int) $session->version);
        self::assertCount(1, $quotes->calls);

        $catalog->offeringAvailable = false;
        $backToken = $this->callbackToken('navigation.back', (int) $account->id);
        $this->accept($this->callbackPayload(6999, $telegramUserId, 'navigation_purchase_quote_stale_back', 'en', $backToken));
        $processor->process('123456789', 6999);

        $after = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->first(['state', 'version', 'payload']);
        self::assertNotNull($after);
        self::assertSame('purchase_catalog', (string) $after->state);
        self::assertSame(6, (int) $after->version);
        self::assertSame('{"page":1}', (string) $after->payload);
        self::assertCount(1, $quotes->calls);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 6999,
            'state' => 'processed',
        ]);
        self::assertStringContainsString('Buy Service', $this->latestConfidentialPresentation());
    }

    public function test_customer_purchase_catalog_empty_english_pagination_and_home_filtering_are_deterministic(): void
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog;
        $catalog->mode = 'paginated';
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $telegramUserId = 9692;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(6960, $telegramUserId, 'navigation_purchase_en', 'en', '/start'));
        $processor->process('123456789', 6960);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id']);
        self::assertNotNull($session);
        $purchase = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 1)
            ->where('action', 'navigation.purchase')
            ->first(['token_ciphertext']);
        self::assertNotNull($purchase);
        $purchaseToken = $this->app->make(StringEncrypter::class)->decryptString((string) $purchase->token_ciphertext);
        $before = $this->purchaseMutationCounts();

        $this->accept($this->callbackPayload(6961, $telegramUserId, 'navigation_purchase_en', 'en', $purchaseToken));
        $processor->process('123456789', 6961);
        $pageOne = $this->latestConfidentialPresentation();
        self::assertStringContainsString('Buy Service', $pageOne);
        self::assertStringContainsString('Purchase plan — Base variant', $pageOne);
        self::assertStringContainsString('Page 1 of 2 — 2 available option(s)', $pageOne);
        self::assertStringNotContainsString('پلن خرید', $pageOne);
        $next = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 2)
            ->where('action', 'navigation.purchase.page')
            ->where('action_payload', '{"page":2}')
            ->first(['token_ciphertext']);
        self::assertNotNull($next);
        $nextToken = $this->app->make(StringEncrypter::class)->decryptString((string) $next->token_ciphertext);

        $this->accept($this->callbackPayload(6962, $telegramUserId, 'navigation_purchase_en', 'en', $nextToken));
        $processor->process('123456789', 6962);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'purchase_catalog',
            'version' => 3,
            'payload' => '{"page":2}',
        ]);
        $pageTwo = $this->latestConfidentialPresentation();
        self::assertStringContainsString('Second plan', $pageTwo);
        self::assertStringContainsString('Page 2 of 2 — 2 available option(s)', $pageTwo);
        self::assertSame($before, $this->purchaseMutationCounts());

        DB::table('users')->where('id', (int) $account->user_id)->update([
            'account_type' => 'agent',
            'updated_at' => now('UTC'),
        ]);
        $this->accept($this->payload(6963, $telegramUserId, 'navigation_purchase_en', 'en', '/menu'));
        $processor->process('123456789', 6963);
        $currentVersion = (int) DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->value('version');
        self::assertSame(4, $currentVersion);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', $currentVersion)
            ->where('action', 'navigation.purchase')
            ->count());
        self::assertSame($before, $this->purchaseMutationCounts());

        $catalog->mode = 'empty';
        $emptyTelegramUserId = 9693;
        $this->accept($this->payload(6970, $emptyTelegramUserId, 'navigation_purchase_empty', 'en', '/start'));
        $processor->process('123456789', 6970);
        $emptyAccount = DB::table('telegram_accounts')->where('telegram_user_id', $emptyTelegramUserId)->first(['id']);
        self::assertNotNull($emptyAccount);
        $emptySession = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $emptyAccount->id)
            ->first(['id']);
        self::assertNotNull($emptySession);
        $emptyPurchase = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $emptySession->id)
            ->where('session_version', 1)
            ->where('action', 'navigation.purchase')
            ->first(['token_ciphertext']);
        self::assertNotNull($emptyPurchase);
        $emptyPurchaseToken = $this->app->make(StringEncrypter::class)->decryptString((string) $emptyPurchase->token_ciphertext);
        $this->accept($this->callbackPayload(6971, $emptyTelegramUserId, 'navigation_purchase_empty', 'en', $emptyPurchaseToken));
        $processor->process('123456789', 6971);
        self::assertStringContainsString(
            'No currently eligible service offering is available for your account.',
            $this->latestConfidentialPresentation(),
        );
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $emptySession->id)
            ->where('session_version', 2)
            ->where('action', 'like', 'navigation.purchase.%')
            ->count());
        self::assertSame($before, $this->purchaseMutationCounts());
    }

    public function test_admin_usdt_rate_journey_is_permission_filtered_confidential_and_crash_replay_idempotent(): void
    {
        Config::set('usdt.rate.min_irr', '100000');
        Config::set('usdt.rate.max_irr', '10000000');
        Config::set('usdt.rate.manual_irr', '900000');
        $telegramUserId = 9700;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7000, $telegramUserId, 'navigation_admin_rate', 'fa', '/start'));
        $processor->process('123456789', 7000);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')->where('action', 'navigation.admin')->count());

        $administratorId = $this->financeAdministratorForUser((int) $account->user_id);
        self::assertGreaterThan(0, $administratorId);
        self::assertFalse((bool) DB::table('permissions')->where('code', 'payments.usdt.manage')->value('requires_approval'));
        $this->accept($this->payload(7001, $telegramUserId, 'navigation_admin_rate', 'fa', '/menu'));
        $processor->process('123456789', 7001);

        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id', 'state', 'version', 'payload']);
        self::assertNotNull($session);
        self::assertSame('home', (string) $session->state);
        $adminCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', (int) $session->version)
            ->where('action', 'navigation.admin')
            ->orderByDesc('id')
            ->first(['action_payload', 'token_ciphertext']);
        self::assertNotNull($adminCallback);
        self::assertSame('{}', (string) $adminCallback->action_payload);
        $adminToken = $this->app->make(StringEncrypter::class)->decryptString((string) $adminCallback->token_ciphertext);

        $otherTelegramUserId = 9701;
        $this->accept($this->payload(7010, $otherTelegramUserId, 'navigation_admin_other', 'fa', '/start'));
        $processor->process('123456789', 7010);
        $operationCountBeforeCrossActor = DB::table('telegram_delivery_operations')->count();
        $this->accept($this->callbackPayload(7011, $otherTelegramUserId, 'navigation_admin_other', 'fa', $adminToken));
        $processor->process('123456789', 7011);
        self::assertSame($operationCountBeforeCrossActor, DB::table('telegram_delivery_operations')->count());

        $this->accept($this->callbackPayload(7002, $telegramUserId, 'navigation_admin_rate', 'fa', $adminToken));
        $processor->process('123456789', 7002);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'admin_control',
            'payload' => '{}',
        ]);
        self::assertStringContainsString('مرکز مدیریت', $this->latestConfidentialPresentation());

        $rateToken = $this->callbackToken('navigation.admin.usdt_rate', (int) $account->id);
        $this->accept($this->callbackPayload(7003, $telegramUserId, 'navigation_admin_rate', 'fa', $rateToken));
        $processor->process('123456789', 7003);
        $ratePresentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('900,000', $ratePresentation);
        self::assertStringContainsString('USDT', $ratePresentation);
        self::assertStringContainsString('NOWPayments', $ratePresentation);
        self::assertStringNotContainsString('payments.usdt.manage', $ratePresentation);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'admin_usdt_rate',
            'payload' => '{}',
        ]);
        $commonEvidence = $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId);
        self::assertStringNotContainsString('payments.usdt.manage', $commonEvidence);
        self::assertStringNotContainsString('created_by_administrator_id', $commonEvidence);

        $editToken = $this->callbackToken('navigation.admin.usdt_rate.edit', (int) $account->id);
        $this->accept($this->callbackPayload(7004, $telegramUserId, 'navigation_admin_rate', 'fa', $editToken));
        $processor->process('123456789', 7004);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'admin_usdt_rate_edit',
            'payload' => '{}',
        ]);

        foreach ([[7005, '۹۰,۰۰۰'], [7006, '۰'], [7007, '۹۹۹۹۹']] as [$updateId, $invalidRate]) {
            $this->accept($this->payload($updateId, $telegramUserId, 'navigation_admin_rate', 'fa', $invalidRate));
            $processor->process('123456789', $updateId);
            self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());
            self::assertSame(0, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
            self::assertStringContainsString('معتبر نیست', $this->latestConfidentialPresentation());
        }

        $rawRateInput = '۹۱۰۰۰۰';
        $this->accept($this->payload(7009, $telegramUserId, 'navigation_admin_rate', 'fa', $rawRateInput));
        $processor->process('123456789', 7009);
        self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
        $confirmSession = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->first(['state', 'version', 'payload']);
        self::assertNotNull($confirmSession);
        self::assertSame('admin_usdt_rate_confirm', (string) $confirmSession->state);
        self::assertSame(
            ['rate_irr' => '910000.00000000'],
            json_decode((string) $confirmSession->payload, true, 512, JSON_THROW_ON_ERROR),
        );
        $confirmPresentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('تأیید تغییر نرخ', $confirmPresentation);
        self::assertStringContainsString('910,000', $confirmPresentation);
        self::assertStringContainsString('NOWPayments', $confirmPresentation);
        self::assertStringContainsString('هیچ تغییری ثبت نمی‌شود', $confirmPresentation);

        $confirmCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', (int) $confirmSession->version)
            ->where('action', 'navigation.admin.usdt_rate.confirm')
            ->orderByDesc('id')
            ->first(['public_id', 'action_payload', 'token_ciphertext']);
        self::assertNotNull($confirmCallback);
        self::assertSame('{}', (string) $confirmCallback->action_payload);
        $confirmToken = $this->app->make(StringEncrypter::class)->decryptString((string) $confirmCallback->token_ciphertext);

        $this->accept($this->callbackPayload(7012, $otherTelegramUserId, 'navigation_admin_other', 'fa', $confirmToken));
        $processor->process('123456789', 7012);
        self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame('admin_usdt_rate_confirm', (string) DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->value('state'));

        $this->accept($this->callbackPayload(7013, $telegramUserId, 'navigation_admin_rate', 'fa', $confirmToken));
        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_navigation_test_fail_processed_7013
BEFORE UPDATE ON processed_telegram_updates
FOR EACH ROW
BEGIN
    IF OLD.bot_id = '123456789' AND OLD.update_id = 7013 AND NEW.state = 'processed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-post-admin-rate-confirmation-failure';
    END IF;
END
SQL);
        try {
            try {
                $processor->process('123456789', 7013);
                self::fail('The simulated post-confirmation failure must keep the accepted confirmation retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
                self::assertStringNotContainsString($rawRateInput, $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_7013');
        }

        self::assertSame(1, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame('910000.00000000', (string) DB::table('usdt_manual_rate_versions')->value('rate_irr'));
        self::assertSame(
            'telegram-usdt-rate:'.(string) $confirmCallback->public_id,
            (string) DB::table('usdt_manual_rate_versions')->value('request_key'),
        );
        self::assertSame(
            'tg-usdt-rate:'.(string) $confirmCallback->public_id,
            (string) DB::table('usdt_manual_rate_versions')->value('correlation_id'),
        );
        self::assertSame(1, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 7013,
            'state' => 'failed',
            'attempt_count' => 1,
        ]);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'admin_usdt_rate',
            'payload' => '{}',
        ]);

        $processor->process('123456789', 7013);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 7013,
            'state' => 'processed',
            'attempt_count' => 2,
        ]);
        self::assertSame(1, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
        $updatedPresentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('با موفقیت ثبت شد', $updatedPresentation);
        self::assertStringContainsString('910,000', $updatedPresentation);
    }

    public function test_admin_usdt_rate_confirmation_reauthorizes_after_permission_revocation(): void
    {
        Config::set('usdt.rate.min_irr', '100000');
        Config::set('usdt.rate.max_irr', '10000000');
        Config::set('usdt.rate.manual_irr', '900000');
        $telegramUserId = 9710;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7020, $telegramUserId, 'navigation_admin_revoke', 'en', '/start'));
        $processor->process('123456789', 7020);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $administratorId = $this->financeAdministratorForUser((int) $account->user_id);

        $this->accept($this->payload(7021, $telegramUserId, 'navigation_admin_revoke', 'en', '/menu'));
        $processor->process('123456789', 7021);
        $adminToken = $this->callbackToken('navigation.admin', (int) $account->id);
        $this->accept($this->callbackPayload(7022, $telegramUserId, 'navigation_admin_revoke', 'en', $adminToken));
        $processor->process('123456789', 7022);
        self::assertStringContainsString('Administrator Control Center', $this->latestConfidentialPresentation());
        $rateToken = $this->callbackToken('navigation.admin.usdt_rate', (int) $account->id);
        $this->accept($this->callbackPayload(7023, $telegramUserId, 'navigation_admin_revoke', 'en', $rateToken));
        $processor->process('123456789', 7023);
        self::assertStringContainsString('Manual USDT Rate', $this->latestConfidentialPresentation());
        self::assertStringContainsString('NOWPayments', $this->latestConfidentialPresentation());
        $editToken = $this->callbackToken('navigation.admin.usdt_rate.edit', (int) $account->id);
        $this->accept($this->callbackPayload(7024, $telegramUserId, 'navigation_admin_revoke', 'en', $editToken));
        $processor->process('123456789', 7024);
        self::assertStringContainsString('Change Manual USDT Rate', $this->latestConfidentialPresentation());

        $this->accept($this->payload(7025, $telegramUserId, 'navigation_admin_revoke', 'en', '920000'));
        $processor->process('123456789', 7025);
        self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());
        self::assertStringContainsString('Confirm Manual USDT Rate Change', $this->latestConfidentialPresentation());
        $confirmToken = $this->callbackToken('navigation.admin.usdt_rate.confirm', (int) $account->id);

        DB::table('administrator_role_assignments')->where('administrator_id', $administratorId)->update([
            'revoked_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('administrators')->where('id', $administratorId)->update([
            'permission_version' => DB::raw('permission_version + 1'),
            'updated_at' => now('UTC'),
        ]);

        $this->accept($this->callbackPayload(7026, $telegramUserId, 'navigation_admin_revoke', 'en', $confirmToken));
        $processor->process('123456789', 7026);

        self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->first(['id', 'state', 'version', 'payload']);
        self::assertNotNull($session);
        self::assertSame('home', (string) $session->state);
        self::assertSame('{}', (string) $session->payload);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', (int) $session->version)
            ->where('action', 'navigation.admin')
            ->count());
    }

    public function test_rate_mutation_rolls_back_if_the_claimed_confirmation_cannot_finalize(): void
    {
        Config::set('usdt.rate.min_irr', '100000');
        Config::set('usdt.rate.max_irr', '10000000');
        Config::set('usdt.rate.manual_irr', '900000');
        $telegramUserId = 9730;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7040, $telegramUserId, 'navigation_admin_atomic', 'en', '/start'));
        $processor->process('123456789', 7040);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $this->financeAdministratorForUser((int) $account->user_id);

        $this->accept($this->payload(7041, $telegramUserId, 'navigation_admin_atomic', 'en', '/menu'));
        $processor->process('123456789', 7041);
        $adminToken = $this->callbackToken('navigation.admin', (int) $account->id);
        $this->accept($this->callbackPayload(7042, $telegramUserId, 'navigation_admin_atomic', 'en', $adminToken));
        $processor->process('123456789', 7042);
        $rateToken = $this->callbackToken('navigation.admin.usdt_rate', (int) $account->id);
        $this->accept($this->callbackPayload(7043, $telegramUserId, 'navigation_admin_atomic', 'en', $rateToken));
        $processor->process('123456789', 7043);
        $editToken = $this->callbackToken('navigation.admin.usdt_rate.edit', (int) $account->id);
        $this->accept($this->callbackPayload(7044, $telegramUserId, 'navigation_admin_atomic', 'en', $editToken));
        $processor->process('123456789', 7044);
        $this->accept($this->payload(7045, $telegramUserId, 'navigation_admin_atomic', 'en', '910000'));
        $processor->process('123456789', 7045);

        $before = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id', 'state', 'version', 'payload']);
        self::assertNotNull($before);
        self::assertSame('admin_usdt_rate_confirm', (string) $before->state);
        self::assertSame(
            ['rate_irr' => '910000.00000000'],
            json_decode((string) $before->payload, true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());
        $confirmToken = $this->callbackToken('navigation.admin.usdt_rate.confirm', (int) $account->id);

        $this->accept($this->callbackPayload(7046, $telegramUserId, 'navigation_admin_atomic', 'en', $confirmToken));
        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_navigation_test_fail_admin_rate_finalize
BEFORE UPDATE ON telegram_interaction_sessions
FOR EACH ROW
BEGIN
    IF OLD.state = 'admin_usdt_rate_submitting' AND NEW.state = 'admin_usdt_rate' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-admin-rate-finalize-failure';
    END IF;
END
SQL);
        try {
            try {
                $processor->process('123456789', 7046);
                self::fail('The simulated final session transition failure must roll back the financial mutation.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_admin_rate_finalize');
        }

        self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
        $afterFailure = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $before->id)
            ->first(['state', 'version', 'payload']);
        self::assertNotNull($afterFailure);
        self::assertSame('admin_usdt_rate_confirm', (string) $afterFailure->state);
        self::assertSame((int) $before->version, (int) $afterFailure->version);
        self::assertSame((string) $before->payload, (string) $afterFailure->payload);
        self::assertSame(0, DB::table('telegram_interaction_transitions')
            ->where('telegram_interaction_session_id', (int) $before->id)
            ->where('to_state', 'admin_usdt_rate_submitting')
            ->count());

        $processor->process('123456789', 7046);
        self::assertSame(1, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame('910000.00000000', (string) DB::table('usdt_manual_rate_versions')->value('rate_irr'));
        self::assertSame(1, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
        $afterRetry = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $before->id)
            ->first(['state', 'version', 'payload']);
        self::assertNotNull($afterRetry);
        self::assertSame('admin_usdt_rate', (string) $afterRetry->state);
        self::assertSame('{}', (string) $afterRetry->payload);
        self::assertSame(1, DB::table('telegram_interaction_transitions')
            ->where('telegram_interaction_session_id', (int) $before->id)
            ->where('to_state', 'admin_usdt_rate_submitting')
            ->count());
    }

    public function test_prebound_confirmation_and_back_race_fails_closed_before_financial_mutation(): void
    {
        Config::set('usdt.rate.min_irr', '100000');
        Config::set('usdt.rate.max_irr', '10000000');
        Config::set('usdt.rate.manual_irr', '900000');
        $telegramUserId = 9720;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7030, $telegramUserId, 'navigation_admin_concurrent', 'en', '/start'));
        $processor->process('123456789', 7030);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $this->financeAdministratorForUser((int) $account->user_id);

        $this->accept($this->payload(7031, $telegramUserId, 'navigation_admin_concurrent', 'en', '/menu'));
        $processor->process('123456789', 7031);
        $adminToken = $this->callbackToken('navigation.admin', (int) $account->id);
        $this->accept($this->callbackPayload(7032, $telegramUserId, 'navigation_admin_concurrent', 'en', $adminToken));
        $processor->process('123456789', 7032);
        $rateToken = $this->callbackToken('navigation.admin.usdt_rate', (int) $account->id);
        $this->accept($this->callbackPayload(7033, $telegramUserId, 'navigation_admin_concurrent', 'en', $rateToken));
        $processor->process('123456789', 7033);
        $editToken = $this->callbackToken('navigation.admin.usdt_rate.edit', (int) $account->id);
        $this->accept($this->callbackPayload(7034, $telegramUserId, 'navigation_admin_concurrent', 'en', $editToken));
        $processor->process('123456789', 7034);
        $this->accept($this->payload(7035, $telegramUserId, 'navigation_admin_concurrent', 'en', '910000'));
        $processor->process('123456789', 7035);

        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id', 'public_id', 'state', 'version', 'payload']);
        self::assertNotNull($session);
        self::assertSame('admin_usdt_rate_confirm', (string) $session->state);
        self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());

        $confirmCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', (int) $session->version)
            ->where('action', 'navigation.admin.usdt_rate.confirm')
            ->first(['public_id', 'token_ciphertext']);
        $backCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', (int) $session->version)
            ->where('action', 'navigation.back')
            ->orderByDesc('id')
            ->first(['public_id', 'token_ciphertext']);
        self::assertNotNull($confirmCallback);
        self::assertNotNull($backCallback);
        $confirmToken = $this->app->make(StringEncrypter::class)->decryptString((string) $confirmCallback->token_ciphertext);
        $backToken = $this->app->make(StringEncrypter::class)->decryptString((string) $backCallback->token_ciphertext);

        $this->accept($this->callbackPayload(7036, $telegramUserId, 'navigation_admin_concurrent', 'en', $confirmToken));
        $this->accept($this->callbackPayload(7037, $telegramUserId, 'navigation_admin_concurrent', 'en', $backToken));
        $callbacks = $this->app->make(TelegramInteractionCallbackService::class);
        $confirmAcceptance = $callbacks->accept('123456789', $telegramUserId, $confirmToken, 7036);
        $backAcceptance = $callbacks->accept('123456789', $telegramUserId, $backToken, 7037);
        self::assertSame('admin_usdt_rate_confirm', $confirmAcceptance->sessionState);
        self::assertSame($confirmAcceptance->sessionVersion, $backAcceptance->sessionVersion);
        self::assertFalse($confirmAcceptance->replayed);
        self::assertFalse($backAcceptance->replayed);

        $handler = $this->app->make(TelegramNavigationHandler::class);
        $handler->handle($this->callbackActionFromAcceptance($backAcceptance, 7037, $telegramUserId));
        $handler->handle($this->callbackActionFromAcceptance($confirmAcceptance, 7036, $telegramUserId));

        self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
        $after = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->first(['state', 'payload']);
        self::assertNotNull($after);
        self::assertSame('admin_usdt_rate_edit', (string) $after->state);
        self::assertSame('{}', (string) $after->payload);
        self::assertSame(0, DB::table('telegram_interaction_transitions')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('to_state', 'admin_usdt_rate_submitting')
            ->count());
    }

    public function test_agent_cooperation_submission_is_explicit_confidential_and_replay_safe(): void
    {
        $telegramUserId = 9710;
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $this->accept($this->payload(7100, $telegramUserId, 'agent_cooperation', 'fa', '/start'));
        $processor->process('123456789', 7100);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $accountId = (int) $account->id;
        $userId = (int) $account->user_id;
        self::assertSame(0, DB::table('agent_applications')->where('customer_id', $userId)->count());
        $agentToken = $this->callbackToken('navigation.agent', $accountId);

        $otherTelegramUserId = 9711;
        $this->accept($this->payload(7110, $otherTelegramUserId, 'agent_other', 'fa', '/start'));
        $processor->process('123456789', 7110);
        $operationsBeforeCrossActor = DB::table('telegram_delivery_operations')->count();
        $this->accept($this->callbackPayload(7111, $otherTelegramUserId, 'agent_other', 'fa', $agentToken));
        $processor->process('123456789', 7111);
        self::assertSame($operationsBeforeCrossActor, DB::table('telegram_delivery_operations')->count());
        self::assertSame(0, DB::table('agent_applications')->where('customer_id', $userId)->count());

        $this->accept($this->callbackPayload(7101, $telegramUserId, 'agent_cooperation', 'fa', $agentToken));
        $processor->process('123456789', 7101);
        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', $accountId)->first(['id', 'state', 'version', 'payload']);
        self::assertNotNull($session);
        self::assertSame('agent_cooperation', (string) $session->state);
        self::assertSame(2, (int) $session->version);
        self::assertSame('{}', (string) $session->payload);
        $initialPresentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('درخواست همکاری', $initialPresentation);
        self::assertStringContainsString('درخواست به‌صورت دستی', $initialPresentation);
        self::assertStringContainsString('تضمینی برای تأیید، قیمت‌گذاری، تخفیف', $initialPresentation);
        self::assertStringContainsString('فقط یک درخواست فعال', $initialPresentation);
        self::assertStringContainsString('اطلاعات اختصاصی دیگری', $initialPresentation);
        self::assertSame(0, DB::table('agent_applications')->where('customer_id', $userId)->count());

        $submitToken = $this->callbackToken('navigation.agent.submit', $accountId);
        $this->accept($this->callbackPayload(7102, $telegramUserId, 'agent_cooperation', 'fa', $submitToken));
        $processor->process('123456789', 7102);

        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'agent_cooperation',
            'version' => 4,
            'payload' => '{}',
        ]);
        $this->assertDatabaseHas('agent_applications', [
            'customer_id' => $userId,
            'active_customer_id' => $userId,
            'state' => 'submitted',
            'application_version' => 1,
        ]);
        self::assertSame(1, DB::table('agent_applications')->where('customer_id', $userId)->count());
        self::assertSame(1, DB::table('agent_application_histories')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'agent.application.submit')->where('actor_type', 'user')->where('actor_id', (string) $userId)->count());
        $presentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('وضعیت فعلی: ثبت‌شده', $presentation);
        self::assertStringNotContainsString('application_id', $presentation);
        self::assertStringNotContainsString('decision_reason', $presentation);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 4)
            ->where('action', 'navigation.agent.submit')
            ->count());

        $commonEvidence = $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId);
        self::assertStringNotContainsString('application_id', $commonEvidence);
        self::assertStringNotContainsString('decision_reason', $commonEvidence);
        self::assertStringNotContainsString('claimed_by_administrator_id', $commonEvidence);

        $businessCounts = [
            DB::table('agent_applications')->where('customer_id', $userId)->count(),
            DB::table('agent_application_histories')->count(),
            DB::table('audit_logs')->where('action', 'agent.application.submit')->where('actor_type', 'user')->where('actor_id', (string) $userId)->count(),
        ];
        $deliveryCount = DB::table('telegram_delivery_operations')->count();
        $this->accept($this->callbackPayload(7103, $telegramUserId, 'agent_cooperation', 'fa', $submitToken));
        $processor->process('123456789', 7103);
        self::assertSame($businessCounts, [
            DB::table('agent_applications')->where('customer_id', $userId)->count(),
            DB::table('agent_application_histories')->count(),
            DB::table('audit_logs')->where('action', 'agent.application.submit')->where('actor_type', 'user')->where('actor_id', (string) $userId)->count(),
        ]);
        self::assertSame($deliveryCount, DB::table('telegram_delivery_operations')->count());
    }

    public function test_agent_submit_recovers_after_business_commit_before_callback_completion_without_duplicate_effect(): void
    {
        $telegramUserId = 9715;
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $this->accept($this->payload(7150, $telegramUserId, 'agent_commit_recovery', 'en', '/start'));
        $processor->process('123456789', 7150);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $accountId = (int) $account->id;
        $userId = (int) $account->user_id;

        $agentToken = $this->callbackToken('navigation.agent', $accountId);
        $this->accept($this->callbackPayload(7151, $telegramUserId, 'agent_commit_recovery', 'en', $agentToken));
        $processor->process('123456789', 7151);
        $submit = DB::table('telegram_interaction_callbacks')
            ->where('telegram_account_id', $accountId)
            ->where('action', 'navigation.agent.submit')
            ->first(['public_id', 'token_ciphertext']);
        self::assertNotNull($submit);
        $submitToken = $this->app->make(StringEncrypter::class)->decryptString((string) $submit->token_ciphertext);
        $deliveriesBefore = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $this->accept($this->callbackPayload(7152, $telegramUserId, 'agent_commit_recovery', 'en', $submitToken));

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_agent_test_fail_delivery_7152
BEFORE INSERT ON telegram_delivery_operations
FOR EACH ROW
BEGIN
    IF NEW.recipient_chat_id = 9715 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-agent-post-commit-presentation-failure';
    END IF;
END
SQL);
        try {
            try {
                $processor->process('123456789', 7152);
                self::fail('The simulated presentation failure must keep the accepted Agent callback retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_agent_test_fail_delivery_7152');
        }

        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', $accountId)->first(['id', 'state', 'version']);
        self::assertNotNull($session);
        self::assertSame('agent_cooperation', (string) $session->state);
        self::assertSame(4, (int) $session->version);
        $this->assertDatabaseHas('telegram_interaction_callbacks', [
            'public_id' => (string) $submit->public_id,
            'state' => 'accepted',
            'accepted_update_id' => 7152,
        ]);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 7152,
            'state' => 'failed',
            'attempt_count' => 1,
        ]);
        self::assertSame(1, DB::table('agent_applications')->where('customer_id', $userId)->count());
        self::assertSame(1, DB::table('agent_application_histories')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'agent.application.submit')->where('actor_type', 'user')->where('actor_id', (string) $userId)->count());
        self::assertSame($deliveriesBefore, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());
        $backCallbacks = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 4)
            ->where('action', 'navigation.back')
            ->count();
        self::assertSame(1, $backCallbacks);

        $processor->process('123456789', 7152);

        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 7152,
            'state' => 'processed',
            'attempt_count' => 2,
        ]);
        $this->assertDatabaseHas('telegram_interaction_callbacks', [
            'public_id' => (string) $submit->public_id,
            'state' => 'completed',
            'accepted_update_id' => 7152,
        ]);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'agent_cooperation',
            'version' => 4,
        ]);
        self::assertSame(1, DB::table('agent_applications')->where('customer_id', $userId)->count());
        self::assertSame(1, DB::table('agent_application_histories')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'agent.application.submit')->where('actor_type', 'user')->where('actor_id', (string) $userId)->count());
        self::assertSame($deliveriesBefore + 1, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());
        self::assertSame($backCallbacks, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 4)
            ->where('action', 'navigation.back')
            ->count());
        self::assertStringContainsString('Current status: Submitted', $this->latestConfidentialPresentation());
    }

    public function test_agent_submit_revalidates_current_customer_state_before_mutation(): void
    {
        $telegramUserId = 9720;
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $this->accept($this->payload(7200, $telegramUserId, 'agent_revalidate', 'en', '/start'));
        $processor->process('123456789', 7200);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $accountId = (int) $account->id;
        $userId = (int) $account->user_id;

        $agentToken = $this->callbackToken('navigation.agent', $accountId);
        $this->accept($this->callbackPayload(7201, $telegramUserId, 'agent_revalidate', 'en', $agentToken));
        $processor->process('123456789', 7201);
        $submitToken = $this->callbackToken('navigation.agent.submit', $accountId);

        DB::table('users')->where('id', $userId)->update([
            'account_status' => 'suspended',
            'updated_at' => now('UTC'),
        ]);
        $this->accept($this->callbackPayload(7202, $telegramUserId, 'agent_revalidate', 'en', $submitToken));
        $processor->process('123456789', 7202);

        self::assertSame(0, DB::table('agent_applications')->where('customer_id', $userId)->count());
        self::assertSame(0, DB::table('agent_application_histories')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'agent.application.submit')->where('actor_type', 'user')->where('actor_id', (string) $userId)->count());
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'telegram_account_id' => $accountId,
            'state' => 'agent_cooperation_unavailable',
            'version' => 3,
            'payload' => '{}',
        ]);
        $presentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('could not be submitted', $presentation);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) DB::table('telegram_interaction_sessions')->where('telegram_account_id', $accountId)->value('id'))
            ->where('session_version', 3)
            ->where('action', 'navigation.agent.submit')
            ->where('state', 'pending')
            ->count());
    }

    public function test_approved_agent_gets_localized_agent_menu_and_single_purchase_with_execution_time_revalidation(): void
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog('agent');
        $quotes = new TelegramNavigationCustomerPurchaseQuote($catalog);
        $paymentMethods = new TelegramNavigationCustomerPurchasePaymentMethods;
        $purchaseOrders = new TelegramNavigationCustomerPurchaseOrder;
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->app->instance(TelegramCustomerPurchaseQuote::class, $quotes);
        $this->app->instance(TelegramCustomerPurchasePaymentMethods::class, $paymentMethods);
        $this->app->instance(TelegramCustomerPurchaseOrder::class, $purchaseOrders);
        $telegramUserId = 9730;
        $reviewerTelegramUserId = 9731;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7300, $telegramUserId, 'agent_approved', 'en', '/start'));
        $processor->process('123456789', 7300);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $accountId = (int) $account->id;
        $userId = (int) $account->user_id;

        $this->accept($this->payload(7310, $reviewerTelegramUserId, 'agent_reviewer', 'en', '/start'));
        $processor->process('123456789', 7310);
        $reviewerUserId = DB::table('telegram_accounts')->where('telegram_user_id', $reviewerTelegramUserId)->value('user_id');
        self::assertIsNumeric($reviewerUserId);
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => (int) $reviewerUserId,
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $applications = $this->app->make(AgentApplicationService::class);
        $applications->submit($userId, new AgentChangeContext(
            'telegram-agent-approved-submit-7300',
            'telegram-agent-submit-correlation-7300',
            'customer_request',
            actorUserId: $userId,
        ));
        $applicationId = DB::table('agent_applications')->where('customer_id', $userId)->value('id');
        self::assertIsNumeric($applicationId);
        $applications->claim((int) $applicationId, new AgentChangeContext(
            'telegram-agent-approved-claim-7300',
            'telegram-agent-claim-correlation-7300',
            'review_action',
            actorAdministratorId: $administratorId,
        ));
        $applications->approve((int) $applicationId, 'default', new AgentChangeContext(
            'telegram-agent-approved-final-7300',
            'telegram-agent-final-correlation-7300',
            'approved',
            'Approved for Telegram Agent navigation test.',
            actorAdministratorId: $administratorId,
        ));

        $this->accept($this->payload(7301, $telegramUserId, 'agent_approved', 'en', '/menu'));
        $processor->process('123456789', 7301);
        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', $accountId)->first(['id', 'version']);
        self::assertNotNull($session);
        $homeOperation = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->orderByDesc('id')->first(['public_id']);
        self::assertNotNull($homeOperation);
        $homeKeyboard = DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $homeOperation->public_id)
            ->value('keyboard_snapshot');
        self::assertIsString($homeKeyboard);
        self::assertStringContainsString('Agent Menu', $homeKeyboard);
        self::assertStringNotContainsString('Request Cooperation', $homeKeyboard);
        self::assertStringNotContainsString('Buy Service', $homeKeyboard);

        $agentToken = $this->callbackToken('navigation.agent', $accountId);
        $this->accept($this->callbackPayload(7302, $telegramUserId, 'agent_approved', 'en', $agentToken));
        $processor->process('123456789', 7302);
        $presentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('Agent Menu', $presentation);
        self::assertStringContainsString('Agent status: Active', $presentation);
        self::assertStringContainsString('Member since:', $presentation);
        self::assertStringContainsString('Approved at:', $presentation);
        self::assertStringContainsString('Services purchased as Agent: 0', $presentation);
        self::assertStringNotContainsString('Approved for Telegram Agent navigation test.', $presentation);
        $currentVersion = (int) DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->value('version');
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', $currentVersion)
            ->where('action', 'navigation.agent.submit')
            ->count());
        self::assertSame(1, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', $currentVersion)
            ->where('action', 'navigation.agent.purchase')
            ->where('state', 'pending')
            ->count());

        $beforePurchase = $this->purchaseMutationCounts();
        $purchaseToken = $this->callbackToken('navigation.agent.purchase', $accountId);
        $this->accept($this->callbackPayload(7303, $telegramUserId, 'agent_approved', 'en', $purchaseToken));
        $processor->process('123456789', 7303);
        $purchaseSession = DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->first(['state', 'version']);
        self::assertNotNull($purchaseSession);
        self::assertSame('purchase_catalog', (string) $purchaseSession->state);
        self::assertSame($currentVersion + 1, (int) $purchaseSession->version);
        self::assertStringContainsString(
            'The listed amount is the base price.',
            $this->latestConfidentialPresentation(),
        );
        self::assertSame($beforePurchase, $this->purchaseMutationCounts());

        $offeringToken = $this->callbackToken('navigation.purchase.'.str_repeat('c', 40), $accountId);
        $this->accept($this->callbackPayload(7320, $telegramUserId, 'agent_approved', 'en', $offeringToken));
        $processor->process('123456789', 7320);
        $quoteToken = $this->callbackToken('navigation.purchase.quote', $accountId);
        $this->accept($this->callbackPayload(7321, $telegramUserId, 'agent_approved', 'en', $quoteToken));
        $processor->process('123456789', 7321);
        self::assertCount(1, $quotes->calls);
        $quoteSession = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->first(['state', 'version']);
        self::assertNotNull($quoteSession);
        self::assertSame('purchase_quote', (string) $quoteSession->state);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', (int) $quoteSession->version)
            ->where('action', 'navigation.purchase.discount')
            ->count());
        self::assertSame(1, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', (int) $quoteSession->version)
            ->where('action', 'navigation.purchase.payment_methods')
            ->where('state', 'pending')
            ->count());
        self::assertStringContainsString('Service Purchase Quote', $this->latestConfidentialPresentation());

        $paymentToken = $this->callbackToken('navigation.purchase.payment_methods', $accountId);
        $this->accept($this->callbackPayload(7322, $telegramUserId, 'agent_approved', 'en', $paymentToken));
        $processor->process('123456789', 7322);
        self::assertCount(1, $paymentMethods->calls);
        self::assertCount(1, $purchaseOrders->openCalls);
        self::assertSame($userId, $paymentMethods->calls[0]['actor_user_id']);
        self::assertSame($userId, $paymentMethods->calls[0]['subject_user_id']);
        self::assertSame($userId, $purchaseOrders->openCalls[0]['actor_user_id']);
        self::assertSame($userId, $purchaseOrders->openCalls[0]['subject_user_id']);
        $paymentSession = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->first(['state', 'version', 'payload']);
        self::assertNotNull($paymentSession);
        self::assertSame('purchase_payment_methods', (string) $paymentSession->state);
        self::assertStringContainsString(str_pad('01K', 26, '0'), (string) $paymentSession->payload);
        self::assertStringContainsString(str_pad('01N', 26, '0'), (string) $paymentSession->payload);
        self::assertSame($beforePurchase, $this->purchaseMutationCounts());

        $deliveryCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $processor->process('123456789', 7303);
        self::assertSame((int) $paymentSession->version, (int) DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->value('version'));
        self::assertSame($deliveryCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());
        self::assertSame($beforePurchase, $this->purchaseMutationCounts());

        $this->accept($this->payload(7304, $telegramUserId, 'agent_approved', 'en', '/menu'));
        $processor->process('123456789', 7304);
        $agentToken = $this->callbackToken('navigation.agent', $accountId);
        $this->accept($this->callbackPayload(7305, $telegramUserId, 'agent_approved', 'en', $agentToken));
        $processor->process('123456789', 7305);
        $staleVersion = (int) DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->value('version');
        $stalePurchaseToken = $this->callbackToken('navigation.agent.purchase', $accountId);
        DB::table('agent_profiles')->where('user_id', $userId)->update([
            'status' => 'suspended',
            'suspended_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $beforeDeniedPurchase = $this->purchaseMutationCounts();

        $this->accept($this->callbackPayload(7306, $telegramUserId, 'agent_approved', 'en', $stalePurchaseToken));
        $processor->process('123456789', 7306);

        $deniedSession = DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->first(['state', 'version']);
        self::assertNotNull($deniedSession);
        self::assertSame('agent_cooperation', (string) $deniedSession->state);
        self::assertSame($staleVersion, (int) $deniedSession->version);
        self::assertStringContainsString('Agent status: Suspended', $this->latestConfidentialPresentation());
        self::assertSame($beforeDeniedPurchase, $this->purchaseMutationCounts());
    }

    public function test_agent_menu_purchased_services_delegates_to_canonical_my_services_and_replays_safely(): void
    {
        $telegramUserId = 9750;
        $reviewerTelegramUserId = 9751;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7500, $telegramUserId, 'agent_services', 'en', '/start'));
        $processor->process('123456789', 7500);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $accountId = (int) $account->id;
        $userId = (int) $account->user_id;

        $this->accept($this->payload(7510, $reviewerTelegramUserId, 'agent_services_reviewer', 'en', '/start'));
        $processor->process('123456789', 7510);
        $reviewerUserId = DB::table('telegram_accounts')->where('telegram_user_id', $reviewerTelegramUserId)->value('user_id');
        self::assertIsNumeric($reviewerUserId);
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => (int) $reviewerUserId,
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $applications = $this->app->make(AgentApplicationService::class);
        $applications->submit($userId, new AgentChangeContext(
            'telegram-agent-services-submit-7500',
            'telegram-agent-services-submit-correlation-7500',
            'customer_request',
            actorUserId: $userId,
        ));
        $applicationId = DB::table('agent_applications')->where('customer_id', $userId)->value('id');
        self::assertIsNumeric($applicationId);
        $applications->claim((int) $applicationId, new AgentChangeContext(
            'telegram-agent-services-claim-7500',
            'telegram-agent-services-claim-correlation-7500',
            'review_action',
            actorAdministratorId: $administratorId,
        ));
        $applications->approve((int) $applicationId, 'default', new AgentChangeContext(
            'telegram-agent-services-approved-7500',
            'telegram-agent-services-approved-correlation-7500',
            'approved',
            'Approved for Telegram Agent purchased-services navigation test.',
            actorAdministratorId: $administratorId,
        ));

        $this->accept($this->payload(7501, $telegramUserId, 'agent_services', 'en', '/menu'));
        $processor->process('123456789', 7501);
        $agentToken = $this->callbackToken('navigation.agent', $accountId);
        $this->accept($this->callbackPayload(7502, $telegramUserId, 'agent_services', 'en', $agentToken));
        $processor->process('123456789', 7502);

        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $accountId)
            ->first(['id', 'state', 'version']);
        self::assertNotNull($session);
        self::assertSame('agent_cooperation', (string) $session->state);
        $agentVersion = (int) $session->version;
        self::assertSame(1, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', $agentVersion)
            ->where('action', 'navigation.agent.services')
            ->where('state', 'pending')
            ->count());

        $agentOperation = DB::table('telegram_delivery_operations')
            ->where('recipient_chat_id', $telegramUserId)
            ->orderByDesc('id')
            ->first(['public_id']);
        self::assertNotNull($agentOperation);
        $agentKeyboard = DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $agentOperation->public_id)
            ->value('keyboard_snapshot');
        self::assertIsString($agentKeyboard);
        self::assertStringContainsString('Purchased services', $agentKeyboard);

        $servicesToken = $this->callbackToken('navigation.agent.services', $accountId);
        $this->accept($this->callbackPayload(7503, $telegramUserId, 'agent_services', 'en', $servicesToken));
        $processor->process('123456789', 7503);

        $servicesSession = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->first(['state', 'version', 'payload']);
        self::assertNotNull($servicesSession);
        self::assertSame('my_services', (string) $servicesSession->state);
        self::assertSame($agentVersion + 1, (int) $servicesSession->version);
        self::assertSame('{"page":1}', (string) $servicesSession->payload);
        self::assertStringContainsString('My Services', $this->latestConfidentialPresentation());
        self::assertStringContainsString('You do not have any services yet.', $this->latestConfidentialPresentation());
        self::assertSame(1, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', (int) $servicesSession->version)
            ->where('action', 'navigation.services.search')
            ->where('state', 'pending')
            ->count());

        $deliveryCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $callbackCount = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->count();
        $processor->process('123456789', 7503);
        self::assertSame((int) $servicesSession->version, (int) DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->value('version'));
        self::assertSame($deliveryCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());
        self::assertSame($callbackCount, DB::table('telegram_interaction_callbacks')->where('telegram_interaction_session_id', (int) $session->id)->count());

        $searchToken = $this->callbackToken('navigation.services.search', $accountId);
        $this->accept($this->callbackPayload(7504, $telegramUserId, 'agent_services', 'en', $searchToken));
        $processor->process('123456789', 7504);
        $searchSession = DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->first(['state', 'version']);
        self::assertNotNull($searchSession);
        self::assertSame('service_search', (string) $searchSession->state);
        self::assertSame((int) $servicesSession->version + 1, (int) $searchSession->version);
        self::assertStringContainsString('Search My Services', $this->latestConfidentialPresentation());
    }

    public function test_agent_navigation_recovers_through_back_entry_command_and_cancel_without_agent_mutation(): void
    {
        $telegramUserId = 9740;
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $this->accept($this->payload(7400, $telegramUserId, 'agent_recovery', 'en', '/start'));
        $processor->process('123456789', 7400);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $accountId = (int) $account->id;
        $userId = (int) $account->user_id;

        $agentToken = $this->callbackToken('navigation.agent', $accountId);
        $this->accept($this->callbackPayload(7401, $telegramUserId, 'agent_recovery', 'en', $agentToken));
        $processor->process('123456789', 7401);
        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', $accountId)->first(['id', 'public_id', 'state', 'status', 'version']);
        self::assertNotNull($session);
        self::assertSame('agent_cooperation', (string) $session->state);
        self::assertSame('active', (string) $session->status);
        self::assertSame(2, (int) $session->version);

        $backToken = $this->callbackToken('navigation.back', $accountId);
        $this->accept($this->callbackPayload(7402, $telegramUserId, 'agent_recovery', 'en', $backToken));
        $processor->process('123456789', 7402);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'home',
            'status' => 'active',
            'version' => 3,
        ]);
        self::assertSame(0, DB::table('agent_applications')->where('customer_id', $userId)->count());

        $agentToken = $this->callbackToken('navigation.agent', $accountId);
        $this->accept($this->callbackPayload(7403, $telegramUserId, 'agent_recovery', 'en', $agentToken));
        $processor->process('123456789', 7403);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'agent_cooperation',
            'status' => 'active',
            'version' => 4,
        ]);

        $this->accept($this->payload(7404, $telegramUserId, 'agent_recovery', 'en', '/menu'));
        $processor->process('123456789', 7404);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'home',
            'status' => 'active',
            'version' => 5,
        ]);
        self::assertSame(0, DB::table('agent_applications')->where('customer_id', $userId)->count());

        $agentToken = $this->callbackToken('navigation.agent', $accountId);
        $this->accept($this->callbackPayload(7405, $telegramUserId, 'agent_recovery', 'en', $agentToken));
        $processor->process('123456789', 7405);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'agent_cooperation',
            'status' => 'active',
            'version' => 6,
        ]);

        $this->accept($this->payload(7406, $telegramUserId, 'agent_recovery', 'en', '/cancel'));
        $processor->process('123456789', 7406);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'status' => 'cancelled',
            'active_telegram_account_id' => null,
        ]);
        self::assertSame(0, DB::table('agent_applications')->where('customer_id', $userId)->count());

        $this->accept($this->payload(7407, $telegramUserId, 'agent_recovery', 'en', '/start'));
        $processor->process('123456789', 7407);
        $restarted = DB::table('telegram_interaction_sessions')
            ->where('active_telegram_account_id', $accountId)
            ->first(['id', 'state', 'status', 'version']);
        self::assertNotNull($restarted);
        self::assertNotSame((int) $session->id, (int) $restarted->id);
        self::assertSame('home', (string) $restarted->state);
        self::assertSame('active', (string) $restarted->status);
        self::assertSame(1, (int) $restarted->version);
        self::assertSame(0, DB::table('agent_applications')->where('customer_id', $userId)->count());
    }

    public function test_agent_existing_application_states_render_without_exposing_review_details(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9741;
        $reviewerTelegramUserId = 9742;

        $this->accept($this->payload(7410, $telegramUserId, 'agent_states', 'en', 'hello'));
        $processor->process('123456789', 7410);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $accountId = (int) $account->id;
        $userId = (int) $account->user_id;

        $this->accept($this->payload(7411, $reviewerTelegramUserId, 'agent_states_reviewer', 'en', 'hello'));
        $processor->process('123456789', 7411);
        $reviewerUserId = DB::table('telegram_accounts')->where('telegram_user_id', $reviewerTelegramUserId)->value('user_id');
        self::assertIsNumeric($reviewerUserId);
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => (int) $reviewerUserId,
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $applications = $this->app->make(AgentApplicationService::class);
        $applications->submit($userId, new AgentChangeContext(
            'telegram-agent-states-submit-7410',
            'telegram-agent-states-submit-correlation-7410',
            'customer_request',
            actorUserId: $userId,
        ));
        $applicationId = DB::table('agent_applications')->where('customer_id', $userId)->value('id');
        self::assertIsNumeric($applicationId);
        $applications->claim((int) $applicationId, new AgentChangeContext(
            'telegram-agent-states-claim-7410',
            'telegram-agent-states-claim-correlation-7410',
            'review_action',
            actorAdministratorId: $administratorId,
        ));

        $this->accept($this->payload(7412, $telegramUserId, 'agent_states', 'en', '/start'));
        $processor->process('123456789', 7412);
        $agentToken = $this->callbackToken('navigation.agent', $accountId);
        $this->accept($this->callbackPayload(7413, $telegramUserId, 'agent_states', 'en', $agentToken));
        $processor->process('123456789', 7413);
        self::assertStringContainsString('Current status: Under review', $this->latestConfidentialPresentation());
        $sessionId = DB::table('telegram_interaction_sessions')->where('telegram_account_id', $accountId)->value('id');
        self::assertIsNumeric($sessionId);
        $currentVersion = DB::table('telegram_interaction_sessions')->where('id', (int) $sessionId)->value('version');
        self::assertIsNumeric($currentVersion);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $sessionId)
            ->where('session_version', (int) $currentVersion)
            ->where('action', 'navigation.agent.submit')
            ->count());

        $privateReason = 'Private reviewer reason must never be presented to the customer.';
        $applications->reject((int) $applicationId, new AgentChangeContext(
            'telegram-agent-states-reject-7410',
            'telegram-agent-states-reject-correlation-7410',
            'not_eligible',
            $privateReason,
            actorAdministratorId: $administratorId,
        ));
        $this->accept($this->payload(7414, $telegramUserId, 'agent_states', 'en', '/menu'));
        $processor->process('123456789', 7414);
        $agentToken = $this->callbackToken('navigation.agent', $accountId);
        $this->accept($this->callbackPayload(7415, $telegramUserId, 'agent_states', 'en', $agentToken));
        $processor->process('123456789', 7415);
        $presentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('Current status: Rejected', $presentation);
        self::assertStringNotContainsString($privateReason, $presentation);
        self::assertStringNotContainsString((string) $administratorId, $presentation);

        $withdrawnTelegramUserId = 9743;
        $this->accept($this->payload(7420, $withdrawnTelegramUserId, 'agent_withdrawn', 'en', 'hello'));
        $processor->process('123456789', 7420);
        $withdrawnAccount = DB::table('telegram_accounts')->where('telegram_user_id', $withdrawnTelegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($withdrawnAccount);
        $withdrawnAccountId = (int) $withdrawnAccount->id;
        $withdrawnUserId = (int) $withdrawnAccount->user_id;
        $applications->submit($withdrawnUserId, new AgentChangeContext(
            'telegram-agent-withdrawn-submit-7420',
            'telegram-agent-withdrawn-submit-correlation-7420',
            'customer_request',
            actorUserId: $withdrawnUserId,
        ));
        $withdrawnApplicationId = DB::table('agent_applications')->where('customer_id', $withdrawnUserId)->value('id');
        self::assertIsNumeric($withdrawnApplicationId);
        $applications->withdraw((int) $withdrawnApplicationId, new AgentChangeContext(
            'telegram-agent-withdrawn-final-7420',
            'telegram-agent-withdrawn-final-correlation-7420',
            'customer_withdrawal',
            actorUserId: $withdrawnUserId,
        ));

        $this->accept($this->payload(7421, $withdrawnTelegramUserId, 'agent_withdrawn', 'en', '/start'));
        $processor->process('123456789', 7421);
        $withdrawnToken = $this->callbackToken('navigation.agent', $withdrawnAccountId);
        $this->accept($this->callbackPayload(7422, $withdrawnTelegramUserId, 'agent_withdrawn', 'en', $withdrawnToken));
        $processor->process('123456789', 7422);
        self::assertStringContainsString('Current status: Withdrawn', $this->latestConfidentialPresentation());
        $withdrawnSessionId = DB::table('telegram_interaction_sessions')->where('telegram_account_id', $withdrawnAccountId)->value('id');
        self::assertIsNumeric($withdrawnSessionId);
        $withdrawnVersion = DB::table('telegram_interaction_sessions')->where('id', (int) $withdrawnSessionId)->value('version');
        self::assertIsNumeric($withdrawnVersion);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $withdrawnSessionId)
            ->where('session_version', (int) $withdrawnVersion)
            ->where('action', 'navigation.agent.submit')
            ->count());
    }

    public function test_arbitrary_text_and_non_private_start_do_not_implicitly_create_navigation_authority(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(6103, 9603, 'navigation_ignored', 'fa', 'hello'));
        $processor->process('123456789', 6103);

        $this->accept($this->payload(6104, 9604, 'navigation_group', 'fa', '/start', 'group', -1009604));
        $processor->process('123456789', 6104);

        self::assertSame(0, DB::table('telegram_interaction_sessions')->count());
        self::assertSame(0, DB::table('telegram_delivery_operations')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6103, 'state' => 'processed']);
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6104, 'state' => 'processed']);
    }

    public function test_post_dispatch_failure_replays_the_same_session_callback_and_delivery_operation_without_duplication(): void
    {
        $this->accept($this->payload(6105, 9605, 'navigation_retry', 'fa', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_navigation_test_fail_processed_6105
BEFORE UPDATE ON processed_telegram_updates
FOR EACH ROW
BEGIN
    IF OLD.bot_id = '123456789' AND OLD.update_id = 6105 AND NEW.state = 'processed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-post-navigation-dispatch-failure';
    END IF;
END
SQL);
        try {
            try {
                $processor->process('123456789', 6105);
                self::fail('The simulated post-dispatch failure must keep the navigation update retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_6105');
        }

        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6105, 'state' => 'failed', 'attempt_count' => 1]);
        self::assertSame(1, DB::table('telegram_interaction_sessions')->count());
        self::assertSame(1, DB::table('telegram_interaction_update_bindings')->where('update_id', 6105)->count());
        self::assertSame(1, DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_account')->count());
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());

        $firstSessionId = (int) DB::table('telegram_interaction_sessions')->value('id');
        $firstCallbackId = (string) DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_account')->value('public_id');
        $firstOperationId = (string) DB::table('telegram_delivery_operations')->value('public_id');

        $processor->process('123456789', 6105);

        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6105, 'state' => 'processed', 'attempt_count' => 2]);
        self::assertSame(1, DB::table('telegram_interaction_sessions')->count());
        self::assertSame($firstSessionId, (int) DB::table('telegram_interaction_sessions')->value('id'));
        self::assertSame(1, DB::table('telegram_interaction_update_bindings')->where('update_id', 6105)->count());
        self::assertSame(1, DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_account')->count());
        self::assertSame($firstCallbackId, (string) DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_account')->value('public_id'));
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());
        self::assertSame($firstOperationId, (string) DB::table('telegram_delivery_operations')->value('public_id'));
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());
    }

    /** @return array{TelegramUpdateProcessor,int,int,TelegramNavigationCustomerPurchaseCardToCardPayment} */
    private function prepareSelectedCardToCardJourney(int $telegramUserId, int $baseUpdateId, string $username): array
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog;
        $quotes = new TelegramNavigationCustomerPurchaseQuote($catalog);
        $paymentMethods = new TelegramNavigationCustomerPurchasePaymentMethods;
        $paymentMethods->methodCodes = ['card_to_card', 'wallet'];
        $purchaseOrders = new TelegramNavigationCustomerPurchaseOrder;
        $cardToCardPayments = new TelegramNavigationCustomerPurchaseCardToCardPayment;
        $walletPayments = new TelegramNavigationCustomerPurchaseWalletPayment;
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->app->instance(TelegramCustomerPurchaseQuote::class, $quotes);
        $this->app->instance(TelegramCustomerPurchasePaymentMethods::class, $paymentMethods);
        $this->app->instance(TelegramCustomerPurchaseOrder::class, $purchaseOrders);
        $this->app->instance(TelegramCustomerPurchaseCardToCardPayment::class, $cardToCardPayments);
        $this->app->instance(TelegramCustomerPurchaseWalletPayment::class, $walletPayments);
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload($baseUpdateId, $telegramUserId, $username, 'fa', '/start'));
        $processor->process('123456789', $baseUpdateId);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        $accountId = (int) $account->id;
        foreach ([
            [$baseUpdateId + 1, 'navigation.purchase'],
            [$baseUpdateId + 2, 'navigation.purchase.'.str_repeat('c', 40)],
            [$baseUpdateId + 3, 'navigation.purchase.quote'],
            [$baseUpdateId + 4, 'navigation.purchase.payment_methods'],
        ] as [$updateId, $action]) {
            $token = $this->callbackToken($action, $accountId);
            $this->accept($this->callbackPayload($updateId, $telegramUserId, $username, 'fa', $token));
            $processor->process('123456789', $updateId);
        }
        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', $accountId)->first(['id', 'state', 'version']);
        self::assertNotNull($session);
        self::assertSame('purchase_payment_methods', (string) $session->state);
        self::assertSame(7, (int) $session->version);
        $cardToCardCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 7)
            ->where('action', 'navigation.purchase.payment_method.select')
            ->where('action_payload', json_encode(['method_code' => 'card_to_card'], JSON_THROW_ON_ERROR))
            ->first(['token_ciphertext']);
        self::assertNotNull($cardToCardCallback);
        $cardToCardToken = $this->app->make(StringEncrypter::class)->decryptString((string) $cardToCardCallback->token_ciphertext);
        $this->accept($this->callbackPayload($baseUpdateId + 5, $telegramUserId, $username, 'fa', $cardToCardToken));
        $processor->process('123456789', $baseUpdateId + 5);
        $selected = DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->first(['state', 'version']);
        self::assertNotNull($selected);
        self::assertSame('purchase_payment_method_selected', (string) $selected->state);
        self::assertSame(9, (int) $selected->version);

        return [$processor, $accountId, (int) $session->id, $cardToCardPayments];
    }

    /** @return array{TelegramUpdateProcessor,int,int,TelegramNavigationCustomerPurchaseWalletPayment} */
    private function prepareSelectedWalletJourney(int $telegramUserId, int $baseUpdateId, string $username): array
    {
        $catalog = new TelegramNavigationCustomerPurchaseCatalog;
        $quotes = new TelegramNavigationCustomerPurchaseQuote($catalog);
        $paymentMethods = new TelegramNavigationCustomerPurchasePaymentMethods;
        $purchaseOrders = new TelegramNavigationCustomerPurchaseOrder;
        $walletPayments = new TelegramNavigationCustomerPurchaseWalletPayment;
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->app->instance(TelegramCustomerPurchaseQuote::class, $quotes);
        $this->app->instance(TelegramCustomerPurchasePaymentMethods::class, $paymentMethods);
        $this->app->instance(TelegramCustomerPurchaseOrder::class, $purchaseOrders);
        $this->app->instance(TelegramCustomerPurchaseWalletPayment::class, $walletPayments);
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload($baseUpdateId, $telegramUserId, $username, 'fa', '/start'));
        $processor->process('123456789', $baseUpdateId);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        $accountId = (int) $account->id;
        foreach ([
            [$baseUpdateId + 1, 'navigation.purchase'],
            [$baseUpdateId + 2, 'navigation.purchase.'.str_repeat('c', 40)],
            [$baseUpdateId + 3, 'navigation.purchase.quote'],
            [$baseUpdateId + 4, 'navigation.purchase.payment_methods'],
        ] as [$updateId, $action]) {
            $token = $this->callbackToken($action, $accountId);
            $this->accept($this->callbackPayload($updateId, $telegramUserId, $username, 'fa', $token));
            $processor->process('123456789', $updateId);
        }
        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', $accountId)->first(['id', 'state', 'version']);
        self::assertNotNull($session);
        self::assertSame('purchase_payment_methods', (string) $session->state);
        self::assertSame(7, (int) $session->version);
        $walletCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 7)
            ->where('action', 'navigation.purchase.payment_method.select')
            ->where('action_payload', json_encode(['method_code' => 'wallet'], JSON_THROW_ON_ERROR))
            ->first(['token_ciphertext']);
        self::assertNotNull($walletCallback);
        $walletToken = $this->app->make(StringEncrypter::class)->decryptString((string) $walletCallback->token_ciphertext);
        $this->accept($this->callbackPayload($baseUpdateId + 5, $telegramUserId, $username, 'fa', $walletToken));
        $processor->process('123456789', $baseUpdateId + 5);
        $selected = DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->first(['state', 'version']);
        self::assertNotNull($selected);
        self::assertSame('purchase_payment_method_selected', (string) $selected->state);
        self::assertSame(9, (int) $selected->version);

        return [$processor, $accountId, (int) $session->id, $walletPayments];
    }

    /** @param array<string, mixed> $payload */
    private function accept(array $payload): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk();
    }

    /** @return array<string, mixed> */
    private function payload(
        int $updateId,
        int $telegramUserId,
        string $username,
        string $languageCode,
        string $text,
        string $chatType = 'private',
        ?int $chatId = null,
    ): array {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'date' => 1_700_000_000,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'username' => $username,
                    'language_code' => $languageCode,
                ],
                'chat' => [
                    'id' => $chatId ?? $telegramUserId,
                    'type' => $chatType,
                ],
                'text' => $text,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function callbackPayload(
        int $updateId,
        int $telegramUserId,
        string $username,
        string $languageCode,
        string $token,
    ): array {
        return [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => 'callback-'.$updateId,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'username' => $username,
                    'language_code' => $languageCode,
                ],
                'message' => [
                    'message_id' => $updateId,
                    'date' => 1_700_000_000,
                    'chat' => [
                        'id' => $telegramUserId,
                        'type' => 'private',
                    ],
                ],
                'data' => $token,
            ],
        ];
    }

    private function messageActionFromBinding(
        TelegramInteractionUpdateBindingReceipt $binding,
        int $updateId,
        string $messageText,
    ): TelegramInteractionAction {
        self::assertNotNull($binding->sessionPublicId);
        self::assertNotNull($binding->flow);
        self::assertNotNull($binding->sessionState);
        self::assertNotNull($binding->sessionVersion);

        return new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $binding->requestKey,
            '123456789',
            $updateId,
            $binding->telegramAccountId,
            $binding->userId,
            $binding->telegramUserId,
            $binding->sessionPublicId,
            $binding->flow,
            $binding->sessionState,
            $binding->sessionVersion,
            $binding->sessionPayload,
            $messageText,
            null,
            null,
            [],
            $binding->replayed,
            null,
            $binding->acceptedAt,
        );
    }

    private function callbackActionFromAcceptance(
        TelegramInteractionCallbackReceipt $callback,
        int $updateId,
        int $telegramUserId,
    ): TelegramInteractionAction {
        return new TelegramInteractionAction(
            TelegramInteractionActionKind::Callback,
            $callback->requestKey,
            '123456789',
            $updateId,
            $callback->telegramAccountId,
            $callback->userId,
            $telegramUserId,
            $callback->sessionPublicId,
            $callback->flow,
            $callback->sessionState,
            $callback->sessionVersion,
            $callback->sessionPayload,
            null,
            $callback->publicId,
            $callback->action,
            $callback->payload,
            $callback->replayed,
            $callback->acceptedAt,
        );
    }

    private function financeAdministratorForUser(int $userId): int
    {
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = DB::table('roles')->where('code', 'finance')->where('is_active', true)->value('id');
        self::assertIsNumeric($roleId);
        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $administratorId,
            'role_id' => (int) $roleId,
            'granted_by_administrator_id' => null,
            'granted_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $administratorId;
    }

    private function callbackToken(string $action, int $telegramAccountId): string
    {
        $sessionId = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $telegramAccountId)
            ->value('id');
        self::assertIsNumeric($sessionId);
        $callback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $sessionId)
            ->where('action', $action)
            ->orderByDesc('id')
            ->first(['action_payload', 'token_ciphertext']);
        self::assertNotNull($callback);
        self::assertSame('{}', (string) $callback->action_payload);
        self::assertIsString($callback->token_ciphertext);

        return $this->app->make(StringEncrypter::class)->decryptString((string) $callback->token_ciphertext);
    }

    private function latestConfidentialPresentation(): string
    {
        $operationPublicId = DB::table('telegram_delivery_operations')->orderByDesc('id')->value('public_id');
        self::assertIsString($operationPublicId);
        $ciphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $operationPublicId)
            ->value('presentation_ciphertext');
        self::assertIsString($ciphertext);

        return $this->app->make(StringEncrypter::class)->decryptString($ciphertext);
    }

    private function navigationCommonDurableEvidence(int $sessionId, int $telegramUserId): string
    {
        return json_encode([
            'sessions' => DB::table('telegram_interaction_sessions')->where('id', $sessionId)->get()->all(),
            'transitions' => DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', $sessionId)->get()->all(),
            'update_bindings' => DB::table('telegram_interaction_update_bindings')->where('telegram_interaction_session_id', $sessionId)->get()->all(),
            'callbacks' => DB::table('telegram_interaction_callbacks')->where('telegram_interaction_session_id', $sessionId)->get(['action', 'action_payload', 'issue_request_hash', 'issue_command_hash'])->all(),
            'operations' => DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->get()->all(),
            'outbox' => DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->get()->all(),
            'keyboards' => DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->get(['delivery_operation_public_id', 'keyboard_snapshot'])->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function ledgerAccount(
        string $code,
        string $class,
        ?int $userId = null,
        ?string $bucket = null,
    ): int {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => $code,
            'account_class' => $class,
            'owner_user_id' => $userId,
            'wallet_bucket' => $bucket,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string,int> */
    private function purchaseMutationCounts(): array
    {
        return [
            'quotes' => DB::table('quotes')->count(),
            'route_selections' => DB::table('plan_offering_route_selections')->count(),
            'capacity_reservations' => DB::table('panel_capacity_reservations')->count(),
            'payment_intents' => DB::table('payment_intents')->count(),
            'orders' => DB::table('orders')->count(),
            'services' => DB::table('service_subscriptions')->count(),
        ];
    }

    /** @return array<string,int> */
    private function walletMutationCounts(): array
    {
        return [
            'accounts' => DB::table('ledger_accounts')->count(),
            'transactions' => DB::table('ledger_transactions')->count(),
            'entries' => DB::table('ledger_entries')->count(),
            'holds' => DB::table('wallet_holds')->count(),
        ];
    }

    /** @return array<string,int> */
    private function referralMutationCounts(): array
    {
        return [
            'identities' => DB::table('referral_identities')->count(),
            'relationships' => DB::table('referral_relationships')->count(),
            'events' => DB::table('referral_attribution_events')->count(),
        ];
    }
}

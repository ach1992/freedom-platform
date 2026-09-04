<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Orders\Domain\OrderState;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseWalletPayment;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseWalletPaid;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseWalletReservation;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseWalletUnavailable;
use App\Modules\Wallet\Application\WalletCashAccountService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramCustomerPurchaseWalletPaymentService implements TelegramCustomerPurchaseWalletPayment
{
    private const METHOD_CODE = 'wallet';

    public function __construct(
        private DatabaseManager $database,
        private TelegramCustomerPurchaseOrder $orders,
        private TelegramCustomerPurchasePaymentMethods $paymentMethods,
        private WalletCashAccountService $cashAccounts,
        private PurchaseWalletPaymentService $walletPayments,
    ) {}

    /** @requirement BUY-001 BUY-003 PAY-001 PAY-002 PRO-001 WAL-002 DAT-002 DAT-003 SEC-002 QUA-001 */
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
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertOperationKey($operationKey);

        return $this->database->connection()->transaction(function () use (
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
            $operationKey,
        ): TelegramCustomerPurchaseWalletReservation {
            $order = $this->orders->currentForSelf(
                $actorUserId,
                $subjectUserId,
                $orderPublicId,
                $quotePublicId,
                $quoteConfigurationHash,
            );
            $selection = $this->paymentMethods->selectForSelf(
                $actorUserId,
                $subjectUserId,
                $quotePublicId,
                $quoteConfigurationHash,
                $decisionPublicId,
                $decisionConfigurationHash,
                self::METHOD_CODE,
            );
            if (! hash_equals($selection->decisionPublicId, $decisionPublicId)
                || ! hash_equals($selection->sourceQuotePublicId, $quotePublicId)
                || $selection->methodCode !== self::METHOD_CODE) {
                throw new AuthorizationException('Telegram wallet payment selection is unavailable.');
            }

            try {
                $cash = $this->cashAccounts->forSelf($subjectUserId, $actorUserId);
                $intent = $this->walletPayments->reserve(
                    'telegram-wallet-reserve:'.$operationKey,
                    $subjectUserId,
                    $cash->accountId,
                    $quotePublicId,
                    $decisionPublicId,
                    $this->correlationId('reserve', $operationKey),
                );
            } catch (DomainException $exception) {
                throw new TelegramCustomerPurchaseWalletUnavailable(
                    'Telegram wallet payment is unavailable.',
                    previous: $exception,
                );
            }
            if ($intent->userId !== $subjectUserId
                || ! hash_equals($intent->sourceQuotePublicId, $quotePublicId)
                || ! hash_equals($intent->eligibilityDecisionPublicId, $decisionPublicId)
                || $intent->methodCode !== self::METHOD_CODE
                || $intent->state !== PaymentIntentState::AwaitingUserAction
                || $intent->amount->currency() !== 'IRR'
                || $intent->amount->amount() !== $order->amountIrr) {
                throw new RuntimeException('Telegram wallet reservation does not match current checkout authority.');
            }
            $after = $this->cashAccounts->forSelf($subjectUserId, $actorUserId);

            return new TelegramCustomerPurchaseWalletReservation(
                $intent->intentPublicId,
                $orderPublicId,
                $quotePublicId,
                $decisionPublicId,
                $intent->amount->amount(),
                $after->availableBalanceIrr,
                $intent->replayed,
            );
        }, 3);
    }

    /** @requirement BUY-001 BUY-003 PAY-002 PAY-003 PRO-001 WAL-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
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
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertOperationKey($operationKey);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
            $paymentIntentPublicId,
            $operationKey,
        ): TelegramCustomerPurchaseWalletPaid {
            $stored = $this->storedIntent(
                $connection,
                $paymentIntentPublicId,
                $subjectUserId,
                $quotePublicId,
                $decisionPublicId,
                true,
            );
            if (in_array($stored->state, [
                PaymentIntentState::Failed->value,
                PaymentIntentState::Expired->value,
                PaymentIntentState::Canceled->value,
            ], true)) {
                throw new TelegramCustomerPurchaseWalletUnavailable('Telegram wallet payment is no longer confirmable.');
            }
            if ($stored->state !== PaymentIntentState::Captured->value) {
                $this->orders->currentForSelf(
                    $actorUserId,
                    $subjectUserId,
                    $orderPublicId,
                    $quotePublicId,
                    $quoteConfigurationHash,
                );
                $selection = $this->paymentMethods->selectForSelf(
                    $actorUserId,
                    $subjectUserId,
                    $quotePublicId,
                    $quoteConfigurationHash,
                    $decisionPublicId,
                    $decisionConfigurationHash,
                    self::METHOD_CODE,
                );
                if ($selection->methodCode !== self::METHOD_CODE) {
                    throw new AuthorizationException('Telegram wallet capture selection is unavailable.');
                }
            }

            $order = $this->walletPayments->capture(
                $paymentIntentPublicId,
                $this->correlationId('capture', $operationKey),
            );
            if (! hash_equals($order->orderPublicId, $orderPublicId)
                || ! hash_equals($order->paymentIntentPublicId, $paymentIntentPublicId)
                || ! hash_equals($order->sourceQuotePublicId, $quotePublicId)
                || $order->userId !== $subjectUserId
                || $order->state !== OrderState::Paid
                || $order->commercialAmount->currency() !== 'IRR'
                || $order->settledAmount->currency() !== 'IRR'
                || $order->commercialAmount->amount() !== $order->settledAmount->amount()) {
                throw new RuntimeException('Telegram wallet capture does not match current checkout authority.');
            }

            return new TelegramCustomerPurchaseWalletPaid(
                $paymentIntentPublicId,
                $orderPublicId,
                $order->purchaseSettlementPublicId,
                $quotePublicId,
                $order->settledAmount->amount(),
                $order->replayed,
            );
        }, 3);
    }

    /** @requirement BUY-001 PAY-002 WAL-002 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function cancelForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $decisionPublicId,
        string $paymentIntentPublicId,
        string $operationKey,
    ): void {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertOperationKey($operationKey);

        $this->database->connection()->transaction(function (Connection $connection) use (
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $decisionPublicId,
            $paymentIntentPublicId,
            $operationKey,
        ): void {
            $this->storedIntent(
                $connection,
                $paymentIntentPublicId,
                $subjectUserId,
                $quotePublicId,
                $decisionPublicId,
                true,
                $orderPublicId,
            );
            $this->walletPayments->cancel(
                $paymentIntentPublicId,
                $this->correlationId('cancel', $operationKey),
            );
        }, 3);
    }

    /** @return object{state:string,amount_irr:int|string} */
    private function storedIntent(
        Connection $connection,
        string $paymentIntentPublicId,
        int $subjectUserId,
        string $quotePublicId,
        string $decisionPublicId,
        bool $lock,
        ?string $orderPublicId = null,
    ): object {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $paymentIntentPublicId) !== 1) {
            throw new AuthorizationException('Telegram wallet payment intent is unavailable.');
        }
        $query = $connection->table('payment_intents as intent')
            ->join('quotes as quote', 'quote.id', '=', 'intent.source_quote_id')
            ->join('payment_method_eligibility_decisions as decision', 'decision.id', '=', 'intent.payment_eligibility_decision_id')
            ->where('intent.public_id', strtoupper($paymentIntentPublicId));
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{state:string,amount_irr:int|string,user_id:int|string,source_quote_public_id:string|null,payment_eligibility_decision_public_id:string|null,payment_method_code:string|null,provider_code:string,purpose:string,quote_id:int|string}|null $row */
        $row = $query->first([
            'intent.state', 'intent.amount_irr', 'intent.user_id', 'intent.source_quote_public_id',
            'intent.payment_eligibility_decision_public_id', 'intent.payment_method_code', 'intent.provider_code',
            'intent.purpose', 'quote.id as quote_id',
        ]);
        if ($row === null
            || $row->purpose !== 'purchase'
            || (int) $row->user_id !== $subjectUserId
            || $row->source_quote_public_id === null
            || ! hash_equals($row->source_quote_public_id, $quotePublicId)
            || $row->payment_eligibility_decision_public_id === null
            || ! hash_equals($row->payment_eligibility_decision_public_id, $decisionPublicId)
            || $row->payment_method_code !== self::METHOD_CODE
            || $row->provider_code !== self::METHOD_CODE) {
            throw new AuthorizationException('Telegram wallet payment intent is unavailable.');
        }
        if ($orderPublicId !== null) {
            $storedOrder = $connection->table('orders')
                ->where('public_id', $orderPublicId)
                ->where('user_id', $subjectUserId)
                ->where('source_quote_id', (int) $row->quote_id)
                ->lockForUpdate()
                ->value('public_id');
            if (! is_string($storedOrder) || ! hash_equals($storedOrder, $orderPublicId)) {
                throw new AuthorizationException('Telegram wallet payment Order is unavailable.');
            }
        }

        return $row;
    }

    private function assertSelf(int $actorUserId, int $subjectUserId): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram wallet payment self access denied.');
        }
    }

    private function assertOperationKey(string $operationKey): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Telegram wallet payment operation identity is invalid.');
        }
    }

    private function correlationId(string $operation, string $operationKey): string
    {
        return 'tg-wallet-'.$operation.':'.substr($operationKey, 0, 40);
    }
}

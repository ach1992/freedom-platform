<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Orders\Domain\OrderState;
use App\Modules\Payments\CardToCard\Application\CardToCardPaymentService;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardPayment;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardReservation;
use App\Shared\Application\RestrictedValue;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramCustomerPurchaseCardToCardPaymentService implements TelegramCustomerPurchaseCardToCardPayment
{
    private const METHOD_CODE = 'card_to_card';

    public function __construct(
        private DatabaseManager $database,
        private TelegramCustomerPurchaseOrder $orders,
        private TelegramCustomerPurchasePaymentMethods $paymentMethods,
        private CardToCardPaymentService $payments,
        private StringEncrypter $encrypter,
    ) {}

    /** @requirement BUY-003 PAY-001 PAY-002 PRO-001 C2C-001 C2C-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
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
        ): TelegramCustomerPurchaseCardToCardReservation {
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
                throw new AuthorizationException('Telegram card-to-card payment selection is unavailable.');
            }

            try {
                $reservation = $this->payments->create(
                    $this->creationKey($orderPublicId),
                    $subjectUserId,
                    $quotePublicId,
                    $decisionPublicId,
                    $this->correlationId($operationKey),
                );
            } catch (DomainException $exception) {
                throw new AuthorizationException('Telegram card-to-card payment is unavailable.', previous: $exception);
            }

            $intent = $reservation->paymentIntent;
            if ($intent->userId !== $subjectUserId
                || ! hash_equals($intent->sourceQuotePublicId, $quotePublicId)
                || ! hash_equals($intent->eligibilityDecisionPublicId, $decisionPublicId)
                || $intent->methodCode !== self::METHOD_CODE
                || $intent->state !== PaymentIntentState::AwaitingUserAction
                || $intent->amount->currency() !== 'IRR'
                || $intent->amount->amount() !== $order->amountIrr
                || $reservation->payableAmountIrr < $intent->amount->amount()) {
                throw new RuntimeException('Telegram card-to-card reservation does not match current checkout authority.');
            }

            return new TelegramCustomerPurchaseCardToCardReservation(
                $intent->intentPublicId,
                $reservation->reservationPublicId,
                $orderPublicId,
                $quotePublicId,
                $decisionPublicId,
                $reservation->payableAmountIrr,
                $reservation->maskedCardNumber,
                $reservation->expiresAt,
                $reservation->lateReviewUntil,
                $intent->replayed || $reservation->replayed,
            );
        }, 3);
    }

    /** @requirement C2C-001 DAT-002 DAT-003 SEC-002 SEC-008 QUA-001 */
    public function destinationPanForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $reservationPublicId,
    ): RestrictedValue {
        $this->assertSelf($actorUserId, $subjectUserId);
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $reservationPublicId) !== 1) {
            throw new AuthorizationException('Telegram card-to-card destination is unavailable.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($subjectUserId, $reservationPublicId): RestrictedValue {
            /** @var object{encrypted_card_number:string,user_id:int|string,purpose:string,payment_method_code:string|null,provider_code:string,state:string,active_lock:int|string|null,expires_at:string,order_state:string|null}|null $row */
            $row = $connection->table('c2c_amount_reservations as reservation')
                ->join('c2c_destination_accounts as destination', 'destination.id', '=', 'reservation.c2c_destination_account_id')
                ->join('payment_intents as intent', 'intent.id', '=', 'reservation.payment_intent_id')
                ->leftJoin('orders as purchase_order', function ($join): void {
                    $join->on('purchase_order.source_quote_id', '=', 'intent.source_quote_id')
                        ->on('purchase_order.user_id', '=', 'intent.user_id');
                })
                ->where('reservation.public_id', strtoupper($reservationPublicId))
                ->lockForUpdate()
                ->first([
                    'destination.encrypted_card_number',
                    'intent.user_id', 'intent.purpose', 'intent.payment_method_code', 'intent.provider_code', 'intent.state',
                    'reservation.active_lock', 'reservation.expires_at', 'purchase_order.state as order_state',
                ]);

            $now = $connection->selectOne('SELECT CURRENT_TIMESTAMP(6) AS now_at');
            $nowAt = is_object($now) && is_string($now->now_at ?? null) ? $now->now_at : null;
            if ($row === null
                || $nowAt === null
                || (int) $row->user_id !== $subjectUserId
                || $row->purpose !== 'purchase'
                || $row->payment_method_code !== self::METHOD_CODE
                || $row->provider_code !== self::METHOD_CODE
                || $row->state !== PaymentIntentState::AwaitingUserAction->value
                || (int) ($row->active_lock ?? 0) !== 1
                || (string) $row->expires_at <= $nowAt
                || $row->order_state !== OrderState::AwaitingPayment->value) {
                throw new AuthorizationException('Telegram card-to-card destination is unavailable.');
            }

            $pan = $this->encrypter->decryptString($row->encrypted_card_number);
            if (preg_match('/\A[0-9]{16,19}\z/', $pan) !== 1) {
                throw new RuntimeException('Stored card-to-card destination payment data is invalid.');
            }

            return RestrictedValue::fromString($pan);
        }, 3);
    }

    private function creationKey(string $orderPublicId): string
    {
        return 'telegram-c2c-order:'.hash('sha256', strtoupper($orderPublicId));
    }

    private function correlationId(string $operationKey): string
    {
        return 'tg-c2c-reserve:'.substr($operationKey, 0, 40);
    }

    private function assertSelf(int $actorUserId, int $subjectUserId): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram card-to-card payment self access denied.');
        }
    }

    private function assertOperationKey(string $operationKey): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Telegram card-to-card payment operation identity is invalid.');
        }
    }
}

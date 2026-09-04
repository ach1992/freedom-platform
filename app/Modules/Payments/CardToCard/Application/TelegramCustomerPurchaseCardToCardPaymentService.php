<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\PurchaseOrderSettlementAvailability;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardPayment;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardReceiptSubmission;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardDestination;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardReservation;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardSubmission;
use App\Shared\Application\Clock;
use App\Shared\Application\RestrictedValue;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramCustomerPurchaseCardToCardPaymentService implements TelegramCustomerPurchaseCardToCardPayment, TelegramCustomerPurchaseCardToCardReceiptSubmission
{
    private const METHOD_CODE = 'card_to_card';

    public function __construct(
        private DatabaseManager $database,
        private TelegramCustomerPurchaseOrder $orders,
        private TelegramCustomerPurchasePaymentMethods $paymentMethods,
        private PurchaseOrderService $purchaseOrders,
        private CardToCardPaymentService $cardToCardPayments,
        private CardToCardManualSubmissionService $manualSubmissions,
        private StringEncrypter $encrypter,
        private Clock $clock,
    ) {}

    /** @requirement BUY-001 BUY-003 PAY-001 PAY-002 PRO-001 C2C-001 C2C-004 DAT-002 DAT-003 SEC-002 QUA-001 */
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

            $receipt = $this->cardToCardPayments->create(
                'telegram-c2c-order:'.$orderPublicId,
                $subjectUserId,
                $quotePublicId,
                $decisionPublicId,
                'tg-c2c-reserve:'.substr($operationKey, 0, 40),
            );
            $intent = $receipt->paymentIntent;
            if ($intent->userId !== $subjectUserId
                || ! hash_equals($intent->sourceQuotePublicId, $quotePublicId)
                || ! hash_equals($intent->eligibilityDecisionPublicId, $decisionPublicId)
                || $intent->methodCode !== self::METHOD_CODE
                || $intent->state !== PaymentIntentState::AwaitingUserAction
                || $intent->amount->currency() !== 'IRR'
                || $intent->amount->amount() !== $order->amountIrr
                || $receipt->baseAmountIrr !== $order->amountIrr
                || $receipt->payableAmountIrr !== $receipt->baseAmountIrr + $receipt->adjustmentAmountIrr) {
                throw new RuntimeException('Telegram card-to-card reservation does not match current checkout authority.');
            }

            return new TelegramCustomerPurchaseCardToCardReservation(
                $intent->intentPublicId,
                $receipt->reservationPublicId,
                $orderPublicId,
                $quotePublicId,
                $decisionPublicId,
                $receipt->baseAmountIrr,
                $receipt->adjustmentAmountIrr,
                $receipt->payableAmountIrr,
                $receipt->maskedCardNumber,
                $receipt->expiresAt,
                $receipt->replayed || $intent->replayed,
            );
        }, 3);
    }

    /** @requirement BUY-001 PAY-002 C2C-001 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function destinationForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $reservationPublicId,
    ): TelegramCustomerPurchaseCardToCardDestination {
        $this->assertSelf($actorUserId, $subjectUserId);
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $reservationPublicId) !== 1) {
            throw new AuthorizationException('Telegram card-to-card destination is unavailable.');
        }

        /** @var object{quote_public_id:string,source_quote_configuration_hash:string,state:string,user_id:int|string,purpose:string,payment_method_code:string|null,provider_code:string,payable_amount_irr:int|string,expires_at:string,masked_card_number:string,encrypted_card_number:string}|null $row */
        $row = $this->database->connection()
            ->table('c2c_amount_reservations as reservation')
            ->join('payment_intents as intent', 'intent.id', '=', 'reservation.payment_intent_id')
            ->join('c2c_destination_accounts as destination', 'destination.id', '=', 'reservation.c2c_destination_account_id')
            ->where('reservation.public_id', strtoupper($reservationPublicId))
            ->first([
                'intent.source_quote_public_id as quote_public_id',
                'intent.source_quote_configuration_hash',
                'intent.state',
                'intent.user_id',
                'intent.purpose',
                'intent.payment_method_code',
                'intent.provider_code',
                'reservation.payable_amount_irr',
                'reservation.expires_at',
                'destination.masked_card_number',
                'destination.encrypted_card_number',
            ]);
        if ($row === null
            || (int) $row->user_id !== $subjectUserId
            || $row->purpose !== 'purchase'
            || $row->payment_method_code !== self::METHOD_CODE
            || $row->provider_code !== self::METHOD_CODE
            || $row->state !== PaymentIntentState::AwaitingUserAction->value
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $row->quote_public_id) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $row->source_quote_configuration_hash) !== 1) {
            throw new AuthorizationException('Telegram card-to-card destination is unavailable.');
        }

        if ($this->purchaseOrders->settlementAvailabilityFromQuote($row->quote_public_id, $subjectUserId)
            !== PurchaseOrderSettlementAvailability::AwaitingPayment) {
            throw new AuthorizationException('Telegram card-to-card destination is no longer payable.');
        }

        $expiresAt = $this->storedDateTime($row->expires_at);
        if ($expiresAt <= $this->clock->now()->setTimezone(new DateTimeZone('UTC'))) {
            throw new AuthorizationException('Telegram card-to-card destination reservation has expired.');
        }

        $cardNumber = $this->encrypter->decryptString($row->encrypted_card_number);
        if (preg_match('/\A[0-9]{16}\z/', $cardNumber) !== 1
            || ! hash_equals(
                substr($cardNumber, 0, 6).'******'.substr($cardNumber, -4),
                $row->masked_card_number,
            )) {
            throw new RuntimeException('Stored card-to-card destination identity is invalid.');
        }

        return new TelegramCustomerPurchaseCardToCardDestination(
            strtoupper($reservationPublicId),
            RestrictedValue::fromString($cardNumber),
            $row->masked_card_number,
            $this->positiveInt($row->payable_amount_irr, 'Card-to-card payable amount'),
            $expiresAt,
        );
    }

    /** @requirement BUY-003 PAY-002 PAY-003 C2C-002 C2C-004 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function submitReceiptForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $reservationPublicId,
        DateTimeImmutable $submittedAt,
        string $evidenceHash,
        string $privateReceiptReference,
        string $operationKey,
    ): TelegramCustomerPurchaseCardToCardSubmission {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertOperationKey($operationKey);
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $reservationPublicId) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $evidenceHash) !== 1
            || preg_match('/\Atelegram-private-media:[0-9A-HJKMNP-TV-Z]{26}\z/i', $privateReceiptReference) !== 1) {
            throw new AuthorizationException('Telegram card-to-card receipt submission is unavailable.');
        }
        $normalizedReservationPublicId = strtoupper($reservationPublicId);
        $normalizedPrivateReference = 'telegram-private-media:'.strtoupper(substr($privateReceiptReference, 23));

        /** @var object{payment_intent_public_id:string,quote_public_id:string,state:string,user_id:int|string,purpose:string,payment_method_code:string|null,provider_code:string,captured_at:?string,payable_amount_irr:int|string}|null $row */
        $row = $this->database->connection()
            ->table('c2c_amount_reservations as reservation')
            ->join('payment_intents as intent', 'intent.id', '=', 'reservation.payment_intent_id')
            ->where('reservation.public_id', $normalizedReservationPublicId)
            ->first([
                'intent.public_id as payment_intent_public_id',
                'intent.source_quote_public_id as quote_public_id',
                'intent.state',
                'intent.user_id',
                'intent.purpose',
                'intent.payment_method_code',
                'intent.provider_code',
                'intent.captured_at',
                'reservation.payable_amount_irr',
            ]);
        if ($row === null
            || (int) $row->user_id !== $subjectUserId
            || $row->purpose !== 'purchase'
            || $row->payment_method_code !== self::METHOD_CODE
            || $row->provider_code !== self::METHOD_CODE
            || $row->captured_at !== null
            || ! in_array($row->state, [
                PaymentIntentState::AwaitingUserAction->value,
                PaymentIntentState::Submitted->value,
            ], true)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $row->payment_intent_public_id) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $row->quote_public_id) !== 1) {
            throw new AuthorizationException('Telegram card-to-card receipt submission is unavailable.');
        }

        if ($row->state === PaymentIntentState::AwaitingUserAction->value
            && $this->purchaseOrders->settlementAvailabilityFromQuote($row->quote_public_id, $subjectUserId)
                !== PurchaseOrderSettlementAvailability::AwaitingPayment) {
            throw new AuthorizationException('Telegram card-to-card receipt submission is no longer payable.');
        }

        try {
            $submission = $this->manualSubmissions->submit(
                'telegram-c2c-receipt:'.$operationKey,
                $subjectUserId,
                $normalizedReservationPublicId,
                $this->positiveInt($row->payable_amount_irr, 'Card-to-card receipt payable amount'),
                $submittedAt,
                $evidenceHash,
                privateReceiptReference: $normalizedPrivateReference,
                correlationId: 'tg-c2c-receipt:'.substr($operationKey, 0, 40),
            );
        } catch (DomainException $exception) {
            throw new AuthorizationException('Telegram card-to-card receipt submission was rejected by payment authority.', previous: $exception);
        }

        if (! hash_equals(strtoupper($submission->reservationPublicId), $normalizedReservationPublicId)
            || ! hash_equals(strtoupper($submission->paymentIntentPublicId), strtoupper($row->payment_intent_public_id))
            || $submission->claimedAmountIrr !== (int) $row->payable_amount_irr) {
            throw new RuntimeException('Telegram card-to-card receipt result conflicts with current payment authority.');
        }

        return new TelegramCustomerPurchaseCardToCardSubmission(
            strtoupper($submission->submissionPublicId),
            strtoupper($submission->paymentIntentPublicId),
            strtoupper($submission->reservationPublicId),
            $submission->claimedAmountIrr,
            $submission->replayed,
        );
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

    private function positiveInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($integer === false) {
            throw new RuntimeException($label.' must be a positive integer.');
        }

        return $integer;
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new RuntimeException('Stored card-to-card timestamp is invalid.');
        }

        return $date;
    }
}

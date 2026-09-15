<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\PurchaseOrderSettlementAvailability;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardReceiptSubmission;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardSubmission;
use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramCustomerPurchaseCardToCardReceiptSubmissionService implements TelegramCustomerPurchaseCardToCardReceiptSubmission
{
    private const METHOD_CODE = 'card_to_card';

    public function __construct(
        private DatabaseManager $database,
        private PurchaseOrderService $purchaseOrders,
        private CardToCardManualSubmissionService $manualSubmissions,
    ) {}

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
        $normalizedPrivateReference = 'telegram-private-media:'.strtoupper(substr($privateReceiptReference, strlen('telegram-private-media:')));

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
            strtoupper($submission->publicId),
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
}

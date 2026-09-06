<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardReceiptSubmission;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class TelegramPrivateMediaInteractionGateway
{
    private const C2C_INSTRUCTIONS_STATE = 'purchase_card_to_card_instructions';

    private const C2C_SUBMITTING_STATE = 'purchase_card_to_card_receipt_submitting';

    private const C2C_SUBMITTED_STATE = 'purchase_card_to_card_submitted';

    public function __construct(
        private DatabaseManager $database,
        private TelegramInteractionSessionService $sessions,
        private TelegramPrivateMediaIngestor $media,
        private TelegramCustomerPurchaseCardToCardReceiptSubmission $cardToCardSubmissions,
        private CustomerAccountSummaryService $customers,
        private TelegramCardToCardReceiptStatusDelivery $statusDelivery,
    ) {}

    /** @requirement BUY-003 PAY-002 PAY-003 C2C-002 C2C-004 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-009 QUA-001 QUA-004 */
    public function handle(TelegramPrivateMediaInteraction $interaction): bool
    {
        if ($interaction->flow !== TelegramNavigationEntryGateway::FLOW
            || $interaction->sessionState !== self::C2C_INSTRUCTIONS_STATE) {
            return false;
        }

        $state = $this->c2cState($interaction->sessionPayload);
        $locale = $this->localeForActor($interaction->userId);

        try {
            $media = $this->media->ingest(
                $interaction->botId,
                $interaction->updateId,
                $interaction->telegramAccountId,
                $interaction->userId,
                $interaction->media,
            );
        } catch (TelegramPrivateMediaRejected $exception) {
            $status = $exception->reasonCode === 'discarded_unassociated' ? 'unavailable' : 'invalid';
            $this->statusDelivery->queue($interaction->telegramUserId, $interaction->requestKey, $locale, $status);

            return true;
        }

        $operationKey = hash('sha256', $interaction->requestKey);
        try {
            $submission = $this->database->connection()->transaction(function () use (
                $interaction,
                $state,
                $media,
                $operationKey,
                $locale,
            ): TelegramCustomerPurchaseCardToCardSubmission {
                $claim = $this->sessions->transition(
                    $interaction->sessionPublicId,
                    $interaction->sessionVersion,
                    self::C2C_SUBMITTING_STATE,
                    $interaction->sessionPayload,
                    'telegram-c2c-receipt-claim:'.$operationKey,
                );
                $this->assertActorBinding($interaction, $claim->userId);

                $submission = $this->cardToCardSubmissions->submitReceiptForSelf(
                    $interaction->userId,
                    $interaction->userId,
                    $state['c2c_reservation_public_id'],
                    $interaction->messageAt,
                    $media->contentSha256,
                    $media->privateReference,
                    $operationKey,
                );
                if (! hash_equals($submission->reservationPublicId, $state['c2c_reservation_public_id'])
                    || ! hash_equals($submission->paymentIntentPublicId, $state['payment_intent_public_id'])) {
                    throw new RuntimeException('Telegram card-to-card receipt submission does not match the active checkout session.');
                }

                $this->media->associate(
                    $media,
                    $interaction->userId,
                    'c2c_manual_submission',
                    $submission->submissionPublicId,
                );

                $submitted = $this->sessions->transition(
                    $interaction->sessionPublicId,
                    $claim->version,
                    self::C2C_SUBMITTED_STATE,
                    $state,
                    'telegram-c2c-receipt-submitted:'.$operationKey,
                );
                $this->assertActorBinding($interaction, $submitted->userId);
                $this->statusDelivery->queue(
                    $interaction->telegramUserId,
                    $interaction->requestKey,
                    $locale,
                    'received',
                );

                return $submission;
            }, 3);
        } catch (AuthorizationException|DomainException) {
            $this->media->discardIfUnassociated($media, $interaction->userId);
            $this->statusDelivery->queue($interaction->telegramUserId, $interaction->requestKey, $locale, 'unavailable');

            return true;
        }

        if (! Str::isUlid($submission->submissionPublicId)) {
            throw new RuntimeException('Telegram card-to-card receipt submission identity is invalid.');
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{payment_intent_public_id:string,c2c_reservation_public_id:string}
     */
    private function c2cState(array $payload): array
    {
        $method = $payload['payment_method_code'] ?? null;
        $paymentIntentPublicId = $payload['payment_intent_public_id'] ?? null;
        $reservationPublicId = $payload['c2c_reservation_public_id'] ?? null;
        if ($method !== 'card_to_card'
            || ! is_string($paymentIntentPublicId)
            || ! Str::isUlid($paymentIntentPublicId)
            || ! is_string($reservationPublicId)
            || ! Str::isUlid($reservationPublicId)
            || array_key_exists('card_number', $payload)
            || array_key_exists('private_receipt_reference', $payload)
            || array_key_exists('evidence_hash', $payload)) {
            throw new RuntimeException('Telegram card-to-card receipt session state is invalid.');
        }

        return [
            'payment_intent_public_id' => strtoupper($paymentIntentPublicId),
            'c2c_reservation_public_id' => strtoupper($reservationPublicId),
        ];
    }

    private function assertActorBinding(TelegramPrivateMediaInteraction $interaction, int $sessionUserId): void
    {
        if ($sessionUserId !== $interaction->userId) {
            throw new AuthorizationException('Telegram private-media session actor changed.');
        }
    }

    private function localeForActor(int $userId): string
    {
        $customer = $this->customers->forSelf($userId, $userId);

        return $customer->locale === 'en' ? 'en' : 'fa';
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Support\Application\SupportTicketAttachmentReceipt;
use App\Modules\Support\Application\SupportTicketAttachmentService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardReceiptSubmission;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseGiftCardPayment;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseUsdtPayment;
use App\Modules\Telegram\Application\Contracts\TelegramSupportCustomerRateLimiter;
use App\Shared\Application\RestrictedValue;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class TelegramPrivateMediaInteractionGateway
{
    private const ADMIN_DIRECT_MESSAGE_COMPOSE_STATE = 'admin_customer_message_compose';

    private const C2C_INSTRUCTIONS_STATE = 'purchase_card_to_card_instructions';

    private const C2C_SUBMITTING_STATE = 'purchase_card_to_card_receipt_submitting';

    private const C2C_SUBMITTED_STATE = 'purchase_card_to_card_submitted';

    private const GIFT_CARD_EVIDENCE_INPUT_STATE = 'purchase_gift_card_evidence_input';

    private const GIFT_CARD_SUBMITTING_STATE = 'purchase_gift_card_submitting';

    private const GIFT_CARD_SUBMITTED_STATE = 'purchase_gift_card_submitted';

    private const USDT_TXID_INPUT_STATE = 'purchase_usdt_txid_input';

    private const USDT_SUBMITTING_STATE = 'purchase_usdt_submitting';

    private const USDT_SUBMITTED_STATE = 'purchase_usdt_submitted';

    private const SUPPORT_REPLY_STATE = 'support_reply';

    private const SUPPORT_QUEUE_REPLY_STATE = 'support_queue_reply';

    private const SUPPORT_MUTATING_STATE = 'support_mutating';

    private const SUPPORT_TICKET_STATE = 'support_ticket';

    private const SUPPORT_QUEUE_TICKET_STATE = 'support_queue_ticket';

    public function __construct(
        private DatabaseManager $database,
        private TelegramInteractionSessionService $sessions,
        private TelegramPrivateMediaIngestor $media,
        private TelegramAdminCustomerNavigationHandler $adminCustomers,
        private TelegramCustomerPurchaseCardToCardReceiptSubmission $cardToCardSubmissions,
        private TelegramCustomerPurchaseGiftCardPayment $giftCards,
        private TelegramCustomerPurchaseUsdtPayment $usdt,
        private CustomerAccountSummaryService $customers,
        private TelegramPaymentPrivateEvidenceStatusDelivery $paymentEvidenceStatus,
        private TelegramCardToCardReceiptStatusDelivery $statusDelivery,
        private SupportTicketAttachmentService $supportAttachments,
        private TelegramSupportMembershipFreshnessGuard $supportMembership,
        private TelegramSupportCustomerRateLimiter $supportRateLimiter,
        private TelegramSupportAttachmentStatusDelivery $supportAttachmentStatus,
    ) {}

    /** @requirement COM-001 BUY-003 PAY-002 PAY-003 C2C-002 C2C-004 SUP-001 SUP-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-009 QUA-001 QUA-004 */
    public function handle(TelegramPrivateMediaInteraction $interaction): bool
    {
        if ($interaction->flow !== TelegramNavigationEntryGateway::FLOW) {
            return false;
        }

        if ($interaction->sessionState === self::ADMIN_DIRECT_MESSAGE_COMPOSE_STATE) {
            return $this->adminCustomers->handlePrivateMedia($interaction);
        }
        if ($interaction->sessionState === self::C2C_INSTRUCTIONS_STATE) {
            return $interaction->caption === null && $this->handleCardToCard($interaction);
        }
        if ($interaction->sessionState === self::GIFT_CARD_EVIDENCE_INPUT_STATE) {
            return $this->handleGiftCardEvidence($interaction);
        }
        if ($interaction->sessionState === self::USDT_TXID_INPUT_STATE) {
            return $this->handleUsdtEvidence($interaction);
        }
        if ($interaction->sessionState === self::SUPPORT_REPLY_STATE) {
            return $interaction->caption === null && $this->handleSupportAttachment($interaction, false);
        }
        if ($interaction->sessionState === self::SUPPORT_QUEUE_REPLY_STATE) {
            return $interaction->caption === null && $this->handleSupportAttachment($interaction, true);
        }

        return false;
    }

    private function handleCardToCard(TelegramPrivateMediaInteraction $interaction): bool
    {
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

        if (! TelegramPrivateMediaContentValidator::isImageMime($media->detectedMime)) {
            $this->media->discardIfUnassociated($media, $interaction->userId);
            $this->statusDelivery->queue($interaction->telegramUserId, $interaction->requestKey, $locale, 'invalid');

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

    private function handleGiftCardEvidence(TelegramPrivateMediaInteraction $interaction): bool
    {
        $state = $this->giftCardEvidenceState($interaction->sessionPayload);
        $locale = $this->localeForActor($interaction->userId);
        $mode = $state['gift_card_submission_mode'];
        $caption = $interaction->caption === null ? null : trim($interaction->caption);

        if ($mode === 'code_only'
            || ($mode === 'image_only' && $caption !== null && $caption !== '')
            || ($mode === 'both' && ($caption === null || $caption === ''))) {
            $this->queuePaymentEvidenceStatus($interaction, $locale, 'invalid');

            return true;
        }

        $code = in_array($mode, ['either', 'both'], true) && $caption !== null && $caption !== ''
            ? $caption
            : null;

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
            $this->queuePaymentEvidenceStatus($interaction, $locale, $status);

            return true;
        }

        if (! TelegramPrivateMediaContentValidator::isImageMime($media->detectedMime)) {
            $this->media->discardIfUnassociated($media, $interaction->userId);
            $this->queuePaymentEvidenceStatus($interaction, $locale, 'invalid');

            return true;
        }

        $operationKey = hash('sha256', $interaction->requestKey);
        try {
            $submission = $this->database->connection()->transaction(function () use (
                $interaction,
                $state,
                $code,
                $media,
                $operationKey,
                $locale,
            ): TelegramCustomerPurchaseGiftCardSubmission {
                $claim = $this->sessions->transition(
                    $interaction->sessionPublicId,
                    $interaction->sessionVersion,
                    self::GIFT_CARD_SUBMITTING_STATE,
                    $interaction->sessionPayload,
                    'telegram-gift-card-evidence-claim:'.$operationKey,
                );
                $this->assertActorBinding($interaction, $claim->userId);

                $submission = $this->giftCards->submitEvidenceForSelf(
                    $interaction->userId,
                    $interaction->userId,
                    $state['order_public_id'],
                    $state['quote_public_id'],
                    $state['quote_configuration_hash'],
                    $state['payment_decision_public_id'],
                    $state['payment_decision_configuration_hash'],
                    $state['gift_card_type_code'],
                    $state['gift_card_type_configuration_hash'],
                    $state['gift_card_claimed_face_value'],
                    $code,
                    $media->privateReference,
                    null,
                    null,
                    $media->contentSha256,
                    $operationKey,
                );

                $this->media->associate(
                    $media,
                    $interaction->userId,
                    'gift_card_submission',
                    $submission->submissionPublicId,
                );

                $safeState = [
                    'submission_public_id' => $submission->submissionPublicId,
                    'payment_intent_public_id' => $submission->paymentIntentPublicId,
                    'gift_card_type_code' => $submission->typeCode,
                    'claimed_face_value' => $submission->claimedFaceValue,
                    'claimed_currency' => $submission->claimedCurrency,
                    'state' => $submission->state,
                ];
                if ($submission->reviewPublicId !== null) {
                    $safeState['review_public_id'] = $submission->reviewPublicId;
                }
                if ($submission->maskedCode !== '') {
                    $safeState['masked_code'] = $submission->maskedCode;
                }

                $submitted = $this->sessions->transition(
                    $interaction->sessionPublicId,
                    $claim->version,
                    self::GIFT_CARD_SUBMITTED_STATE,
                    $safeState,
                    'telegram-gift-card-evidence-submitted:'.$operationKey,
                );
                $this->assertActorBinding($interaction, $submitted->userId);
                $this->queuePaymentEvidenceStatus($interaction, $locale, 'received');

                return $submission;
            }, 3);
        } catch (AuthorizationException|DomainException) {
            $this->media->discardIfUnassociated($media, $interaction->userId);
            $this->queuePaymentEvidenceStatus($interaction, $locale, 'unavailable');

            return true;
        }

        if (! Str::isUlid($submission->submissionPublicId)) {
            throw new RuntimeException('Telegram Gift Card private-evidence submission identity is invalid.');
        }

        return true;
    }

    private function handleUsdtEvidence(TelegramPrivateMediaInteraction $interaction): bool
    {
        $state = $this->usdtEvidenceState($interaction->sessionPayload);
        $locale = $this->localeForActor($interaction->userId);
        $txid = $interaction->caption === null ? '' : strtolower(trim($interaction->caption));
        if (preg_match('/\A0x[a-f0-9]{64}\z/', $txid) !== 1) {
            $this->queuePaymentEvidenceStatus($interaction, $locale, 'invalid');

            return true;
        }

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
            $this->queuePaymentEvidenceStatus($interaction, $locale, $status);

            return true;
        }

        if (! TelegramPrivateMediaContentValidator::isImageMime($media->detectedMime)) {
            $this->media->discardIfUnassociated($media, $interaction->userId);
            $this->queuePaymentEvidenceStatus($interaction, $locale, 'invalid');

            return true;
        }

        $operationKey = hash('sha256', $interaction->requestKey);
        try {
            $submission = $this->database->connection()->transaction(function () use (
                $interaction,
                $state,
                $txid,
                $media,
                $operationKey,
                $locale,
            ): TelegramCustomerPurchaseUsdtSubmission {
                $claim = $this->sessions->transition(
                    $interaction->sessionPublicId,
                    $interaction->sessionVersion,
                    self::USDT_SUBMITTING_STATE,
                    $interaction->sessionPayload,
                    'telegram-usdt-evidence-claim:'.$operationKey,
                );
                $this->assertActorBinding($interaction, $claim->userId);

                $submission = $this->usdt->submitTxidForSelf(
                    $interaction->userId,
                    $interaction->userId,
                    $state['order_public_id'],
                    $state['quote_public_id'],
                    $state['quote_configuration_hash'],
                    $state['payment_decision_public_id'],
                    $state['payment_decision_configuration_hash'],
                    $state['usdt_authority_public_id'],
                    $txid,
                    $operationKey,
                    $media->privateReference,
                    $media->contentSha256,
                );

                $this->media->associate(
                    $media,
                    $interaction->userId,
                    'usdt_txid_submission',
                    $submission->submissionPublicId,
                );

                $safeState = [
                    'submission_public_id' => $submission->submissionPublicId,
                    'authority_public_id' => $submission->authorityPublicId,
                    'payment_intent_public_id' => $submission->paymentIntentPublicId,
                    'masked_txid' => $this->maskTxid($submission->txid),
                    'state' => $submission->state,
                ];
                $submitted = $this->sessions->transition(
                    $interaction->sessionPublicId,
                    $claim->version,
                    self::USDT_SUBMITTED_STATE,
                    $safeState,
                    'telegram-usdt-evidence-submitted:'.$operationKey,
                );
                $this->assertActorBinding($interaction, $submitted->userId);
                $this->queuePaymentEvidenceStatus($interaction, $locale, 'received');

                return $submission;
            }, 3);
        } catch (AuthorizationException|DomainException) {
            $this->media->discardIfUnassociated($media, $interaction->userId);
            $this->queuePaymentEvidenceStatus($interaction, $locale, 'unavailable');

            return true;
        }

        if (! Str::isUlid($submission->submissionPublicId)) {
            throw new RuntimeException('Telegram USDT private-evidence submission identity is invalid.');
        }

        return true;
    }

    private function handleSupportAttachment(TelegramPrivateMediaInteraction $interaction, bool $staff): bool
    {
        $ticketId = $this->positivePayloadId($interaction->sessionPayload, 'ticket_id');
        $locale = $this->localeForActor($interaction->userId);

        try {
            $this->supportMembership->assertSupportView($interaction->userId);
        } catch (AuthorizationException) {
            $this->supportAttachmentStatus->queue(
                $interaction->telegramUserId,
                $interaction->requestKey,
                $locale,
                'unavailable',
            );

            return true;
        }

        if (! $staff) {
            $decision = $this->supportRateLimiter->consume(
                $interaction->userId,
                TelegramSupportCustomerRateLimitScope::CustomerContent,
            );
            if (! $decision->allowed) {
                if ($decision->retryAfterSeconds === null) {
                    throw new RuntimeException('Telegram Support rate-limit decision is incomplete.');
                }
                $this->supportAttachmentStatus->queue(
                    $interaction->telegramUserId,
                    $interaction->requestKey,
                    $locale,
                    'rate_limited',
                    null,
                    $decision->retryAfterSeconds,
                );

                return true;
            }
        }

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
            $this->supportAttachmentStatus->queue(
                $interaction->telegramUserId,
                $interaction->requestKey,
                $locale,
                $status,
            );

            return true;
        }

        try {
            $this->supportMembership->assertSupportView($interaction->userId);
        } catch (AuthorizationException) {
            $this->media->discardIfUnassociated($media, $interaction->userId);
            $this->supportAttachmentStatus->queue(
                $interaction->telegramUserId,
                $interaction->requestKey,
                $locale,
                'unavailable',
            );

            return true;
        }

        $operation = $staff ? 'support-attachment' : 'customer-attachment';
        $targetState = $staff ? self::SUPPORT_QUEUE_TICKET_STATE : self::SUPPORT_TICKET_STATE;
        $operationKey = hash('sha256', $interaction->requestKey);
        try {
            $attachment = $this->database->connection()->transaction(function () use (
                $interaction,
                $staff,
                $ticketId,
                $media,
                $operation,
                $targetState,
                $operationKey,
                $locale,
            ): SupportTicketAttachmentReceipt {
                $claim = $this->sessions->transition(
                    $interaction->sessionPublicId,
                    $interaction->sessionVersion,
                    self::SUPPORT_MUTATING_STATE,
                    ['operation' => $operation],
                    'tg-support-mutation-claim:'.hash('sha256', $interaction->requestKey.':'.$operation),
                );
                $this->assertActorBinding($interaction, $claim->userId);

                $kind = TelegramPrivateMediaContentValidator::kindForMime($media->detectedMime);
                $privateReference = RestrictedValue::fromString($media->privateReference);
                $idempotencyKey = 'telegram-support-attachment:'.$operationKey;
                $receipt = $staff
                    ? $this->supportAttachments->addForSupport(
                        $interaction->userId,
                        $ticketId,
                        $kind,
                        $media->detectedMime,
                        $media->byteSize,
                        $media->contentSha256,
                        $privateReference,
                        $idempotencyKey,
                    )
                    : $this->supportAttachments->addForCustomer(
                        $ticketId,
                        $interaction->userId,
                        $kind,
                        $media->detectedMime,
                        $media->byteSize,
                        $media->contentSha256,
                        $privateReference,
                        $idempotencyKey,
                    );

                $this->media->associate(
                    $media,
                    $interaction->userId,
                    'support_ticket_attachment',
                    $receipt->attachment->publicId,
                );

                $completed = $this->sessions->transition(
                    $interaction->sessionPublicId,
                    $claim->version,
                    $targetState,
                    ['ticket_id' => $ticketId],
                    'tg-support-mutation-complete:'.hash('sha256', $interaction->requestKey.':'.$operation),
                );
                $this->assertActorBinding($interaction, $completed->userId);
                $this->supportAttachmentStatus->queue(
                    $interaction->telegramUserId,
                    $interaction->requestKey,
                    $locale,
                    'received',
                    $receipt->attachment->publicId,
                );

                return $receipt;
            }, 3);
        } catch (AuthorizationException|DomainException) {
            $this->media->discardIfUnassociated($media, $interaction->userId);
            $this->supportAttachmentStatus->queue(
                $interaction->telegramUserId,
                $interaction->requestKey,
                $locale,
                'unavailable',
            );

            return true;
        }

        if (! Str::isUlid($attachment->attachment->publicId)) {
            throw new RuntimeException('Telegram Support attachment identity is invalid.');
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

    /**
     * @param  array<string,mixed>  $payload
     * @return array{order_public_id:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,gift_card_type_code:string,gift_card_type_configuration_hash:string,gift_card_claimed_face_value:int,gift_card_submission_mode:string}
     */
    private function giftCardEvidenceState(array $payload): array
    {
        foreach (['code', 'private_image_reference', 'image_content_hash', 'telegram_file_id', 'telegram_file_unique_id'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                throw new RuntimeException('Telegram Gift Card private evidence leaked into durable session state.');
            }
        }

        if (($payload['payment_method_code'] ?? null) !== 'gift_card'
            || ! is_string($payload['order_public_id'] ?? null)
            || ! Str::isUlid($payload['order_public_id'])
            || ! is_string($payload['quote_public_id'] ?? null)
            || ! Str::isUlid($payload['quote_public_id'])
            || ! is_string($payload['quote_configuration_hash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['quote_configuration_hash']) !== 1
            || ! is_string($payload['payment_decision_public_id'] ?? null)
            || ! Str::isUlid($payload['payment_decision_public_id'])
            || ! is_string($payload['payment_decision_configuration_hash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['payment_decision_configuration_hash']) !== 1
            || ! is_string($payload['gift_card_type_code'] ?? null)
            || preg_match('/\A[A-Za-z0-9:_.-]{2,64}\z/', $payload['gift_card_type_code']) !== 1
            || ! is_string($payload['gift_card_type_configuration_hash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['gift_card_type_configuration_hash']) !== 1
            || ! is_int($payload['gift_card_claimed_face_value'] ?? null)
            || $payload['gift_card_claimed_face_value'] < 1
            || ! is_string($payload['gift_card_submission_mode'] ?? null)
            || ! in_array($payload['gift_card_submission_mode'], ['image_only', 'code_only', 'either', 'both'], true)
            || ! is_string($payload['gift_card_verification_mode'] ?? null)
            || ! in_array($payload['gift_card_verification_mode'], [
                'manual_only',
                'automatic_only',
                'automatic_then_manual',
                'automatic_with_manual_approval_above_limit',
                'manual_fallback_on_provider_failure',
            ], true)) {
            throw new RuntimeException('Telegram Gift Card private-evidence session state is invalid.');
        }

        return [
            'order_public_id' => strtoupper($payload['order_public_id']),
            'quote_public_id' => strtoupper($payload['quote_public_id']),
            'quote_configuration_hash' => $payload['quote_configuration_hash'],
            'payment_decision_public_id' => strtoupper($payload['payment_decision_public_id']),
            'payment_decision_configuration_hash' => $payload['payment_decision_configuration_hash'],
            'gift_card_type_code' => $payload['gift_card_type_code'],
            'gift_card_type_configuration_hash' => $payload['gift_card_type_configuration_hash'],
            'gift_card_claimed_face_value' => $payload['gift_card_claimed_face_value'],
            'gift_card_submission_mode' => $payload['gift_card_submission_mode'],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{order_public_id:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,usdt_authority_public_id:string}
     */
    private function usdtEvidenceState(array $payload): array
    {
        foreach (['txid', 'private_evidence_reference', 'evidence_content_hash'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                throw new RuntimeException('Telegram USDT private evidence leaked into durable session state.');
            }
        }

        if (($payload['payment_method_code'] ?? null) !== 'usdt_bep20'
            || ! is_string($payload['order_public_id'] ?? null)
            || ! Str::isUlid($payload['order_public_id'])
            || ! is_string($payload['quote_public_id'] ?? null)
            || ! Str::isUlid($payload['quote_public_id'])
            || ! is_string($payload['quote_configuration_hash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['quote_configuration_hash']) !== 1
            || ! is_string($payload['payment_decision_public_id'] ?? null)
            || ! Str::isUlid($payload['payment_decision_public_id'])
            || ! is_string($payload['payment_decision_configuration_hash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['payment_decision_configuration_hash']) !== 1
            || ! is_string($payload['usdt_authority_public_id'] ?? null)
            || ! Str::isUlid($payload['usdt_authority_public_id'])
            || ($payload['usdt_network'] ?? null) !== 'BEP20'
            || ! is_string($payload['usdt_destination_address'] ?? null)
            || preg_match('/\A0x[a-f0-9]{40}\z/', $payload['usdt_destination_address']) !== 1) {
            throw new RuntimeException('Telegram USDT private-evidence session state is invalid.');
        }

        return [
            'order_public_id' => strtoupper($payload['order_public_id']),
            'quote_public_id' => strtoupper($payload['quote_public_id']),
            'quote_configuration_hash' => $payload['quote_configuration_hash'],
            'payment_decision_public_id' => strtoupper($payload['payment_decision_public_id']),
            'payment_decision_configuration_hash' => $payload['payment_decision_configuration_hash'],
            'usdt_authority_public_id' => strtoupper($payload['usdt_authority_public_id']),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function positivePayloadId(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if ((! is_int($value) && ! is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value < 1) {
            throw new RuntimeException('Telegram Support attachment payload identity is invalid.');
        }

        return (int) $value;
    }

    private function queuePaymentEvidenceStatus(
        TelegramPrivateMediaInteraction $interaction,
        string $locale,
        string $status,
    ): void {
        $this->paymentEvidenceStatus->queue(
            $interaction->telegramUserId,
            $interaction->requestKey,
            $locale,
            $status,
        );
    }

    private function maskTxid(string $txid): string
    {
        return substr(strtolower($txid), 0, 10).'…'.substr(strtolower($txid), -8);
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

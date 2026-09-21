<?php

declare(strict_types=1);

namespace App\Modules\Payments\NowPayments\Application;

use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseNowPaymentsPayment;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseNowPaymentsReceipt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramCustomerPurchaseNowPaymentsPaymentService implements TelegramCustomerPurchaseNowPaymentsPayment
{
    private const METHOD_CODE = 'nowpayments';

    public function __construct(
        private TelegramCustomerPurchaseOrder $orders,
        private TelegramCustomerPurchasePaymentMethods $paymentMethods,
        private NowPaymentsPaymentService $nowPayments,
        private DatabaseManager $database,
    ) {}

    /** @requirement BUY-001 BUY-003 PAY-001 PAY-002 PAY-003 IPG-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function claimForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): string {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertOperationKey($operationKey);
        $this->authorizeCheckout(
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
        );

        $intent = $this->nowPayments->claimPurchase(
            $subjectUserId,
            $quotePublicId,
            $decisionPublicId,
            hash('sha256', "telegram-nowpayments-claim\0".$operationKey),
        );
        if ($intent->userId !== $subjectUserId
            || ! hash_equals($intent->sourceQuotePublicId, $quotePublicId)
            || ! hash_equals($intent->eligibilityDecisionPublicId, $decisionPublicId)
            || $intent->methodCode !== self::METHOD_CODE) {
            throw new RuntimeException('Telegram NOWPayments PaymentIntent claim identity changed.');
        }

        return $intent->intentPublicId;
    }

    /** @requirement BUY-001 BUY-003 PAY-002 PAY-003 IPG-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function executeClaimForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $paymentIntentPublicId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseNowPaymentsReceipt {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertOperationKey($operationKey);
        $this->assertClaimIdentity(
            $subjectUserId,
            $paymentIntentPublicId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
        );

        $receipt = $this->nowPayments->executePurchaseClaim(
            $subjectUserId,
            $paymentIntentPublicId,
            $quotePublicId,
            $decisionPublicId,
            hash('sha256', "telegram-nowpayments-execute\0".$operationKey),
        );
        if (! hash_equals($receipt->paymentIntentPublicId, $paymentIntentPublicId)) {
            throw new RuntimeException('Telegram NOWPayments PaymentIntent execution identity changed.');
        }

        return $this->receipt($receipt);
    }

    /** @requirement BUY-001 BUY-003 PAY-001 PAY-002 PAY-003 IPG-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function prepareForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseNowPaymentsReceipt {
        $paymentIntentPublicId = $this->claimForSelf(
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
            $operationKey,
        );

        return $this->executeClaimForSelf(
            $actorUserId,
            $subjectUserId,
            $paymentIntentPublicId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
            $operationKey,
        );
    }

    /** @requirement BUY-003 PAY-003 IPG-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function refreshForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $paymentIntentPublicId,
        string $operationKey,
    ): TelegramCustomerPurchaseNowPaymentsReceipt {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertOperationKey($operationKey);
        $this->assertOwnedIntent($subjectUserId, $paymentIntentPublicId);

        return $this->receipt($this->nowPayments->refresh(
            $paymentIntentPublicId,
            hash('sha256', "telegram-nowpayments-refresh\0".$operationKey),
        ));
    }

    private function authorizeCheckout(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
    ): void {
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
        if (! hash_equals($order->orderPublicId, $orderPublicId)
            || ! hash_equals($order->sourceQuotePublicId, $quotePublicId)
            || ! hash_equals($selection->decisionPublicId, $decisionPublicId)
            || ! hash_equals($selection->sourceQuotePublicId, $quotePublicId)
            || $selection->methodCode !== self::METHOD_CODE) {
            throw new AuthorizationException('Telegram NOWPayments checkout authority is unavailable.');
        }
    }

    private function assertClaimIdentity(
        int $subjectUserId,
        string $paymentIntentPublicId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
    ): void {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $paymentIntentPublicId) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $orderPublicId) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $quotePublicId) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $quoteConfigurationHash) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $decisionPublicId) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $decisionConfigurationHash) !== 1) {
            throw new AuthorizationException('Telegram NOWPayments PaymentIntent claim is unavailable.');
        }

        $intent = $this->database->connection()->table('payment_intents')
            ->where('public_id', strtoupper($paymentIntentPublicId))
            ->first([
                'user_id', 'purpose', 'source_quote_public_id', 'source_quote_configuration_hash',
                'payment_eligibility_decision_public_id', 'payment_eligibility_configuration_hash',
                'payment_method_code', 'provider_code',
            ]);
        $order = $this->database->connection()->table('orders')
            ->where('public_id', strtoupper($orderPublicId))
            ->first([
                'user_id', 'source_type', 'source_quote_public_id', 'source_quote_configuration_hash',
                'payment_intent_public_id',
            ]);

        if ($intent === null
            || $order === null
            || (int) $intent->user_id !== $subjectUserId
            || $intent->purpose !== 'purchase'
            || ! is_string($intent->source_quote_public_id)
            || ! hash_equals($intent->source_quote_public_id, $quotePublicId)
            || ! is_string($intent->source_quote_configuration_hash)
            || ! hash_equals(strtolower($intent->source_quote_configuration_hash), strtolower($quoteConfigurationHash))
            || ! is_string($intent->payment_eligibility_decision_public_id)
            || ! hash_equals($intent->payment_eligibility_decision_public_id, $decisionPublicId)
            || ! is_string($intent->payment_eligibility_configuration_hash)
            || ! hash_equals(strtolower($intent->payment_eligibility_configuration_hash), strtolower($decisionConfigurationHash))
            || $intent->payment_method_code !== self::METHOD_CODE
            || $intent->provider_code !== self::METHOD_CODE
            || (int) $order->user_id !== $subjectUserId
            || $order->source_type !== 'purchase'
            || ! is_string($order->source_quote_public_id)
            || ! hash_equals($order->source_quote_public_id, $quotePublicId)
            || ! is_string($order->source_quote_configuration_hash)
            || ! hash_equals(strtolower($order->source_quote_configuration_hash), strtolower($quoteConfigurationHash))
            || (is_string($order->payment_intent_public_id)
                && ! hash_equals($order->payment_intent_public_id, $paymentIntentPublicId))) {
            throw new AuthorizationException('Telegram NOWPayments PaymentIntent claim is unavailable.');
        }
    }

    private function assertOwnedIntent(int $subjectUserId, string $paymentIntentPublicId): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $paymentIntentPublicId) !== 1) {
            throw new AuthorizationException('Telegram NOWPayments payment is unavailable.');
        }
        $row = $this->database->connection()->table('payment_intents')
            ->where('public_id', strtoupper($paymentIntentPublicId))
            ->first(['user_id', 'purpose', 'provider_code']);
        if ($row === null
            || (int) $row->user_id !== $subjectUserId
            || $row->purpose !== 'purchase'
            || $row->provider_code !== self::METHOD_CODE) {
            throw new AuthorizationException('Telegram NOWPayments payment is unavailable.');
        }
    }

    private function assertSelf(int $actorUserId, int $subjectUserId): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram NOWPayments self access denied.');
        }
    }

    private function assertOperationKey(string $operationKey): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Telegram NOWPayments operation identity is invalid.');
        }
    }

    private function receipt(NowPaymentsPaymentReceipt $receipt): TelegramCustomerPurchaseNowPaymentsReceipt
    {
        return new TelegramCustomerPurchaseNowPaymentsReceipt(
            $receipt->authorityPublicId,
            $receipt->paymentIntentPublicId,
            $receipt->state->value,
            $receipt->rateSource,
            $receipt->rateIrr,
            $receipt->priceAmountUsd,
            $receipt->payCurrency,
            $receipt->providerPaymentId,
            $receipt->providerStatus,
            $receipt->providerPayAmount,
            $receipt->providerPayAddress,
            $receipt->settlementPublicId,
            $receipt->replayed,
            $receipt->manualReviewRequired,
        );
    }
}

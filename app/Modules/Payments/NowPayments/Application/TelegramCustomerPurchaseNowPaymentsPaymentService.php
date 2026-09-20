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

        return $this->receipt($this->nowPayments->initiatePurchase(
            $subjectUserId,
            $quotePublicId,
            $decisionPublicId,
            hash('sha256', "telegram-nowpayments-prepare\0".$operationKey),
        ));
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

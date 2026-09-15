<?php

declare(strict_types=1);

namespace App\Modules\Payments\Zarinpal\Application;

use App\Modules\Payments\Zarinpal\Domain\ZarinpalRequestState;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseZarinpalPayment;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseZarinpalRedirect;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

final readonly class TelegramCustomerPurchaseZarinpalPaymentService implements TelegramCustomerPurchaseZarinpalPayment
{
    private const METHOD_CODE = 'zarinpal';

    public function __construct(
        private TelegramCustomerPurchaseOrder $orders,
        private TelegramCustomerPurchasePaymentMethods $paymentMethods,
        private ZarinpalPaymentService $zarinpal,
    ) {}

    /** @requirement BUY-001 BUY-003 PAY-001 PAY-002 IPG-001 DAT-002 DAT-003 SEC-002 QUA-001 QUA-004 */
    public function prepareForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseZarinpalRedirect {
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

        $receipt = $this->zarinpal->initiatePurchase(
            $subjectUserId,
            $quotePublicId,
            $decisionPublicId,
            hash('sha256', "telegram-zarinpal-prepare\0".$operationKey),
        );
        $redirectUrl = $receipt->state === ZarinpalRequestState::Redirectable ? $receipt->redirectUrl : null;

        return new TelegramCustomerPurchaseZarinpalRedirect(
            $receipt->publicId,
            $receipt->paymentIntentPublicId,
            $receipt->state->value,
            $redirectUrl,
            $receipt->replayed,
            $receipt->manualReviewRequired,
        );
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
            throw new AuthorizationException('Telegram Zarinpal checkout authority is unavailable.');
        }
    }

    private function assertSelf(int $actorUserId, int $subjectUserId): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram Zarinpal payment self access denied.');
        }
    }

    private function assertOperationKey(string $operationKey): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Telegram Zarinpal operation identity is invalid.');
        }
    }
}

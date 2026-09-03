<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\OrderState;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseOrderReceipt;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

final readonly class TelegramCustomerPurchaseOrderService implements TelegramCustomerPurchaseOrder
{
    public function __construct(
        private QuoteService $quotes,
        private PurchaseOrderService $orders,
    ) {}

    /** @requirement BUY-001 BUY-002 BUY-003 PAY-001 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function openForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $correlationId,
    ): TelegramCustomerPurchaseOrderReceipt {
        $quote = $this->currentQuoteForSelf($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash);

        try {
            $opened = $this->orders->openFromQuote($quotePublicId, $actorUserId, $correlationId);
        } catch (DomainException $exception) {
            throw new AuthorizationException('Telegram purchase Order is unavailable.', previous: $exception);
        }

        if ($opened->userId !== $subjectUserId
            || ! hash_equals($opened->sourceQuotePublicId, $quotePublicId)
            || $opened->state !== OrderState::AwaitingPayment
            || $opened->stateVersion !== 0
            || $opened->commercialAmount->currency() !== 'IRR'
            || $opened->commercialAmount->amount() !== $quote->finalPriceIrr) {
            throw new RuntimeException('Telegram purchase Order opening does not match the current Quote.');
        }

        $current = $this->currentForSelf(
            $actorUserId,
            $subjectUserId,
            $opened->orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
        );

        return new TelegramCustomerPurchaseOrderReceipt(
            $current->orderPublicId,
            $current->sourceQuotePublicId,
            $current->sourceQuoteConfigurationHash,
            $current->amountIrr,
            $current->currency,
            $opened->replayed,
        );
    }

    /** @requirement BUY-001 BUY-002 BUY-003 PAY-001 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function currentForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
    ): TelegramCustomerPurchaseOrderReceipt {
        $quote = $this->currentQuoteForSelf($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $orderPublicId) !== 1) {
            throw new AuthorizationException('Telegram purchase Order is unavailable.');
        }

        try {
            $opened = $this->orders->currentFromQuote($quotePublicId, $actorUserId);
        } catch (DomainException $exception) {
            throw new AuthorizationException('Telegram purchase Order is unavailable.', previous: $exception);
        }
        if (! hash_equals($opened->orderPublicId, $orderPublicId)
            || $opened->userId !== $subjectUserId
            || ! hash_equals($opened->sourceQuotePublicId, $quotePublicId)
            || $opened->state !== OrderState::AwaitingPayment
            || $opened->stateVersion !== 0
            || $opened->commercialAmount->currency() !== 'IRR'
            || $opened->commercialAmount->amount() !== $quote->finalPriceIrr) {
            throw new AuthorizationException('Telegram purchase Order is unavailable.');
        }

        return new TelegramCustomerPurchaseOrderReceipt(
            $opened->orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $opened->commercialAmount->amount(),
            $opened->commercialAmount->currency(),
            true,
        );
    }

    private function currentQuoteForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
    ): QuoteReceipt {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId
            || preg_match('/\A[0-9a-f]{64}\z/', $quoteConfigurationHash) !== 1) {
            throw new AuthorizationException('Telegram purchase Order self access denied.');
        }

        try {
            $quote = $this->quotes->current($quotePublicId);
        } catch (DomainException $exception) {
            throw new AuthorizationException('Telegram purchase Order Quote is unavailable.', previous: $exception);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Quote has expired.') {
                throw new AuthorizationException('Telegram purchase Order Quote is unavailable.', previous: $exception);
            }
            throw $exception;
        }

        if ($quote->userId !== $subjectUserId
            || ! hash_equals($quote->configurationSnapshotHash, $quoteConfigurationHash)
            || $quote->currency !== 'IRR'
            || $quote->finalPriceIrr < 1) {
            throw new AuthorizationException('Telegram purchase Order Quote is unavailable.');
        }

        return $quote;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payments\Zarinpal\Application;

use App\Modules\Payments\Application\WalletTopUpPaymentService;
use App\Modules\Payments\Zarinpal\Domain\ZarinpalRequestState;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerWalletTopUpPayment;
use App\Modules\Telegram\Application\TelegramCustomerWalletTopUpRedirect;
use App\Modules\Wallet\Application\WalletCashAccountService;
use App\Shared\Domain\Money;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramCustomerWalletTopUpZarinpalService implements TelegramCustomerWalletTopUpPayment
{
    private const PROVIDER_CODE = 'zarinpal';

    public function __construct(
        private WalletCashAccountService $cashAccounts,
        private WalletTopUpPaymentService $topUps,
        private ZarinpalPaymentService $zarinpal,
    ) {}

    /** @requirement WAL-001 PAY-002 PAY-003 IPG-001 SEC-002 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function prepareForSelf(
        int $actorUserId,
        int $subjectUserId,
        int $amountIrr,
        string $operationKey,
    ): TelegramCustomerWalletTopUpRedirect {
        $this->assertSelf($actorUserId, $subjectUserId);
        if ($amountIrr < 1) {
            throw new InvalidArgumentException('Wallet top-up amount must be positive.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new InvalidArgumentException('Wallet top-up operation identity is invalid.');
        }

        $wallet = $this->cashAccounts->forSelf($subjectUserId, $actorUserId);
        $intent = $this->topUps->create(
            'telegram.wallet-top-up.intent:'.$operationKey,
            $subjectUserId,
            $wallet->accountId,
            self::PROVIDER_CODE,
            Money::irr($amountIrr),
            'tg-topup-intent:'.substr(hash('sha256', $operationKey), 0, 48),
        );
        if ($intent->userId !== $subjectUserId
            || $intent->walletAccountId !== $wallet->accountId
            || $intent->providerCode !== self::PROVIDER_CODE
            || $intent->amount->amount() !== $amountIrr
            || $intent->amount->currency() !== 'IRR') {
            throw new RuntimeException('Wallet top-up PaymentIntent identity changed.');
        }

        $receipt = $this->zarinpal->initiateWalletTopUp(
            $subjectUserId,
            $intent->intentPublicId,
            'tg-topup-zarinpal:'.substr(hash('sha256', $operationKey), 0, 46),
        );
        if (! hash_equals($receipt->paymentIntentPublicId, $intent->intentPublicId)) {
            throw new RuntimeException('Zarinpal wallet top-up PaymentIntent identity changed.');
        }

        return new TelegramCustomerWalletTopUpRedirect(
            $receipt->publicId,
            $receipt->paymentIntentPublicId,
            $receipt->state->value,
            $amountIrr,
            $receipt->state === ZarinpalRequestState::Redirectable ? $receipt->redirectUrl : null,
            $receipt->replayed,
            $receipt->manualReviewRequired,
        );
    }

    private function assertSelf(int $actorUserId, int $subjectUserId): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Wallet top-up self access denied.');
        }
    }
}

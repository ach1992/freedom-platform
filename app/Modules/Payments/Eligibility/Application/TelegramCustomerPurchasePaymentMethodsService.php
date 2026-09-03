<?php

declare(strict_types=1);

namespace App\Modules\Payments\Eligibility\Application;

use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodsDecision;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;
use Symfony\Component\Uid\Ulid;

final readonly class TelegramCustomerPurchasePaymentMethodsService implements TelegramCustomerPurchasePaymentMethods
{
    public function __construct(private PaymentMethodEligibilityService $eligibility) {}

    /** @requirement BUY-001 BUY-003 PAY-001 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function discoverForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionKey,
    ): TelegramCustomerPurchasePaymentMethodsDecision {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram purchase payment-method self access denied.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $quoteConfigurationHash) !== 1) {
            throw new RuntimeException('Telegram purchase payment-method Quote snapshot identity is invalid.');
        }
        $this->callbackPublicId($decisionKey);

        try {
            $decision = $this->eligibility->evaluate(
                $decisionKey,
                $actorUserId,
                $quotePublicId,
                $quoteConfigurationHash,
            );
        } catch (RuntimeException $exception) {
            if (in_array($exception->getMessage(), [
                'Payment eligibility requires a current commercial Quote.',
                'Payment eligibility Quote snapshot does not match the expected configuration.',
            ], true)) {
                throw new AuthorizationException('Telegram purchase payment methods are unavailable.', previous: $exception);
            }

            throw $exception;
        }

        if ($decision->userId !== $subjectUserId
            || ! hash_equals($decision->sourceQuotePublicId, $quotePublicId)) {
            throw new RuntimeException('Telegram purchase payment-method decision does not match the current Quote.');
        }

        $methodCodes = [];
        foreach ($decision->methods as $method) {
            $methodCodes[] = $method['method_code'];
        }

        return new TelegramCustomerPurchasePaymentMethodsDecision(
            $decision->publicId,
            $decision->sourceQuotePublicId,
            $quoteConfigurationHash,
            $decision->configurationSnapshotHash,
            $methodCodes,
            $decision->replayed,
        );
    }

    private function callbackPublicId(string $decisionKey): string
    {
        if (preg_match('/\Atelegram-purchase-payment-methods:([0-9A-HJKMNP-TV-Z]{26})\z/i', $decisionKey, $matches) !== 1) {
            throw new RuntimeException('Telegram purchase payment-method decision key is invalid.');
        }
        $publicId = strtoupper($matches[1]);
        if (! Ulid::isValid($publicId)) {
            throw new RuntimeException('Telegram purchase payment-method callback identity is invalid.');
        }

        return $publicId;
    }
}

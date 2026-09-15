<?php

declare(strict_types=1);

namespace App\Modules\Payments\Eligibility\Application;

use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodsDecision;
use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodSelection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Symfony\Component\Uid\Ulid;

final readonly class TelegramCustomerPurchasePaymentMethodsService implements TelegramCustomerPurchasePaymentMethods
{
    public function __construct(
        private PaymentMethodEligibilityService $eligibility,
        private DatabaseManager $database,
    ) {}

    /** @requirement BUY-001 BUY-003 PAY-001 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function discoverForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionKey,
    ): TelegramCustomerPurchasePaymentMethodsDecision {
        $this->assertSelfInput($actorUserId, $subjectUserId, $quoteConfigurationHash);
        $this->callbackPublicId($decisionKey);

        try {
            $decision = $this->eligibility->evaluate(
                $decisionKey,
                $actorUserId,
                $quotePublicId,
                $quoteConfigurationHash,
            );
        } catch (RuntimeException $exception) {
            $this->throwUnavailableWhenQuoteIsStale($exception);
            throw $exception;
        }

        return $this->decisionForTelegram($decision, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
    }

    /** @requirement BUY-001 BUY-003 PAY-001 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function currentForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
    ): TelegramCustomerPurchasePaymentMethodsDecision {
        $this->assertSelfInput($actorUserId, $subjectUserId, $quoteConfigurationHash);
        if (! Ulid::isValid($decisionPublicId)
            || preg_match('/\A[0-9a-f]{64}\z/', $decisionConfigurationHash) !== 1) {
            throw new AuthorizationException('Telegram purchase payment methods are unavailable.');
        }

        $stored = $this->database->connection()->table('payment_method_eligibility_decisions')
            ->where('public_id', strtoupper($decisionPublicId))
            ->first(['decision_key', 'public_id', 'source_quote_public_id', 'user_id', 'configuration_snapshot_hash']);
        if ($stored === null
            || (int) $stored->user_id !== $subjectUserId
            || ! hash_equals((string) $stored->source_quote_public_id, $quotePublicId)
            || ! hash_equals((string) $stored->configuration_snapshot_hash, $decisionConfigurationHash)) {
            throw new AuthorizationException('Telegram purchase payment methods are unavailable.');
        }

        try {
            $decision = $this->eligibility->evaluate(
                (string) $stored->decision_key,
                $actorUserId,
                $quotePublicId,
                $quoteConfigurationHash,
            );
        } catch (RuntimeException $exception) {
            $this->throwUnavailableWhenQuoteIsStale($exception);
            throw $exception;
        }

        if (! hash_equals($decision->publicId, (string) $stored->public_id)
            || ! hash_equals($decision->configurationSnapshotHash, $decisionConfigurationHash)) {
            throw new RuntimeException('Telegram purchase payment-method replay identity changed.');
        }

        return $this->decisionForTelegram($decision, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
    }

    /** @requirement BUY-001 BUY-003 PAY-001 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function selectForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $methodCode,
    ): TelegramCustomerPurchasePaymentMethodSelection {
        if (preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $methodCode) !== 1) {
            throw new AuthorizationException('Telegram purchase payment method is unavailable.');
        }
        $decision = $this->currentForSelf(
            $actorUserId,
            $subjectUserId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
        );
        if (! in_array($methodCode, $decision->methodCodes, true)) {
            throw new AuthorizationException('Telegram purchase payment method is unavailable.');
        }

        return new TelegramCustomerPurchasePaymentMethodSelection(
            $decision->decisionPublicId,
            $decision->sourceQuotePublicId,
            $methodCode,
        );
    }

    private function assertSelfInput(int $actorUserId, int $subjectUserId, string $quoteConfigurationHash): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram purchase payment-method self access denied.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $quoteConfigurationHash) !== 1) {
            throw new RuntimeException('Telegram purchase payment-method Quote snapshot identity is invalid.');
        }
    }

    private function decisionForTelegram(
        PaymentEligibilityDecisionReceipt $decision,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
    ): TelegramCustomerPurchasePaymentMethodsDecision {
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

    private function throwUnavailableWhenQuoteIsStale(RuntimeException $exception): void
    {
        if (in_array($exception->getMessage(), [
            'Payment eligibility requires a current commercial Quote.',
            'Payment eligibility Quote snapshot does not match the expected configuration.',
        ], true)) {
            throw new AuthorizationException('Telegram purchase payment methods are unavailable.', previous: $exception);
        }
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

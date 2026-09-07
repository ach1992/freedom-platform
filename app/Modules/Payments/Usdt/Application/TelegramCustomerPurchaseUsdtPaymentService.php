<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseUsdtPayment;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseOrderReceipt;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseUsdtInstructions;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseUsdtSubmission;
use App\Shared\Application\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramCustomerPurchaseUsdtPaymentService implements TelegramCustomerPurchaseUsdtPayment
{
    private const METHOD_CODE = UsdtPaymentAuthorityService::METHOD_CODE;

    public function __construct(
        private DatabaseManager $database,
        private TelegramCustomerPurchaseOrder $orders,
        private TelegramCustomerPurchasePaymentMethods $paymentMethods,
        private UsdtAmountQuoteService $amountQuotes,
        private UsdtPaymentAuthorityService $authorities,
        private UsdtTxidSubmissionService $submissions,
        private Clock $clock,
    ) {}

    /** @requirement BUY-001 BUY-003 PAY-001 PAY-002 PRO-001 USDT-001 USDT-002 USDT-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function initiateForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseUsdtInstructions {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertOperationKey($operationKey);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
            $operationKey,
        ): TelegramCustomerPurchaseUsdtInstructions {
            $order = $this->authorizeCheckout(
                $actorUserId,
                $subjectUserId,
                $orderPublicId,
                $quotePublicId,
                $quoteConfigurationHash,
                $decisionPublicId,
                $decisionConfigurationHash,
            );

            $amountQuote = $this->amountQuotes->create('telegram-usdt-amount:'.$orderPublicId, $quotePublicId);
            if ($amountQuote->userId !== $subjectUserId
                || ! hash_equals($amountQuote->sourceQuotePublicId, $quotePublicId)
                || $amountQuote->network !== UsdtBep20Asset::NETWORK
                || $amountQuote->orderAmountIrr !== $order->amountIrr
                || preg_match('/\A0x[a-f0-9]{40}\z/', $amountQuote->destinationAddress) !== 1
                || $amountQuote->expiresAt <= $this->clock->now()) {
                throw new AuthorizationException('Telegram USDT amount quote is unavailable.');
            }

            $authority = $this->authorities->prepare(
                'telegram-usdt-authority:'.$orderPublicId,
                'telegram-usdt-intent:'.$orderPublicId,
                $subjectUserId,
                $quotePublicId,
                $decisionPublicId,
                $amountQuote->publicId,
                $this->correlationId('initiate', $operationKey),
            );
            if ($authority->userId !== $subjectUserId
                || ! hash_equals($authority->amountQuotePublicId, $amountQuote->publicId)
                || $authority->amountIrr !== $order->amountIrr
                || $authority->network !== UsdtBep20Asset::NETWORK
                || ! hash_equals($authority->destinationAddress, $amountQuote->destinationAddress)
                || $authority->quoteExpiresAt != $amountQuote->expiresAt) {
                throw new RuntimeException('Telegram USDT payment authority does not match the current checkout.');
            }

            $this->storedAuthority(
                $connection,
                $authority->publicId,
                $authority->paymentIntentPublicId,
                $amountQuote->publicId,
                $subjectUserId,
                $quotePublicId,
                $quoteConfigurationHash,
                $decisionPublicId,
                $decisionConfigurationHash,
                $orderPublicId,
                [PaymentIntentState::AwaitingUserAction->value],
                false,
            );

            return new TelegramCustomerPurchaseUsdtInstructions(
                $authority->publicId,
                $authority->paymentIntentPublicId,
                $amountQuote->publicId,
                $orderPublicId,
                $quotePublicId,
                $decisionPublicId,
                $amountQuote->network,
                $amountQuote->destinationAddress,
                $amountQuote->exactUsdt,
                $amountQuote->expiresAt,
                $amountQuote->replayed || $authority->replayed,
            );
        }, 3);
    }

    /** @requirement BUY-001 BUY-003 PAY-001 PAY-002 PRO-001 USDT-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function submitTxidForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $authorityPublicId,
        string $paymentIntentPublicId,
        string $amountQuotePublicId,
        string $txid,
        string $operationKey,
    ): TelegramCustomerPurchaseUsdtSubmission {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertOperationKey($operationKey);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
            $authorityPublicId,
            $paymentIntentPublicId,
            $amountQuotePublicId,
            $txid,
            $operationKey,
        ): TelegramCustomerPurchaseUsdtSubmission {
            $order = $this->authorizeCheckout(
                $actorUserId,
                $subjectUserId,
                $orderPublicId,
                $quotePublicId,
                $quoteConfigurationHash,
                $decisionPublicId,
                $decisionConfigurationHash,
            );
            $stored = $this->storedAuthority(
                $connection,
                $authorityPublicId,
                $paymentIntentPublicId,
                $amountQuotePublicId,
                $subjectUserId,
                $quotePublicId,
                $quoteConfigurationHash,
                $decisionPublicId,
                $decisionConfigurationHash,
                $orderPublicId,
                [PaymentIntentState::AwaitingUserAction->value, PaymentIntentState::Submitted->value],
                true,
            );
            if ((int) $stored->amount_irr !== $order->amountIrr) {
                throw new AuthorizationException('Telegram USDT payment amount is unavailable.');
            }

            $submission = $this->submissions->submit(
                'telegram-usdt-txid:'.$orderPublicId,
                $authorityPublicId,
                $subjectUserId,
                $txid,
                null,
                null,
                $this->correlationId('txid', $operationKey),
            );
            if (! hash_equals($submission->authorityPublicId, $authorityPublicId)
                || ! hash_equals($submission->paymentIntentPublicId, $paymentIntentPublicId)
                || $submission->state !== PaymentIntentState::Submitted->value) {
                throw new RuntimeException('Telegram USDT TXID submission result is inconsistent.');
            }

            return new TelegramCustomerPurchaseUsdtSubmission(
                $submission->publicId,
                $submission->authorityPublicId,
                $submission->paymentIntentPublicId,
                $submission->txid,
                $submission->state,
                $submission->replayed,
            );
        }, 3);
    }

    private function authorizeCheckout(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
    ): TelegramCustomerPurchaseOrderReceipt {
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
            throw new AuthorizationException('Telegram USDT checkout authority is unavailable.');
        }

        return $order;
    }

    /**
     * @param  list<string>  $allowedIntentStates
     * @return object{state:string,captured_at:string|null,amount_irr:int|string}
     */
    private function storedAuthority(
        Connection $connection,
        string $authorityPublicId,
        string $paymentIntentPublicId,
        string $amountQuotePublicId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $orderPublicId,
        array $allowedIntentStates,
        bool $lock,
    ): object {
        foreach ([$authorityPublicId, $paymentIntentPublicId, $amountQuotePublicId, $orderPublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new AuthorizationException('Telegram USDT payment authority is unavailable.');
            }
        }
        $query = $connection->table('usdt_payment_authorities as authority')
            ->join('payment_intents as intent', 'intent.id', '=', 'authority.payment_intent_id')
            ->join('usdt_amount_quotes as amount_quote', 'amount_quote.id', '=', 'authority.usdt_amount_quote_id')
            ->join('quotes as quote', 'quote.id', '=', 'intent.source_quote_id')
            ->where('authority.public_id', strtoupper($authorityPublicId));
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{authority_public_id:string,amount_quote_public_id:string,payment_intent_public_id:string,state:string,captured_at:string|null,amount_irr:int|string,user_id:int|string,source_quote_public_id:string|null,source_quote_configuration_hash:string|null,payment_eligibility_decision_public_id:string|null,payment_eligibility_configuration_hash:string|null,payment_method_code:string|null,provider_code:string,purpose:string,quote_id:int|string}|null $row */
        $row = $query->first([
            'authority.public_id as authority_public_id',
            'amount_quote.public_id as amount_quote_public_id',
            'intent.public_id as payment_intent_public_id',
            'intent.state',
            'intent.captured_at',
            'intent.amount_irr',
            'intent.user_id',
            'intent.source_quote_public_id',
            'intent.source_quote_configuration_hash',
            'intent.payment_eligibility_decision_public_id',
            'intent.payment_eligibility_configuration_hash',
            'intent.payment_method_code',
            'intent.provider_code',
            'intent.purpose',
            'quote.id as quote_id',
        ]);
        if ($row === null
            || ! hash_equals((string) $row->authority_public_id, strtoupper($authorityPublicId))
            || ! hash_equals((string) $row->amount_quote_public_id, strtoupper($amountQuotePublicId))
            || ! hash_equals((string) $row->payment_intent_public_id, strtoupper($paymentIntentPublicId))
            || ! in_array($row->state, $allowedIntentStates, true)
            || $row->captured_at !== null
            || $row->purpose !== 'purchase'
            || (int) $row->user_id !== $subjectUserId
            || $row->source_quote_public_id === null
            || ! hash_equals($row->source_quote_public_id, $quotePublicId)
            || $row->source_quote_configuration_hash === null
            || ! hash_equals($row->source_quote_configuration_hash, $quoteConfigurationHash)
            || $row->payment_eligibility_decision_public_id === null
            || ! hash_equals($row->payment_eligibility_decision_public_id, $decisionPublicId)
            || $row->payment_eligibility_configuration_hash === null
            || ! hash_equals($row->payment_eligibility_configuration_hash, $decisionConfigurationHash)
            || $row->payment_method_code !== self::METHOD_CODE
            || $row->provider_code !== self::METHOD_CODE) {
            throw new AuthorizationException('Telegram USDT payment authority is unavailable.');
        }
        $storedOrder = $connection->table('orders')
            ->where('public_id', strtoupper($orderPublicId))
            ->where('user_id', $subjectUserId)
            ->where('source_quote_id', (int) $row->quote_id)
            ->lockForUpdate()
            ->value('public_id');
        if (! is_string($storedOrder) || ! hash_equals($storedOrder, strtoupper($orderPublicId))) {
            throw new AuthorizationException('Telegram USDT payment Order is unavailable.');
        }

        return $row;
    }

    private function assertSelf(int $actorUserId, int $subjectUserId): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram USDT payment self access denied.');
        }
    }

    private function assertOperationKey(string $operationKey): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Telegram USDT payment operation identity is invalid.');
        }
    }

    private function correlationId(string $operation, string $operationKey): string
    {
        return hash('sha256', 'telegram-usdt-'.$operation."\0".$operationKey);
    }
}

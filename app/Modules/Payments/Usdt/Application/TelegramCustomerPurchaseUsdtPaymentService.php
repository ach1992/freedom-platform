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
    private const METHOD_CODE = 'usdt_bep20';

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
    public function prepareForSelf(
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

        // Rate resolution may perform HTTP. Validate the checkout before the
        // lookup, but deliberately do not hold the pre-payment Order lock
        // across that external read. The authoritative lock/revalidation is
        // repeated immediately before Payment Intent/authority creation.
        $this->authorizeCheckout(
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
        );
        $amountQuotePreparation = $this->amountQuotes->resolve(
            'telegram-usdt-amount:'.$orderPublicId,
            $quotePublicId,
        );

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
            $operationKey,
            $amountQuotePreparation,
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
            $amountQuote = $this->amountQuotes->persist($amountQuotePreparation);
            if ($amountQuote->expiresAt <= $this->clock->now()) {
                throw new AuthorizationException('Telegram USDT amount quote has expired.');
            }
            if ($amountQuote->userId !== $subjectUserId
                || ! hash_equals($amountQuote->sourceQuotePublicId, $quotePublicId)
                || $amountQuote->orderAmountIrr !== $order->amountIrr
                || $amountQuote->network !== UsdtBep20Asset::NETWORK
                || preg_match('/\A0x[a-f0-9]{40}\z/', $amountQuote->destinationAddress) !== 1) {
                throw new RuntimeException('Telegram USDT amount quote does not match current checkout authority.');
            }

            $authority = $this->authorities->prepare(
                'telegram-usdt-authority:'.$orderPublicId,
                'telegram-usdt-intent:'.$orderPublicId,
                $subjectUserId,
                $quotePublicId,
                $decisionPublicId,
                $amountQuote->publicId,
                $this->correlationId('prepare', $operationKey),
            );
            $expectedBaseUnits = UsdtTokenAmount::toBaseUnits($amountQuote->exactUsdt, UsdtBep20Asset::TOKEN_DECIMALS);
            if ($authority->userId !== $subjectUserId
                || $authority->amountIrr !== $order->amountIrr
                || ! hash_equals($authority->amountQuotePublicId, $amountQuote->publicId)
                || $authority->network !== UsdtBep20Asset::NETWORK
                || $authority->chainId !== UsdtBep20Asset::CHAIN_ID
                || ! hash_equals($authority->tokenContract, UsdtBep20Asset::TOKEN_CONTRACT)
                || $authority->tokenDecimals !== UsdtBep20Asset::TOKEN_DECIMALS
                || ! hash_equals($authority->destinationAddress, $amountQuote->destinationAddress)
                || ! hash_equals($authority->expectedAmountBaseUnits, $expectedBaseUnits)
                || $authority->quoteExpiresAt != $amountQuote->expiresAt) {
                throw new RuntimeException('Telegram USDT authority does not match the immutable amount quote.');
            }

            $stored = $this->storedAuthority(
                $connection,
                $authority->publicId,
                $subjectUserId,
                $quotePublicId,
                $quoteConfigurationHash,
                $decisionPublicId,
                $decisionConfigurationHash,
                [PaymentIntentState::AwaitingUserAction->value],
            );
            if (! hash_equals((string) $stored->intent_public_id, $authority->paymentIntentPublicId)) {
                throw new RuntimeException('Telegram USDT authority Payment Intent linkage is inconsistent.');
            }

            return new TelegramCustomerPurchaseUsdtInstructions(
                $authority->publicId,
                $authority->paymentIntentPublicId,
                $authority->amountQuotePublicId,
                $authority->network,
                $amountQuote->exactUsdt,
                $authority->destinationAddress,
                $authority->quoteExpiresAt,
                $amountQuote->replayed || $authority->replayed,
            );
        }, 3);
    }

    /** @requirement BUY-001 BUY-003 PAY-001 PAY-002 PAY-003 PRO-001 USDT-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function submitTxidForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $authorityPublicId,
        string $txid,
        string $operationKey,
    ): TelegramCustomerPurchaseUsdtSubmission {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertOperationKey($operationKey);
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $authorityPublicId) !== 1) {
            throw new AuthorizationException('Telegram USDT payment authority is unavailable.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
            $authorityPublicId,
            $txid,
            $operationKey,
        ): TelegramCustomerPurchaseUsdtSubmission {
            $this->authorizeCheckout(
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
                $subjectUserId,
                $quotePublicId,
                $quoteConfigurationHash,
                $decisionPublicId,
                $decisionConfigurationHash,
                [
                    PaymentIntentState::AwaitingUserAction->value,
                    PaymentIntentState::Submitted->value,
                ],
            );

            $submission = $this->submissions->submit(
                'telegram-usdt-txid:'.$orderPublicId,
                strtoupper($authorityPublicId),
                $subjectUserId,
                $txid,
                null,
                null,
                $this->correlationId('txid', $operationKey),
            );
            if (! hash_equals($submission->authorityPublicId, strtoupper($authorityPublicId))
                || ! hash_equals($submission->paymentIntentPublicId, (string) $stored->intent_public_id)
                || $submission->state !== PaymentIntentState::Submitted->value) {
                throw new RuntimeException('Telegram USDT TXID submission does not match current checkout authority.');
            }

            $intent = $connection->table('payment_intents')
                ->where('public_id', $submission->paymentIntentPublicId)
                ->lockForUpdate()
                ->first(['state', 'captured_at']);
            if ($intent === null
                || $intent->state !== PaymentIntentState::Submitted->value
                || $intent->captured_at !== null
                || $connection->table('purchase_settlements')->where('payment_intent_id', $stored->intent_id)->exists()) {
                throw new RuntimeException('Telegram USDT submission unexpectedly produced settlement authority.');
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
     * @return object{intent_id:int|string,intent_public_id:string,intent_state:string,captured_at:string|null}
     */
    private function storedAuthority(
        Connection $connection,
        string $authorityPublicId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        array $allowedIntentStates,
    ): object {
        /** @var object{intent_id:int|string,intent_public_id:string,intent_state:string,captured_at:string|null,user_id:int|string,source_quote_public_id:string|null,source_quote_configuration_hash:string|null,payment_eligibility_decision_public_id:string|null,payment_eligibility_configuration_hash:string|null,payment_method_code:string|null,provider_code:string,purpose:string,authority_user_id:int|string,authority_quote_public_id:string}|null $row */
        $row = $connection->table('usdt_payment_authorities as authority')
            ->join('payment_intents as intent', 'intent.id', '=', 'authority.payment_intent_id')
            ->where('authority.public_id', strtoupper($authorityPublicId))
            ->lockForUpdate()
            ->first([
                'intent.id as intent_id',
                'intent.public_id as intent_public_id',
                'intent.state as intent_state',
                'intent.captured_at',
                'intent.user_id',
                'intent.source_quote_public_id',
                'intent.source_quote_configuration_hash',
                'intent.payment_eligibility_decision_public_id',
                'intent.payment_eligibility_configuration_hash',
                'intent.payment_method_code',
                'intent.provider_code',
                'intent.purpose',
                'authority.user_id as authority_user_id',
                'authority.source_quote_public_id as authority_quote_public_id',
            ]);
        if ($row === null
            || ! in_array((string) $row->intent_state, $allowedIntentStates, true)
            || $row->captured_at !== null
            || (int) $row->user_id !== $subjectUserId
            || (int) $row->authority_user_id !== $subjectUserId
            || $row->purpose !== 'purchase'
            || $row->source_quote_public_id === null
            || ! hash_equals($row->source_quote_public_id, $quotePublicId)
            || ! hash_equals($row->authority_quote_public_id, $quotePublicId)
            || $row->source_quote_configuration_hash === null
            || ! hash_equals(strtolower($row->source_quote_configuration_hash), $quoteConfigurationHash)
            || $row->payment_eligibility_decision_public_id === null
            || ! hash_equals($row->payment_eligibility_decision_public_id, $decisionPublicId)
            || $row->payment_eligibility_configuration_hash === null
            || ! hash_equals(strtolower($row->payment_eligibility_configuration_hash), $decisionConfigurationHash)
            || $row->payment_method_code !== self::METHOD_CODE
            || $row->provider_code !== self::METHOD_CODE
            || $connection->table('purchase_settlements')->where('payment_intent_id', $row->intent_id)->exists()) {
            throw new AuthorizationException('Telegram USDT payment authority is unavailable.');
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
            throw new RuntimeException('Telegram USDT operation identity is invalid.');
        }
    }

    private function correlationId(string $operation, string $operationKey): string
    {
        return hash('sha256', 'telegram-usdt-'.$operation."\0".$operationKey);
    }
}

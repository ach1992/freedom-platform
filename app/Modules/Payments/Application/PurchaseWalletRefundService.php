<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletHoldStatus;
use App\Modules\Wallet\Domain\WalletSystemAccountCode;
use App\Shared\Domain\Money;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class PurchaseWalletRefundService
{
    private const PROVIDER_CODE = 'wallet';

    private const LEDGER_TRANSACTION_TYPE = 'wallet_purchase_refund';

    private const LEDGER_SOURCE_TYPE = 'purchase_refund';

    public function __construct(
        private DatabaseManager $database,
        private LedgerPostingService $ledger,
        private PurchaseRefundService $purchaseRefunds,
    ) {}

    /** @requirement PAY-002 PAY-003 WAL-002 WAL-004 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function refund(
        string $refundKey,
        string $purchaseSettlementPublicId,
        int $amountIrr,
        string $correlationId,
    ): PurchaseRefundReceipt {
        $this->assertToken($refundKey, 'Wallet purchase refund key', 8, 128);
        $this->assertUlid($purchaseSettlementPublicId, 'Wallet purchase settlement public ID');
        if ($amountIrr < 1) {
            throw new DomainException('Wallet purchase refund amount must be positive integer IRR.');
        }
        $this->assertToken($correlationId, 'Wallet purchase refund correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $refundKey,
            $purchaseSettlementPublicId,
            $amountIrr,
            $correlationId,
        ): PurchaseRefundReceipt {
            /** @var object{
             *     settlement_id:int|string,
             *     intent_id:int|string,
             *     intent_public_id:string,
             *     user_id:int|string,
             *     provider_code:string,
             *     payment_method_code:string,
             *     intent_wallet_account_id:int|string|null,
             *     intent_amount_irr:int|string,
             *     settlement_amount_irr:int|string,
             *     currency:string,
             *     wallet_account_id:int|string,
             *     wallet_hold_id:int|string,
             *     hold_amount_irr:int|string,
             *     hold_status:string,
             *     captured_ledger_transaction_id:int|string|null
             * }|null $authority
             */
            $authority = $connection->table('purchase_settlements as settlement')
                ->join('payment_intents as intent', 'intent.id', '=', 'settlement.payment_intent_id')
                ->join('purchase_wallet_reservations as reservation', 'reservation.payment_intent_id', '=', 'intent.id')
                ->join('wallet_holds as hold', 'hold.id', '=', 'reservation.wallet_hold_id')
                ->where('settlement.public_id', $purchaseSettlementPublicId)
                ->lockForUpdate()
                ->first([
                    'settlement.id as settlement_id',
                    'intent.id as intent_id',
                    'intent.public_id as intent_public_id',
                    'intent.user_id',
                    'intent.provider_code',
                    'intent.payment_method_code',
                    'intent.wallet_account_id as intent_wallet_account_id',
                    'intent.amount_irr as intent_amount_irr',
                    'settlement.amount_irr as settlement_amount_irr',
                    'settlement.currency',
                    'reservation.wallet_account_id',
                    'reservation.wallet_hold_id',
                    'hold.amount_irr as hold_amount_irr',
                    'hold.status as hold_status',
                    'hold.captured_ledger_transaction_id',
                ]);
            if ($authority === null) {
                throw new DomainException('Authoritative wallet purchase settlement does not exist.');
            }
            if ($authority->provider_code !== self::PROVIDER_CODE
                || $authority->payment_method_code !== self::PROVIDER_CODE
                || $authority->intent_wallet_account_id !== null
                || $authority->currency !== 'IRR'
                || (int) $authority->intent_amount_irr !== (int) $authority->settlement_amount_irr
                || (int) $authority->hold_amount_irr !== (int) $authority->settlement_amount_irr
                || $authority->hold_status !== WalletHoldStatus::Captured->value
                || $authority->captured_ledger_transaction_id === null) {
                throw new RuntimeException('Wallet purchase refund authority is inconsistent.');
            }

            $ledgerSourceId = $this->ledgerSourceId(
                $refundKey,
                (int) $authority->settlement_id,
                (int) $authority->intent_id,
                (int) $authority->wallet_hold_id,
                (int) $authority->captured_ledger_transaction_id,
            );
            $offsetAccountId = $this->purchaseOffsetAccountId($connection);
            $refundMoney = IrrMoney::positive($amountIrr);
            $ledgerReceipt = $this->ledger->post(
                $this->ledgerCommandKey($refundKey),
                self::LEDGER_TRANSACTION_TYPE,
                $correlationId,
                [
                    new LedgerEntryDraft($offsetAccountId, LedgerDirection::Debit, $refundMoney),
                    new LedgerEntryDraft((int) $authority->wallet_account_id, LedgerDirection::Credit, $refundMoney),
                ],
                self::LEDGER_SOURCE_TYPE,
                $ledgerSourceId,
            );

            /** @var object{id:int|string, finalized_at:string|null}|null $ledgerTransaction */
            $ledgerTransaction = $connection->table('ledger_transactions')
                ->where('id', $ledgerReceipt->transactionId)
                ->first(['id', 'finalized_at']);
            if ($ledgerTransaction === null || $ledgerTransaction->finalized_at === null) {
                throw new RuntimeException('Wallet purchase refund ledger transaction is not finalized.');
            }

            $occurredAt = $this->storedDateTime((string) $ledgerTransaction->finalized_at);
            $providerRefundId = hash('sha256', "wallet\0refund-ledger:".$ledgerReceipt->transactionId);
            $providerEventId = hash('sha256', "wallet\0refund:".$refundKey."\0ledger:".$ledgerReceipt->transactionId);
            $payloadHash = hash('sha256', json_encode([
                'refund_key' => $refundKey,
                'purchase_settlement_public_id' => $purchaseSettlementPublicId,
                'payment_intent_public_id' => $authority->intent_public_id,
                'wallet_account_id' => (int) $authority->wallet_account_id,
                'wallet_hold_id' => (int) $authority->wallet_hold_id,
                'capture_ledger_transaction_id' => (int) $authority->captured_ledger_transaction_id,
                'refund_ledger_source_id' => $ledgerSourceId,
                'ledger_transaction_id' => $ledgerReceipt->transactionId,
                'amount_irr' => $amountIrr,
                'currency' => 'IRR',
            ], JSON_THROW_ON_ERROR));
            $verified = new VerifiedPaymentEvent(
                $providerEventId,
                $payloadHash,
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Refunded,
                    $providerRefundId,
                    $providerEventId,
                    Money::irr($amountIrr),
                    $occurredAt,
                    $occurredAt,
                    $payloadHash,
                    [
                        'purchase_settlement' => $purchaseSettlementPublicId,
                        'payment_intent' => $authority->intent_public_id,
                        'wallet_account_id' => (int) $authority->wallet_account_id,
                        'wallet_hold_id' => (int) $authority->wallet_hold_id,
                        'capture_ledger_transaction_id' => (int) $authority->captured_ledger_transaction_id,
                        'refund_ledger_source_id' => $ledgerSourceId,
                        'ledger_transaction_id' => $ledgerReceipt->transactionId,
                    ],
                ),
            );

            return $this->purchaseRefunds->record(
                $refundKey,
                $purchaseSettlementPublicId,
                self::PROVIDER_CODE,
                $verified,
                $correlationId,
            );
        }, 3);
    }

    private function purchaseOffsetAccountId(Connection $connection): int
    {
        /** @var object{id:int|string, account_class:string, owner_user_id:int|string|null, wallet_bucket:string|null, currency:string, is_active:int|bool}|null $account */
        $account = $connection->table('ledger_accounts')
            ->where('code', WalletSystemAccountCode::PURCHASE_CLEARING)
            ->first(['id', 'account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active']);
        if ($account === null
            || $account->account_class !== 'revenue'
            || $account->owner_user_id !== null
            || $account->wallet_bucket !== null
            || $account->currency !== 'IRR'
            || ! (bool) $account->is_active) {
            throw new RuntimeException('Wallet purchase clearing account is unavailable or invalid.');
        }

        return (int) $account->id;
    }

    private function ledgerSourceId(
        string $refundKey,
        int $settlementId,
        int $intentId,
        int $holdId,
        int $captureLedgerTransactionId,
    ): string {
        return hash('sha256', implode("\0", [
            'wallet-purchase-refund',
            $refundKey,
            'settlement:'.$settlementId,
            'intent:'.$intentId,
            'hold:'.$holdId,
            'capture-ledger:'.$captureLedgerTransactionId,
        ]));
    }

    private function ledgerCommandKey(string $refundKey): string
    {
        return 'wallet.purchase.refund.'.hash('sha256', $refundKey);
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function assertUlid(string $value, string $label): void
    {
        if (! Str::isUlid($value)) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $min, int $max): void
    {
        $length = strlen($value);
        if ($length < $min || $length > $max || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }
}

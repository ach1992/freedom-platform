<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Orders\Application\PurchaseOrderReceipt;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Wallet\Application\WalletHoldReceipt;
use App\Modules\Wallet\Application\WalletHoldService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\WalletHoldStatus;
use App\Modules\Wallet\Domain\WalletSystemAccountCode;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final readonly class PurchaseWalletPaymentService
{
    private const METHOD_CODE = 'wallet';

    private const HOLD_PREFIX = 'wallet.purchase.';

    private const EXPIRY_RELEASE_REASON = 'wallet purchase expired';

    private const CANCEL_RELEASE_REASON = 'wallet purchase canceled';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private PurchasePaymentIntentService $paymentIntents,
        private WalletHoldService $walletHolds,
        private PurchaseSettlementService $purchaseSettlements,
        private PurchaseOrderService $orders,
    ) {}

    /** @requirement BUY-002 PAY-001 PAY-002 PAY-003 WAL-001 WAL-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function reserve(
        string $creationKey,
        int $userId,
        int $walletAccountId,
        string $sourceQuotePublicId,
        string $eligibilityDecisionPublicId,
        string $correlationId,
    ): PurchasePaymentIntentReceipt {
        if ($walletAccountId < 1) {
            throw new DomainException('Wallet purchase account ID is invalid.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $creationKey,
            $userId,
            $walletAccountId,
            $sourceQuotePublicId,
            $eligibilityDecisionPublicId,
            $correlationId,
        ): PurchasePaymentIntentReceipt {
            $intent = $this->paymentIntents->create(
                $creationKey,
                $userId,
                $sourceQuotePublicId,
                $eligibilityDecisionPublicId,
                self::METHOD_CODE,
                $correlationId,
            );

            $intentRow = $connection->table('payment_intents')
                ->where('public_id', $intent->intentPublicId)
                ->lockForUpdate()
                ->first([
                    'id', 'public_id', 'purpose', 'user_id', 'wallet_account_id', 'source_quote_id',
                    'provider_code', 'payment_method_code', 'amount_irr', 'currency', 'state',
                ]);
            if ($intentRow === null
                || $intentRow->purpose !== 'purchase'
                || (int) $intentRow->user_id !== $userId
                || $intentRow->wallet_account_id !== null
                || $intentRow->provider_code !== self::METHOD_CODE
                || $intentRow->payment_method_code !== self::METHOD_CODE
                || $intentRow->currency !== 'IRR') {
                throw new RuntimeException('Wallet purchase intent authority is inconsistent.');
            }

            $quote = $connection->table('quotes')
                ->where('id', (int) $intentRow->source_quote_id)
                ->first(['expires_at']);
            if ($quote === null) {
                throw new RuntimeException('Wallet purchase Quote authority disappeared.');
            }

            $hold = $this->walletHolds->place(
                $this->holdKey((string) $intentRow->public_id),
                $userId,
                $walletAccountId,
                IrrMoney::positive((int) $intentRow->amount_irr),
                'payment_intent',
                (string) $intentRow->public_id,
                $this->storedDateTime((string) $quote->expires_at),
            );

            $existing = $connection->table('purchase_wallet_reservations')
                ->where('payment_intent_id', (int) $intentRow->id)
                ->lockForUpdate()
                ->first(['wallet_account_id', 'wallet_hold_id']);
            if ($existing !== null) {
                if ((int) $existing->wallet_account_id !== $walletAccountId
                    || (int) $existing->wallet_hold_id !== $hold->holdId) {
                    throw new RuntimeException('Wallet purchase reservation replay conflicts with accepted authority.');
                }

                return $intent;
            }

            $connection->table('purchase_wallet_reservations')->insert([
                'public_id' => (string) Str::ulid(),
                'payment_intent_id' => (int) $intentRow->id,
                'wallet_account_id' => $walletAccountId,
                'wallet_hold_id' => $hold->holdId,
                'created_at' => now('UTC'),
            ]);

            return $intent;
        }, 3);
    }

    /** @requirement BUY-002 PAY-002 PAY-003 WAL-001 WAL-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function capture(string $paymentIntentPublicId, string $correlationId): PurchaseOrderReceipt
    {
        $this->assertUlid($paymentIntentPublicId, 'Wallet purchase payment intent public ID');
        $this->assertToken($correlationId, 'Wallet purchase correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use ($paymentIntentPublicId, $correlationId): PurchaseOrderReceipt {
            $authority = $this->authority($connection, $paymentIntentPublicId, true);
            if ($authority === null) {
                throw new DomainException('Wallet purchase reservation does not exist.');
            }

            $existingSettlement = $connection->table('purchase_settlements')
                ->where('payment_intent_id', (int) $authority->intent_id)
                ->first(['public_id']);
            if ($existingSettlement !== null) {
                if ($authority->hold_status !== WalletHoldStatus::Captured->value
                    || $authority->captured_ledger_transaction_id === null) {
                    throw new RuntimeException('Wallet purchase settlement exists without captured hold authority.');
                }

                return $this->orders->createFromSettlement((string) $existingSettlement->public_id, $correlationId);
            }

            if ($authority->intent_state === PaymentIntentState::AwaitingUserAction->value) {
                $this->transitionIntent(
                    $connection,
                    (int) $authority->intent_id,
                    PaymentIntentState::AwaitingUserAction,
                    PaymentIntentState::Submitted,
                    'wallet_purchase_capture_requested',
                    $correlationId,
                );
            } elseif ($authority->intent_state !== PaymentIntentState::Submitted->value) {
                throw new RuntimeException('Wallet purchase intent is not in a capturable state.');
            }

            $offsetAccountId = $this->purchaseOffsetAccountId($connection);
            $hold = $this->walletHolds->capture(
                (string) $authority->hold_key,
                $offsetAccountId,
                $correlationId,
            );
            if ($hold->status !== WalletHoldStatus::Captured || $hold->capturedLedgerTransactionId === null) {
                throw new RuntimeException('Wallet purchase hold did not produce captured ledger authority.');
            }

            $ledger = $connection->table('ledger_transactions')
                ->where('id', $hold->capturedLedgerTransactionId)
                ->first(['id', 'finalized_at']);
            if ($ledger === null || $ledger->finalized_at === null) {
                throw new RuntimeException('Wallet purchase ledger transaction is not finalized.');
            }

            $settledAt = $this->storedDateTime((string) $ledger->finalized_at);
            $providerTransactionId = hash('sha256', "wallet\0ledger:".(int) $ledger->id);
            $providerEventId = hash('sha256', "wallet\0hold:".$hold->holdId."\0ledger:".(int) $ledger->id);
            $payloadHash = hash('sha256', json_encode([
                'payment_intent_public_id' => $paymentIntentPublicId,
                'wallet_hold_id' => $hold->holdId,
                'ledger_transaction_id' => (int) $ledger->id,
                'amount_irr' => (int) $authority->amount_irr,
                'currency' => 'IRR',
            ], JSON_THROW_ON_ERROR));

            $verified = new VerifiedPaymentEvent(
                $providerEventId,
                $payloadHash,
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Settled,
                    $providerTransactionId,
                    $providerEventId,
                    Money::irr((int) $authority->amount_irr),
                    $settledAt,
                    $settledAt,
                    $payloadHash,
                    [
                        'payment_intent' => $paymentIntentPublicId,
                        'wallet_hold_id' => $hold->holdId,
                        'ledger_transaction_id' => (int) $ledger->id,
                    ],
                ),
            );

            $settlement = $this->purchaseSettlements->capture(
                $paymentIntentPublicId,
                self::METHOD_CODE,
                $verified,
                $correlationId,
            );

            return $this->orders->createFromSettlement($settlement->settlementPublicId, $correlationId);
        }, 3);
    }

    /** @requirement PAY-002 WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function release(string $paymentIntentPublicId, string $reason): WalletHoldReceipt
    {
        $this->assertUlid($paymentIntentPublicId, 'Wallet purchase payment intent public ID');

        return $this->database->connection()->transaction(function (Connection $connection) use ($paymentIntentPublicId, $reason): WalletHoldReceipt {
            $authority = $this->authority($connection, $paymentIntentPublicId, true);
            if ($authority === null) {
                throw new DomainException('Wallet purchase reservation does not exist.');
            }
            if ($connection->table('purchase_settlements')->where('payment_intent_id', (int) $authority->intent_id)->exists()) {
                throw new RuntimeException('Settled wallet purchase cannot release its captured hold.');
            }

            return $this->walletHolds->release((string) $authority->hold_key, $reason);
        }, 3);
    }

    /** @requirement PAY-001 PAY-002 WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function expire(string $paymentIntentPublicId, string $correlationId): WalletHoldReceipt
    {
        $this->assertUlid($paymentIntentPublicId, 'Wallet purchase payment intent public ID');
        $this->assertToken($correlationId, 'Wallet purchase expiry correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use ($paymentIntentPublicId, $correlationId): WalletHoldReceipt {
            $authority = $this->terminalAuthority($connection, $paymentIntentPublicId);
            if ($this->storedDateTime((string) $authority->hold_expires_at) > $this->clock->now()) {
                throw new DomainException('Wallet purchase reservation has not expired.');
            }

            return $this->terminalize(
                $connection,
                $authority,
                PaymentIntentState::Expired,
                self::EXPIRY_RELEASE_REASON,
                'wallet_purchase_expired',
                $correlationId,
            );
        }, 3);
    }

    /** @requirement PAY-001 PAY-002 WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function cancel(string $paymentIntentPublicId, string $correlationId): WalletHoldReceipt
    {
        $this->assertUlid($paymentIntentPublicId, 'Wallet purchase payment intent public ID');
        $this->assertToken($correlationId, 'Wallet purchase cancellation correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use ($paymentIntentPublicId, $correlationId): WalletHoldReceipt {
            return $this->terminalize(
                $connection,
                $this->terminalAuthority($connection, $paymentIntentPublicId),
                PaymentIntentState::Canceled,
                self::CANCEL_RELEASE_REASON,
                'wallet_purchase_canceled',
                $correlationId,
            );
        }, 3);
    }

    private function terminalAuthority(Connection $connection, string $paymentIntentPublicId): stdClass
    {
        $authority = $this->authority($connection, $paymentIntentPublicId, true);
        if ($authority === null) {
            throw new DomainException('Wallet purchase reservation does not exist.');
        }
        if ($connection->table('purchase_settlements')->where('payment_intent_id', (int) $authority->intent_id)->exists()) {
            throw new RuntimeException('Settled wallet purchase cannot enter a pre-capture terminal state.');
        }

        return $authority;
    }

    private function terminalize(
        Connection $connection,
        stdClass $authority,
        PaymentIntentState $targetState,
        string $releaseReason,
        string $historyReason,
        string $correlationId,
    ): WalletHoldReceipt {
        if ($authority->intent_state === $targetState->value) {
            if ($authority->hold_status !== WalletHoldStatus::Released->value) {
                throw new RuntimeException('Terminal wallet purchase intent requires a released hold.');
            }

            return $this->releasedReceipt($authority, true);
        }
        if ($authority->intent_state !== PaymentIntentState::AwaitingUserAction->value) {
            throw new RuntimeException('Wallet purchase intent is not in a terminalizable state.');
        }
        if ($authority->hold_status === WalletHoldStatus::Captured->value) {
            throw new RuntimeException('Captured wallet hold cannot enter a pre-capture terminal state.');
        }

        $hold = $authority->hold_status === WalletHoldStatus::Released->value
            ? $this->releasedReceipt($authority, true)
            : $this->walletHolds->release((string) $authority->hold_key, $releaseReason);
        if ($hold->status !== WalletHoldStatus::Released) {
            throw new RuntimeException('Wallet purchase terminal transition did not release its hold.');
        }

        $this->transitionIntent(
            $connection,
            (int) $authority->intent_id,
            PaymentIntentState::AwaitingUserAction,
            $targetState,
            $historyReason,
            $correlationId,
        );

        return $hold;
    }

    private function releasedReceipt(stdClass $authority, bool $replayed): WalletHoldReceipt
    {
        return new WalletHoldReceipt(
            (int) $authority->wallet_hold_id,
            WalletHoldStatus::Released,
            IrrMoney::positive((int) $authority->hold_amount_irr),
            null,
            $replayed,
        );
    }

    private function transitionIntent(
        Connection $connection,
        int $intentId,
        PaymentIntentState $fromState,
        PaymentIntentState $toState,
        string $reasonCode,
        string $correlationId,
    ): void {
        $updated = $connection->table('payment_intents')
            ->where('id', $intentId)
            ->where('state', $fromState->value)
            ->update([
                'state' => $toState->value,
                'updated_at' => now('UTC'),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Wallet purchase intent transition lost its authoritative state.');
        }

        $connection->table('payment_intent_state_histories')->insert([
            'payment_intent_id' => $intentId,
            'from_state' => $fromState->value,
            'to_state' => $toState->value,
            'reason_code' => $reasonCode,
            'correlation_id' => $correlationId,
            'created_at' => now('UTC'),
        ]);
    }

    private function purchaseOffsetAccountId(Connection $connection): int
    {
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

    private function holdKey(string $paymentIntentPublicId): string
    {
        return self::HOLD_PREFIX.$paymentIntentPublicId;
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

    private function authority(Connection $connection, string $paymentIntentPublicId, bool $lock): ?stdClass
    {
        $query = $connection->table('purchase_wallet_reservations as reservation')
            ->join('payment_intents as intent', 'intent.id', '=', 'reservation.payment_intent_id')
            ->join('wallet_holds as hold', 'hold.id', '=', 'reservation.wallet_hold_id')
            ->where('intent.public_id', $paymentIntentPublicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var stdClass|null $row */
        $row = $query->first([
            'reservation.id as reservation_id', 'reservation.wallet_account_id', 'reservation.wallet_hold_id',
            'intent.id as intent_id', 'intent.user_id', 'intent.provider_code', 'intent.payment_method_code',
            'intent.wallet_account_id as intent_wallet_account_id', 'intent.amount_irr', 'intent.currency',
            'intent.state as intent_state',
            'hold.hold_key', 'hold.ledger_account_id', 'hold.amount_irr as hold_amount_irr',
            'hold.source_type', 'hold.source_id', 'hold.status as hold_status', 'hold.expires_at as hold_expires_at',
            'hold.captured_ledger_transaction_id',
        ]);
        if ($row === null) {
            return null;
        }
        if ($row->provider_code !== self::METHOD_CODE
            || $row->payment_method_code !== self::METHOD_CODE
            || $row->intent_wallet_account_id !== null
            || $row->currency !== 'IRR'
            || (int) $row->wallet_account_id !== (int) $row->ledger_account_id
            || (int) $row->amount_irr !== (int) $row->hold_amount_irr
            || $row->source_type !== 'payment_intent'
            || ! hash_equals($paymentIntentPublicId, (string) $row->source_id)) {
            throw new RuntimeException('Stored wallet purchase reservation integrity check failed.');
        }

        return $row;
    }
}

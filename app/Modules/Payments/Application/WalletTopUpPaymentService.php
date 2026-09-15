<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Wallet\Application\LedgerAccountLockSet;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletSystemAccountCode;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type PaymentIntentRow object{
 *     id:int|string,
 *     public_id:string,
 *     creation_key:string,
 *     payload_hash:string,
 *     purpose:string,
 *     user_id:int|string,
 *     wallet_account_id:int|string,
 *     provider_code:string,
 *     amount_irr:int|string,
 *     currency:string,
 *     state:string,
 *     captured_at:string|null
 * }
 * @phpstan-type ProviderEventRow object{
 *     id:int|string,
 *     payment_intent_id:int|string,
 *     provider_code:string,
 *     provider_event_id:string,
 *     event_payload_hash:string,
 *     provider_transaction_id:string,
 *     evidence_payload_hash:string,
 *     evidence_authority:string,
 *     transaction_status:string,
 *     amount_irr:int|string,
 *     currency:string
 * }
 * @phpstan-type ProviderTransactionRow object{
 *     id:int|string,
 *     payment_intent_id:int|string,
 *     provider_event_row_id:int|string,
 *     provider_code:string,
 *     provider_transaction_id:string,
 *     evidence_payload_hash:string,
 *     transaction_status:string,
 *     amount_irr:int|string,
 *     currency:string,
 *     settled_at:string
 * }
 * @phpstan-type TopUpSettlementRow object{
 *     id:int|string,
 *     payment_intent_id:int|string,
 *     provider_transaction_row_id:int|string,
 *     wallet_account_id:int|string,
 *     ledger_transaction_id:int|string,
 *     amount_irr:int|string
 * }
 */
final readonly class WalletTopUpPaymentService
{
    private const PURPOSE = 'wallet_top_up';

    private const LEDGER_TRANSACTION_TYPE = 'wallet_external_top_up';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private LedgerPostingService $ledger,
    ) {}

    /** @requirement PAY-002 WAL-001 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function create(
        string $creationKey,
        int $userId,
        int $walletAccountId,
        string $providerCode,
        Money $amount,
        string $correlationId,
    ): WalletTopUpIntentReceipt {
        $this->assertToken($creationKey, 'Payment intent creation key', 8, 128);
        $this->assertPositiveId($userId, 'Payment intent user ID');
        $this->assertPositiveId($walletAccountId, 'Payment intent wallet account ID');
        $this->assertToken($providerCode, 'Payment provider code', 2, 64);
        $this->assertToken($correlationId, 'Payment intent correlation ID', 8, 64);
        $this->assertPositiveIrrMoney($amount, 'Wallet top-up amount');

        $payloadHash = $this->creationPayloadHash(
            $userId,
            $walletAccountId,
            $providerCode,
            $amount,
        );

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $creationKey,
                $userId,
                $walletAccountId,
                $providerCode,
                $amount,
                $correlationId,
                $payloadHash,
            ): WalletTopUpIntentReceipt {
                $existing = $this->intentByCreationKey($connection, $creationKey, true);
                if ($existing !== null) {
                    return $this->intentReceipt($existing, $payloadHash, true);
                }

                $this->lockCashWallet($connection, $userId, $walletAccountId);

                $existing = $this->intentByCreationKey($connection, $creationKey, true);
                if ($existing !== null) {
                    return $this->intentReceipt($existing, $payloadHash, true);
                }

                $now = $this->timestamp();
                $publicId = (string) Str::ulid();
                $intentId = (int) $connection->table('payment_intents')->insertGetId([
                    'public_id' => $publicId,
                    'creation_key' => $creationKey,
                    'payload_hash' => $payloadHash,
                    'purpose' => self::PURPOSE,
                    'user_id' => $userId,
                    'wallet_account_id' => $walletAccountId,
                    'provider_code' => $providerCode,
                    'amount_irr' => $amount->amount(),
                    'currency' => $amount->currency(),
                    'state' => PaymentIntentState::Created->value,
                    'creation_correlation_id' => $correlationId,
                    'captured_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->recordStateHistory(
                    $connection,
                    $intentId,
                    null,
                    PaymentIntentState::Created,
                    'intent_created',
                    $correlationId,
                );
                $this->transitionIntent(
                    $connection,
                    $intentId,
                    PaymentIntentState::Created,
                    PaymentIntentState::AwaitingUserAction,
                    'awaiting_user_action',
                    $correlationId,
                );

                return new WalletTopUpIntentReceipt(
                    $publicId,
                    PaymentIntentState::AwaitingUserAction,
                    $userId,
                    $walletAccountId,
                    $providerCode,
                    $amount,
                    false,
                );
            });
        } catch (QueryException $exception) {
            $existing = $this->intentByCreationKey(
                $this->database->connection(),
                $creationKey,
            );
            if ($existing !== null) {
                return $this->intentReceipt($existing, $payloadHash, true);
            }

            throw $exception;
        }
    }

    /**
     * @requirement PAY-002 PAY-003 WAL-001 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001
     */
    public function capture(
        string $intentPublicId,
        string $providerCode,
        VerifiedPaymentEvent $event,
        string $correlationId,
    ): WalletTopUpSettlementReceipt {
        $this->assertUlid($intentPublicId, 'Payment intent public ID');
        $this->assertToken($providerCode, 'Payment provider code', 2, 64);
        $this->assertToken($correlationId, 'Payment capture correlation ID', 8, 64);
        $this->validateCaptureEvent($event);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $intentPublicId,
                $providerCode,
                $event,
                $correlationId,
            ): WalletTopUpSettlementReceipt {
                $intent = $this->intentByPublicId($connection, $intentPublicId, true);
                if ($intent === null) {
                    throw new DomainException('Payment intent does not exist.');
                }

                $this->assertCaptureMatchesIntent($intent, $providerCode, $event->evidence);
                $intentId = $this->positiveDatabaseInt($intent->id, 'Payment intent ID');

                $providerEventRow = $this->recordProviderEvent(
                    $connection,
                    $intentId,
                    $providerCode,
                    $event,
                );

                $existingSettlement = $this->settlementByIntent($connection, $intentId);
                if ($existingSettlement !== null) {
                    $this->assertCapturedReplayMatches(
                        $connection,
                        $intent,
                        $existingSettlement,
                        $providerCode,
                        $event->evidence,
                    );

                    return $this->settlementReceipt(
                        $connection,
                        $intent,
                        $existingSettlement,
                        true,
                    );
                }

                $userId = $this->positiveDatabaseInt($intent->user_id, 'Payment intent user ID');
                $walletAccountId = $this->positiveDatabaseInt($intent->wallet_account_id, 'Payment intent wallet account ID');
                $clearingAccountId = $this->lockCaptureAccounts($connection, $userId, $walletAccountId);

                $state = $this->intentState($intent->state);
                if ($state === PaymentIntentState::Captured) {
                    throw new RuntimeException('Captured payment intent is missing its wallet top-up settlement.');
                }
                if (in_array($state, [
                    PaymentIntentState::Failed,
                    PaymentIntentState::Expired,
                    PaymentIntentState::Canceled,
                    PaymentIntentState::RefundPending,
                    PaymentIntentState::Refunded,
                    PaymentIntentState::PartiallyRefunded,
                ], true)) {
                    throw new RuntimeException('Payment intent state cannot accept capture.');
                }

                $providerTransactionRow = $this->recordProviderTransaction(
                    $connection,
                    $intentId,
                    $providerEventRow,
                    $providerCode,
                    $event->evidence,
                );

                $amount = IrrMoney::positive($this->positiveDatabaseInt($intent->amount_irr, 'Payment intent amount'));
                $ledgerReceipt = $this->ledger->post(
                    $this->ledgerCommandKey($intentPublicId),
                    self::LEDGER_TRANSACTION_TYPE,
                    $correlationId,
                    [
                        new LedgerEntryDraft($clearingAccountId, LedgerDirection::Debit, $amount),
                        new LedgerEntryDraft($walletAccountId, LedgerDirection::Credit, $amount),
                    ],
                    'payment_intent',
                    $intentPublicId,
                );

                $attemptKey = hash('sha256', $providerCode."\0".$event->providerEventId);
                $connection->table('payment_attempts')->insert([
                    'payment_intent_id' => $intentId,
                    'attempt_key' => $attemptKey,
                    'provider_code' => $providerCode,
                    'state' => PaymentIntentState::Captured->value,
                    'created_at' => $this->timestamp(),
                ]);

                $this->advanceToCaptured(
                    $connection,
                    $intentId,
                    $state,
                    $correlationId,
                );

                $settlementId = (int) $connection->table('wallet_top_up_settlements')->insertGetId([
                    'payment_intent_id' => $intentId,
                    'provider_transaction_row_id' => $this->positiveDatabaseInt($providerTransactionRow->id, 'Provider transaction row ID'),
                    'wallet_account_id' => $walletAccountId,
                    'ledger_transaction_id' => $ledgerReceipt->transactionId,
                    'amount_irr' => $amount->amount,
                    'created_at' => $this->timestamp(),
                ]);

                $this->recordCaptureAudit(
                    $connection,
                    $intentPublicId,
                    $walletAccountId,
                    $providerCode,
                    $event,
                    $amount,
                    $ledgerReceipt->transactionId,
                    $settlementId,
                    $correlationId,
                );

                return new WalletTopUpSettlementReceipt(
                    $settlementId,
                    $intentPublicId,
                    PaymentIntentState::Captured,
                    $walletAccountId,
                    $providerCode,
                    $event->providerEventId,
                    $event->evidence->providerTransactionId,
                    Money::irr($amount->amount),
                    $ledgerReceipt->transactionId,
                    false,
                );
            });
        } catch (QueryException $exception) {
            $replay = $this->captureReplayAfterUniqueRace(
                $intentPublicId,
                $providerCode,
                $event,
            );
            if ($replay !== null) {
                return $replay;
            }

            throw $exception;
        }
    }

    /** @return PaymentIntentRow|null */
    private function intentByCreationKey(Connection $connection, string $creationKey, bool $lock = false): ?object
    {
        $query = $connection->table('payment_intents')->where('creation_key', $creationKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var PaymentIntentRow|null $row */
        $row = $query->first($this->intentColumns());

        return $row;
    }

    /** @return PaymentIntentRow|null */
    private function intentByPublicId(Connection $connection, string $publicId, bool $lock = false): ?object
    {
        $query = $connection->table('payment_intents')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var PaymentIntentRow|null $row */
        $row = $query->first($this->intentColumns());

        return $row;
    }

    /** @return list<string> */
    private function intentColumns(): array
    {
        return [
            'id', 'public_id', 'creation_key', 'payload_hash', 'purpose', 'user_id',
            'wallet_account_id', 'provider_code', 'amount_irr', 'currency', 'state', 'captured_at',
        ];
    }

    /** @param PaymentIntentRow $row */
    private function intentReceipt(object $row, string $payloadHash, bool $replayed): WalletTopUpIntentReceipt
    {
        if (! hash_equals($row->payload_hash, $payloadHash)) {
            throw new RuntimeException('Payment intent creation key conflict.');
        }
        if ($row->purpose !== self::PURPOSE || $row->currency !== 'IRR') {
            throw new RuntimeException('Stored payment intent is not a valid wallet top-up intent.');
        }

        return new WalletTopUpIntentReceipt(
            $row->public_id,
            $this->intentState($row->state),
            $this->positiveDatabaseInt($row->user_id, 'Payment intent user ID'),
            $this->positiveDatabaseInt($row->wallet_account_id, 'Payment intent wallet account ID'),
            $row->provider_code,
            Money::irr($this->positiveDatabaseInt($row->amount_irr, 'Payment intent amount')),
            $replayed,
        );
    }

    private function lockCashWallet(Connection $connection, int $userId, int $walletAccountId): void
    {
        /** @var object{account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool}|null $account */
        $account = $connection->table('ledger_accounts')
            ->where('id', $walletAccountId)
            ->lockForUpdate()
            ->first(['account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active']);
        if ($account === null
            || $account->account_class !== 'liability'
            || $account->owner_user_id === null
            || (int) $account->owner_user_id !== $userId
            || $account->wallet_bucket !== 'cash'
            || $account->currency !== 'IRR'
            || ! (bool) $account->is_active) {
            throw new DomainException('Wallet top-up target must be an active owned IRR cash wallet.');
        }
    }

    private function lockCaptureAccounts(Connection $connection, int $userId, int $walletAccountId): int
    {
        $clearingAccountId = $this->clearingAccountId($connection);
        $accounts = LedgerAccountLockSet::acquire($connection, [$walletAccountId, $clearingAccountId]);

        $wallet = $accounts[$walletAccountId] ?? null;
        if ($wallet === null
            || $wallet->account_class !== 'liability'
            || $wallet->owner_user_id === null
            || (int) $wallet->owner_user_id !== $userId
            || $wallet->wallet_bucket !== 'cash'
            || $wallet->currency !== 'IRR'
            || ! (bool) $wallet->is_active) {
            throw new DomainException('Wallet top-up target must be an active owned IRR cash wallet.');
        }

        $clearing = $accounts[$clearingAccountId] ?? null;
        if ($clearing === null
            || $clearing->account_class !== 'asset'
            || $clearing->owner_user_id !== null
            || $clearing->wallet_bucket !== null
            || $clearing->currency !== 'IRR'
            || ! (bool) $clearing->is_active) {
            throw new RuntimeException('Wallet top-up clearing account is unavailable or invalid.');
        }

        return $clearingAccountId;
    }

    private function clearingAccountId(Connection $connection): int
    {
        $accountId = $connection->table('ledger_accounts')
            ->where('code', WalletSystemAccountCode::EXTERNAL_TOP_UP_CLEARING)
            ->value('id');
        if (! is_int($accountId) && ! is_string($accountId)) {
            throw new RuntimeException('Wallet top-up clearing account is unavailable or invalid.');
        }

        return $this->positiveDatabaseInt($accountId, 'Wallet top-up clearing account ID');
    }

    private function creationPayloadHash(
        int $userId,
        int $walletAccountId,
        string $providerCode,
        Money $amount,
    ): string {
        return hash('sha256', json_encode([
            'purpose' => self::PURPOSE,
            'user_id' => $userId,
            'wallet_account_id' => $walletAccountId,
            'provider_code' => $providerCode,
            'amount_irr' => $amount->amount(),
            'currency' => $amount->currency(),
        ], JSON_THROW_ON_ERROR));
    }

    private function validateCaptureEvent(VerifiedPaymentEvent $event): void
    {
        $this->assertPrintableIdentifier($event->providerEventId, 'Payment provider event ID', 1, 191);
        $this->assertSha256($event->payloadHash, 'Payment provider event payload hash');
        $this->assertPrintableIdentifier($event->evidence->providerTransactionId, 'Payment provider transaction ID', 1, 191);
        $this->assertSha256($event->evidence->payloadHash, 'Payment evidence payload hash');
        if ($event->evidence->providerEventId !== null
            && ! hash_equals($event->providerEventId, $event->evidence->providerEventId)) {
            throw new DomainException('Verified provider event and payment evidence event IDs do not match.');
        }
        if (! $event->evidence->authorizesCapture()) {
            throw new DomainException('Non-authoritative payment evidence cannot capture a wallet top-up.');
        }
        if ($event->evidence->settledAt === null) {
            throw new DomainException('Authoritative settled payment evidence requires a settlement timestamp.');
        }
        $this->assertPositiveIrrMoney($event->evidence->amount, 'Payment capture amount');
        $this->normalizeSafeEvidence($event->evidence->safeEvidence);
    }

    /** @param PaymentIntentRow $intent */
    private function assertCaptureMatchesIntent(object $intent, string $providerCode, PaymentEvidence $evidence): void
    {
        if ($intent->purpose !== self::PURPOSE) {
            throw new RuntimeException('Payment intent purpose is not wallet top-up.');
        }
        if (! hash_equals($intent->provider_code, $providerCode)) {
            throw new RuntimeException('Payment provider does not match the immutable intent.');
        }
        if ($intent->currency !== $evidence->amount->currency()
            || (int) $intent->amount_irr !== $evidence->amount->amount()) {
            throw new RuntimeException('Authoritative provider evidence amount does not match the payment intent.');
        }
    }

    /** @return ProviderEventRow */
    private function recordProviderEvent(
        Connection $connection,
        int $intentId,
        string $providerCode,
        VerifiedPaymentEvent $event,
    ): object {
        /** @var ProviderEventRow|null $existing */
        $existing = $connection->table('payment_provider_events')
            ->where('provider_code', $providerCode)
            ->where('provider_event_id', $event->providerEventId)
            ->lockForUpdate()
            ->first([
                'id', 'payment_intent_id', 'provider_code', 'provider_event_id', 'event_payload_hash',
                'provider_transaction_id', 'evidence_payload_hash', 'evidence_authority',
                'transaction_status', 'amount_irr', 'currency',
            ]);
        if ($existing !== null) {
            $this->assertProviderEventMatches($existing, $intentId, $event);

            return $existing;
        }

        $evidence = $event->evidence;
        $eventId = (int) $connection->table('payment_provider_events')->insertGetId([
            'payment_intent_id' => $intentId,
            'provider_code' => $providerCode,
            'provider_event_id' => $event->providerEventId,
            'event_payload_hash' => strtolower($event->payloadHash),
            'provider_transaction_id' => $evidence->providerTransactionId,
            'evidence_payload_hash' => strtolower($evidence->payloadHash),
            'evidence_authority' => $evidence->authority->value,
            'transaction_status' => $evidence->status->value,
            'amount_irr' => $evidence->amount->amount(),
            'currency' => $evidence->amount->currency(),
            'occurred_at' => $this->databaseDateTime($evidence->occurredAt),
            'settled_at' => $evidence->settledAt === null ? null : $this->databaseDateTime($evidence->settledAt),
            'safe_evidence' => json_encode($this->normalizeSafeEvidence($evidence->safeEvidence), JSON_THROW_ON_ERROR),
            'created_at' => $this->timestamp(),
        ]);

        /** @var ProviderEventRow|null $row */
        $row = $connection->table('payment_provider_events')
            ->where('id', $eventId)
            ->first([
                'id', 'payment_intent_id', 'provider_code', 'provider_event_id', 'event_payload_hash',
                'provider_transaction_id', 'evidence_payload_hash', 'evidence_authority',
                'transaction_status', 'amount_irr', 'currency',
            ]);
        if ($row === null) {
            throw new RuntimeException('Payment provider event persistence failed.');
        }

        return $row;
    }

    /** @param ProviderEventRow $row */
    private function assertProviderEventMatches(object $row, int $intentId, VerifiedPaymentEvent $event): void
    {
        $evidence = $event->evidence;
        if ((int) $row->payment_intent_id !== $intentId
            || ! hash_equals($row->provider_event_id, $event->providerEventId)
            || ! hash_equals(strtolower($row->event_payload_hash), strtolower($event->payloadHash))
            || ! hash_equals($row->provider_transaction_id, $evidence->providerTransactionId)
            || ! hash_equals(strtolower($row->evidence_payload_hash), strtolower($event->evidence->payloadHash))
            || $row->evidence_authority !== $evidence->authority->value
            || $row->transaction_status !== $evidence->status->value
            || (int) $row->amount_irr !== $evidence->amount->amount()
            || $row->currency !== $evidence->amount->currency()) {
            throw new RuntimeException('Payment provider event replay conflicts with the accepted event.');
        }
    }

    /**
     * @param  ProviderEventRow  $providerEventRow
     * @return ProviderTransactionRow
     */
    private function recordProviderTransaction(
        Connection $connection,
        int $intentId,
        object $providerEventRow,
        string $providerCode,
        PaymentEvidence $evidence,
    ): object {
        /** @var ProviderTransactionRow|null $existing */
        $existing = $connection->table('payment_provider_transactions')
            ->where('provider_code', $providerCode)
            ->where('provider_transaction_id', $evidence->providerTransactionId)
            ->lockForUpdate()
            ->first([
                'id', 'payment_intent_id', 'provider_event_row_id', 'provider_code', 'provider_transaction_id',
                'evidence_payload_hash', 'transaction_status', 'amount_irr', 'currency', 'settled_at',
            ]);
        if ($existing !== null) {
            if ((int) $existing->payment_intent_id !== $intentId
                || (int) $existing->provider_event_row_id !== (int) $providerEventRow->id
                || ! hash_equals(strtolower($existing->evidence_payload_hash), strtolower($evidence->payloadHash))
                || $existing->transaction_status !== $evidence->status->value
                || (int) $existing->amount_irr !== $evidence->amount->amount()
                || $existing->currency !== $evidence->amount->currency()) {
                throw new RuntimeException('Payment provider transaction conflicts with an accepted transaction.');
            }

            return $existing;
        }

        $transactionId = (int) $connection->table('payment_provider_transactions')->insertGetId([
            'payment_intent_id' => $intentId,
            'provider_event_row_id' => $this->positiveDatabaseInt($providerEventRow->id, 'Provider event row ID'),
            'provider_code' => $providerCode,
            'provider_transaction_id' => $evidence->providerTransactionId,
            'evidence_payload_hash' => strtolower($evidence->payloadHash),
            'transaction_status' => $evidence->status->value,
            'amount_irr' => $evidence->amount->amount(),
            'currency' => $evidence->amount->currency(),
            'occurred_at' => $this->databaseDateTime($evidence->occurredAt),
            'settled_at' => $this->databaseDateTime($evidence->settledAt ?? throw new RuntimeException('Payment settlement timestamp is missing.')),
            'created_at' => $this->timestamp(),
        ]);

        /** @var ProviderTransactionRow|null $row */
        $row = $connection->table('payment_provider_transactions')
            ->where('id', $transactionId)
            ->first([
                'id', 'payment_intent_id', 'provider_event_row_id', 'provider_code', 'provider_transaction_id',
                'evidence_payload_hash', 'transaction_status', 'amount_irr', 'currency', 'settled_at',
            ]);
        if ($row === null) {
            throw new RuntimeException('Payment provider transaction persistence failed.');
        }

        return $row;
    }

    /** @return TopUpSettlementRow|null */
    private function settlementByIntent(Connection $connection, int $intentId): ?object
    {
        /** @var TopUpSettlementRow|null $row */
        $row = $connection->table('wallet_top_up_settlements')
            ->where('payment_intent_id', $intentId)
            ->first([
                'id', 'payment_intent_id', 'provider_transaction_row_id', 'wallet_account_id',
                'ledger_transaction_id', 'amount_irr',
            ]);

        return $row;
    }

    /**
     * @param  PaymentIntentRow  $intent
     * @param  TopUpSettlementRow  $settlement
     */
    private function assertCapturedReplayMatches(
        Connection $connection,
        object $intent,
        object $settlement,
        string $providerCode,
        PaymentEvidence $evidence,
    ): void {
        /** @var ProviderTransactionRow|null $providerTransaction */
        $providerTransaction = $connection->table('payment_provider_transactions')
            ->where('id', $settlement->provider_transaction_row_id)
            ->first([
                'id', 'payment_intent_id', 'provider_event_row_id', 'provider_code', 'provider_transaction_id',
                'evidence_payload_hash', 'transaction_status', 'amount_irr', 'currency', 'settled_at',
            ]);
        if ($providerTransaction === null
            || $providerTransaction->provider_code !== $providerCode
            || ! hash_equals($providerTransaction->provider_transaction_id, $evidence->providerTransactionId)
            || (int) $providerTransaction->amount_irr !== $evidence->amount->amount()
            || $providerTransaction->currency !== $evidence->amount->currency()
            || $providerTransaction->transaction_status !== 'settled') {
            throw new RuntimeException('Captured payment replay conflicts with the accepted provider transaction.');
        }
        if ((int) $settlement->payment_intent_id !== (int) $intent->id
            || (int) $settlement->wallet_account_id !== (int) $intent->wallet_account_id
            || (int) $settlement->amount_irr !== (int) $intent->amount_irr) {
            throw new RuntimeException('Stored wallet top-up settlement does not match its payment intent.');
        }
    }

    /**
     * @param  PaymentIntentRow  $intent
     * @param  TopUpSettlementRow  $settlement
     */
    private function settlementReceipt(
        Connection $connection,
        object $intent,
        object $settlement,
        bool $replayed,
    ): WalletTopUpSettlementReceipt {
        /** @var object{provider_code:string,provider_transaction_id:string,provider_event_row_id:int|string,amount_irr:int|string,currency:string}|null $providerTransaction */
        $providerTransaction = $connection->table('payment_provider_transactions')
            ->where('id', $settlement->provider_transaction_row_id)
            ->first(['provider_code', 'provider_transaction_id', 'provider_event_row_id', 'amount_irr', 'currency']);
        if ($providerTransaction === null) {
            throw new RuntimeException('Wallet top-up provider transaction is unavailable.');
        }
        /** @var object{provider_event_id:string}|null $providerEvent */
        $providerEvent = $connection->table('payment_provider_events')
            ->where('id', $providerTransaction->provider_event_row_id)
            ->first(['provider_event_id']);
        if ($providerEvent === null) {
            throw new RuntimeException('Wallet top-up provider event is unavailable.');
        }

        $ledgerTransactionId = $this->positiveDatabaseInt($settlement->ledger_transaction_id, 'Wallet top-up ledger transaction ID');
        /** @var object{transaction_type:string,source_type:string|null,source_id:string|null,expected_total_irr:int|string,posted_debit_irr:int|string,posted_credit_irr:int|string,entry_count:int|string,finalized_at:string|null}|null $ledgerTransaction */
        $ledgerTransaction = $connection->table('ledger_transactions')
            ->where('id', $ledgerTransactionId)
            ->first([
                'transaction_type', 'source_type', 'source_id', 'expected_total_irr',
                'posted_debit_irr', 'posted_credit_irr', 'entry_count', 'finalized_at',
            ]);
        $amount = $this->positiveDatabaseInt($settlement->amount_irr, 'Wallet top-up settlement amount');
        if ($ledgerTransaction === null
            || $ledgerTransaction->transaction_type !== self::LEDGER_TRANSACTION_TYPE
            || $ledgerTransaction->source_type !== 'payment_intent'
            || $ledgerTransaction->source_id !== $intent->public_id
            || $ledgerTransaction->finalized_at === null
            || (int) $ledgerTransaction->expected_total_irr !== $amount
            || (int) $ledgerTransaction->posted_debit_irr !== $amount
            || (int) $ledgerTransaction->posted_credit_irr !== $amount
            || (int) $ledgerTransaction->entry_count !== 2) {
            throw new RuntimeException('Wallet top-up ledger settlement failed integrity verification.');
        }

        if ($intent->captured_at === null) {
            throw new RuntimeException('Wallet top-up intent is missing capture evidence.');
        }

        return new WalletTopUpSettlementReceipt(
            $this->positiveDatabaseInt($settlement->id, 'Wallet top-up settlement ID'),
            $intent->public_id,
            $this->intentState($intent->state),
            $this->positiveDatabaseInt($settlement->wallet_account_id, 'Wallet top-up wallet account ID'),
            $providerTransaction->provider_code,
            $providerEvent->provider_event_id,
            $providerTransaction->provider_transaction_id,
            Money::irr($amount),
            $ledgerTransactionId,
            $replayed,
        );
    }

    private function advanceToCaptured(
        Connection $connection,
        int $intentId,
        PaymentIntentState $current,
        string $correlationId,
    ): void {
        $path = match ($current) {
            PaymentIntentState::Created => [
                PaymentIntentState::AwaitingUserAction,
                PaymentIntentState::Submitted,
                PaymentIntentState::Verifying,
                PaymentIntentState::Captured,
            ],
            PaymentIntentState::AwaitingUserAction => [
                PaymentIntentState::Submitted,
                PaymentIntentState::Verifying,
                PaymentIntentState::Captured,
            ],
            PaymentIntentState::Submitted => [
                PaymentIntentState::Verifying,
                PaymentIntentState::Captured,
            ],
            PaymentIntentState::Verifying => [PaymentIntentState::Captured],
            PaymentIntentState::PendingManualReview => [
                PaymentIntentState::Verifying,
                PaymentIntentState::Captured,
            ],
            PaymentIntentState::Authorized => [PaymentIntentState::Captured],
            default => throw new RuntimeException('Payment intent cannot transition to captured from its current state.'),
        };

        $from = $current;
        foreach ($path as $to) {
            $this->transitionIntent(
                $connection,
                $intentId,
                $from,
                $to,
                $to === PaymentIntentState::Captured ? 'authoritative_capture' : 'capture_verification',
                $correlationId,
            );
            $from = $to;
        }
    }

    private function transitionIntent(
        Connection $connection,
        int $intentId,
        PaymentIntentState $from,
        PaymentIntentState $to,
        string $reasonCode,
        string $correlationId,
    ): void {
        $from->transitionTo($to);
        $update = [
            'state' => $to->value,
            'updated_at' => $this->timestamp(),
        ];
        if ($to === PaymentIntentState::Captured) {
            $update['captured_at'] = $this->timestamp();
        }

        $updated = $connection->table('payment_intents')
            ->where('id', $intentId)
            ->where('state', $from->value)
            ->update($update);
        if ($updated !== 1) {
            throw new RuntimeException('Payment intent state transition failed.');
        }

        $this->recordStateHistory(
            $connection,
            $intentId,
            $from,
            $to,
            $reasonCode,
            $correlationId,
        );
    }

    private function recordStateHistory(
        Connection $connection,
        int $intentId,
        ?PaymentIntentState $from,
        PaymentIntentState $to,
        string $reasonCode,
        string $correlationId,
    ): void {
        $connection->table('payment_intent_state_histories')->insert([
            'payment_intent_id' => $intentId,
            'from_state' => $from?->value,
            'to_state' => $to->value,
            'reason_code' => $reasonCode,
            'correlation_id' => $correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function recordCaptureAudit(
        Connection $connection,
        string $intentPublicId,
        int $walletAccountId,
        string $providerCode,
        VerifiedPaymentEvent $event,
        IrrMoney $amount,
        int $ledgerTransactionId,
        int $settlementId,
        string $correlationId,
    ): void {
        $connection->table('audit_logs')->insert([
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => 'payment.wallet_top_up.captured',
            'target_type' => 'payment_intent',
            'target_id' => $intentPublicId,
            'before_safe_data' => null,
            'after_safe_data' => json_encode([
                'wallet_account_id' => $walletAccountId,
                'provider_code' => $providerCode,
                'provider_event_id' => $event->providerEventId,
                'provider_transaction_id' => $event->evidence->providerTransactionId,
                'amount_irr' => $amount->amount,
                'settlement_id' => $settlementId,
                'ledger_transaction_id' => $ledgerTransactionId,
            ], JSON_THROW_ON_ERROR),
            'reason_code' => 'authoritative_payment_capture',
            'reason' => null,
            'correlation_id' => $correlationId,
            'request_fingerprint' => null,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function captureReplayAfterUniqueRace(
        string $intentPublicId,
        string $providerCode,
        VerifiedPaymentEvent $event,
    ): ?WalletTopUpSettlementReceipt {
        $connection = $this->database->connection();
        $intent = $this->intentByPublicId($connection, $intentPublicId);
        if ($intent === null) {
            return null;
        }
        $settlement = $this->settlementByIntent(
            $connection,
            $this->positiveDatabaseInt($intent->id, 'Payment intent ID'),
        );
        if ($settlement === null) {
            return null;
        }

        try {
            $this->assertCaptureMatchesIntent($intent, $providerCode, $event->evidence);
            $this->assertCapturedReplayMatches(
                $connection,
                $intent,
                $settlement,
                $providerCode,
                $event->evidence,
            );
        } catch (DomainException|RuntimeException) {
            return null;
        }

        return $this->settlementReceipt($connection, $intent, $settlement, true);
    }

    /**
     * @param  array<string, scalar|null>  $safeEvidence
     * @return array<string, scalar|null>
     */
    private function normalizeSafeEvidence(array $safeEvidence): array
    {
        $normalized = [];
        $forbidden = ['secret', 'token', 'password', 'authorization', 'cookie', 'signature', 'private', 'cvv', 'pan', 'raw_body', 'raw_payload'];
        foreach ($safeEvidence as $key => $value) {
            if (strlen($key) < 1 || strlen($key) > 64 || preg_match('/\A[A-Za-z0-9._:-]+\z/', $key) !== 1) {
                throw new DomainException('Payment safe-evidence key is invalid.');
            }
            $lowerKey = strtolower($key);
            foreach ($forbidden as $needle) {
                if (str_contains($lowerKey, $needle)) {
                    throw new DomainException('Payment safe evidence contains a forbidden sensitive field.');
                }
            }
            if (is_string($value)) {
                if (strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                    throw new DomainException('Payment safe-evidence value is invalid.');
                }
            }
            $normalized[$key] = $value;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function intentState(string $state): PaymentIntentState
    {
        return PaymentIntentState::tryFrom($state)
            ?? throw new RuntimeException('Stored payment intent state is invalid.');
    }

    private function ledgerCommandKey(string $intentPublicId): string
    {
        return 'ledger.wallet-top-up.'.hash('sha256', $intentPublicId);
    }

    private function assertPositiveIrrMoney(Money $money, string $label): void
    {
        if ($money->currency() !== 'IRR' || $money->amount() < 1) {
            throw new DomainException($label.' must be positive integer IRR.');
        }
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new DomainException($label.' must be positive.');
        }
    }

    private function assertUlid(string $value, string $label): void
    {
        if (strlen($value) !== 26 || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertPrintableIdentifier(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || $value !== trim($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertSha256(string $value, string $label): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/i', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function positiveDatabaseInt(int|string $value, string $label): int
    {
        if (is_string($value) && preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}

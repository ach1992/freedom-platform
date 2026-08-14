<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionObservation;
use App\Shared\Application\Clock;
use DateTimeZone;
use DomainException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final readonly class CardToCardBankTransactionService
{
    private const STATUSES = ['pending', 'settled', 'reversed', 'failed'];

    private const INGESTION_METHODS = ['poll', 'webhook', 'manual', 'fake'];

    public function __construct(
        private DatabaseManager $database,
        private Encrypter $encrypter,
        private Clock $clock,
    ) {}

    /** @requirement C2C-003 C2C-004 C2C-005 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-001 QUA-004 */
    public function ingest(
        string $providerCode,
        BankTransactionObservation $observation,
        string $ingestionMethod,
        string $correlationId,
    ): CardToCardBankTransactionReceipt {
        $this->assertToken($providerCode, 'C2C bank provider code', 2, 64);
        $this->assertToken($observation->providerTransactionId, 'C2C provider transaction ID', 1, 128);
        $this->assertToken($observation->providerEventId, 'C2C provider event ID', 1, 128);
        $this->assertToken($correlationId, 'C2C bank transaction correlation ID', 8, 64);
        if (! in_array($observation->status, self::STATUSES, true)) {
            throw new DomainException('C2C bank transaction status is invalid.');
        }
        if (! in_array($ingestionMethod, self::INGESTION_METHODS, true)) {
            throw new DomainException('C2C bank transaction ingestion method is invalid.');
        }
        if ($observation->amountIrr < 1) {
            throw new DomainException('C2C bank transaction amount must be positive integer IRR.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', strtolower($observation->evidencePayloadHash)) !== 1) {
            throw new DomainException('C2C bank transaction evidence hash is invalid.');
        }

        $destinationHash = hash_hmac('sha256', $this->normalizeCardNumber($observation->destinationCardNumber), $this->lookupKey());
        $senderHash = $observation->senderCardNumber === null
            ? null
            : hash_hmac('sha256', $this->normalizeCardNumber($observation->senderCardNumber), $this->lookupKey());
        $senderName = $observation->senderName === null ? null : trim($observation->senderName);
        if ($senderName !== null && ($senderName === '' || mb_strlen($senderName) > 128)) {
            throw new DomainException('C2C sender name is invalid.');
        }
        $reference = $observation->reference === null ? null : trim($observation->reference);
        if ($reference !== null && ($reference === '' || mb_strlen($reference) > 191)) {
            throw new DomainException('C2C bank transaction reference is invalid.');
        }
        $occurredAt = $observation->occurredAt->setTimezone(new DateTimeZone('UTC'));

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $providerCode,
            $observation,
            $ingestionMethod,
            $correlationId,
            $destinationHash,
            $senderHash,
            $senderName,
            $reference,
            $occurredAt,
        ): CardToCardBankTransactionReceipt {
            $destination = $connection->table('c2c_destination_accounts')
                ->where('card_lookup_hash', $destinationHash)
                ->first(['id', 'public_id', 'verification_provider_code']);
            if ($destination === null) {
                throw new DomainException('C2C bank transaction destination is not registered.');
            }
            if ($ingestionMethod !== 'manual'
                && $destination->verification_provider_code !== $providerCode) {
                throw new DomainException('C2C bank transaction provider is not assigned to the destination.');
            }

            $transaction = $connection->table('c2c_bank_transactions')
                ->where('provider_code', $providerCode)
                ->where('provider_transaction_id', $observation->providerTransactionId)
                ->lockForUpdate()
                ->first();
            $observedAt = $this->timestamp();
            if ($transaction === null) {
                $transactionId = (int) $connection->table('c2c_bank_transactions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'provider_code' => $providerCode,
                    'provider_transaction_id' => $observation->providerTransactionId,
                    'c2c_destination_account_id' => (int) $destination->id,
                    'amount_irr' => $observation->amountIrr,
                    'currency' => 'IRR',
                    'status' => 'pending',
                    'occurred_at' => $occurredAt->format('Y-m-d H:i:s.u'),
                    'sender_card_lookup_hash' => $senderHash,
                    'encrypted_sender_name' => $senderName === null ? null : $this->encrypter->encryptString($senderName),
                    'reference' => $reference,
                    'first_evidence_payload_hash' => strtolower($observation->evidencePayloadHash),
                    'first_observed_at' => $observedAt,
                    'last_observed_at' => $observedAt,
                    'created_at' => $observedAt,
                ]);
                $transaction = $connection->table('c2c_bank_transactions')->where('id', $transactionId)->lockForUpdate()->first();
                if ($transaction === null) {
                    throw new RuntimeException('C2C bank transaction persistence failed.');
                }
            } else {
                $this->assertSameIdentity(
                    $transaction,
                    (int) $destination->id,
                    $observation->amountIrr,
                    $occurredAt->format('Y-m-d H:i:s.u'),
                    $senderHash,
                    $reference,
                );
            }

            $event = $connection->table('c2c_bank_transaction_events')
                ->where('c2c_bank_transaction_id', $transaction->id)
                ->where('provider_event_id', $observation->providerEventId)
                ->first();
            if ($event !== null) {
                if ($event->status !== $observation->status
                    || ! hash_equals(strtolower($event->evidence_payload_hash), strtolower($observation->evidencePayloadHash))) {
                    throw new RuntimeException('C2C provider event replay conflicts with accepted evidence.');
                }

                return $this->receipt($connection, $transaction, true);
            }

            try {
                $connection->table('c2c_bank_transaction_events')->insert([
                    'c2c_bank_transaction_id' => (int) $transaction->id,
                    'provider_event_id' => $observation->providerEventId,
                    'status' => $observation->status,
                    'evidence_payload_hash' => strtolower($observation->evidencePayloadHash),
                    'ingestion_method' => $ingestionMethod,
                    'observed_at' => $observedAt,
                    'correlation_id' => $correlationId,
                    'created_at' => $observedAt,
                ]);
            } catch (QueryException $exception) {
                $duplicate = $connection->table('c2c_bank_transaction_events')
                    ->where('c2c_bank_transaction_id', $transaction->id)
                    ->where('provider_event_id', $observation->providerEventId)
                    ->first();
                if ($duplicate === null
                    || $duplicate->status !== $observation->status
                    || ! hash_equals(strtolower($duplicate->evidence_payload_hash), strtolower($observation->evidencePayloadHash))) {
                    throw $exception;
                }

                return $this->receipt($connection, $transaction, true);
            }

            $currentStatus = (string) $transaction->status;
            if ($currentStatus !== $observation->status) {
                $this->assertAllowedTransition($currentStatus, $observation->status);
            }
            $connection->table('c2c_bank_transactions')
                ->where('id', $transaction->id)
                ->update([
                    'status' => $observation->status,
                    'last_observed_at' => $observedAt,
                ]);
            $fresh = $connection->table('c2c_bank_transactions')->where('id', $transaction->id)->first();
            if ($fresh === null) {
                throw new RuntimeException('C2C bank transaction disappeared after observation.');
            }

            return $this->receipt($connection, $fresh, false);
        }, 3);
    }

    private function assertSameIdentity(
        stdClass $transaction,
        int $destinationId,
        int $amountIrr,
        string $occurredAt,
        ?string $senderHash,
        ?string $reference,
    ): void {
        if ((int) $transaction->c2c_destination_account_id !== $destinationId
            || (int) $transaction->amount_irr !== $amountIrr
            || $transaction->currency !== 'IRR'
            || $transaction->occurred_at !== $occurredAt
            || ! $this->nullableHashEquals($transaction->sender_card_lookup_hash, $senderHash)
            || $transaction->reference !== $reference) {
            throw new RuntimeException('C2C provider transaction identity conflicts with accepted evidence.');
        }
    }

    private function assertAllowedTransition(string $from, string $to): void
    {
        $allowed = match ($from) {
            'pending' => ['settled', 'reversed', 'failed'],
            'settled' => ['reversed'],
            'reversed', 'failed' => [],
            default => throw new RuntimeException('Stored C2C bank transaction state is invalid.'),
        };
        if (! in_array($to, $allowed, true)) {
            throw new RuntimeException('C2C bank transaction state transition is invalid.');
        }
    }

    private function receipt(Connection $connection, stdClass $transaction, bool $replayed): CardToCardBankTransactionReceipt
    {
        $destinationPublicId = $connection->table('c2c_destination_accounts')
            ->where('id', $transaction->c2c_destination_account_id)
            ->value('public_id');
        if (! is_string($destinationPublicId)) {
            throw new RuntimeException('C2C destination public ID is unavailable.');
        }

        return new CardToCardBankTransactionReceipt(
            (int) $transaction->id,
            $transaction->public_id,
            $transaction->provider_code,
            $transaction->provider_transaction_id,
            $destinationPublicId,
            (int) $transaction->amount_irr,
            $transaction->status,
            $replayed,
        );
    }

    private function normalizeCardNumber(string $value): string
    {
        $digits = preg_replace('/[\s-]+/', '', trim($value));
        if (! is_string($digits) || preg_match('/\A[0-9]{16}\z/', $digits) !== 1) {
            throw new DomainException('C2C bank card number is invalid.');
        }

        return $digits;
    }

    private function lookupKey(): string
    {
        $key = config('payments.card_to_card.lookup_key');
        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('Card-to-card lookup key is not configured securely.');
        }

        return $key;
    }

    private function nullableHashEquals(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return hash_equals($left, $right);
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}

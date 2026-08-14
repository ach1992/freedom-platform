<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use App\Modules\Payments\Domain\PaymentIntentState;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class UsdtTxidSubmissionService
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement USDT-003 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function submit(
        string $submissionKey,
        string $authorityPublicId,
        int $userId,
        string $txid,
        ?string $privateEvidenceReference,
        ?string $evidenceContentHash,
        string $correlationId,
    ): UsdtTxidSubmissionReceipt {
        $this->assertKey($submissionKey, 'USDT TXID submission key');
        if (! Str::isUlid($authorityPublicId) || $userId < 1) {
            throw new DomainException('USDT TXID submission identity is invalid.');
        }
        $txid = $this->normalizeTxid($txid);
        $privateEvidenceReference = $this->boundedOptional($privateEvidenceReference, 191, 'USDT private evidence reference');
        $evidenceContentHash = $this->normalizeHash($evidenceContentHash, 'USDT evidence content hash');
        if (($privateEvidenceReference === null) !== ($evidenceContentHash === null)) {
            throw new DomainException('USDT private evidence reference and content hash must be supplied together.');
        }
        $this->assertCorrelation($correlationId);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $submissionKey,
                $authorityPublicId,
                $userId,
                $txid,
                $privateEvidenceReference,
                $evidenceContentHash,
                $correlationId,
            ): UsdtTxidSubmissionReceipt {
                $authority = $connection->table('usdt_payment_authorities')->where('public_id', $authorityPublicId)->lockForUpdate()->first();
                if ($authority === null || (int) $authority->user_id !== $userId) {
                    throw new DomainException('USDT payment authority is unavailable for this user.');
                }
                $intent = $connection->table('payment_intents')->where('id', $authority->payment_intent_id)->lockForUpdate()->first();
                if ($intent === null) {
                    throw new RuntimeException('USDT purchase intent authority is unavailable.');
                }

                $payloadHash = hash('sha256', json_encode([
                    'authority_public_id' => $authorityPublicId,
                    'user_id' => $userId,
                    'txid' => $txid,
                    'private_evidence_reference_hash' => $privateEvidenceReference === null ? null : hash('sha256', $privateEvidenceReference),
                    'evidence_content_hash' => $evidenceContentHash,
                ], JSON_THROW_ON_ERROR));

                $existing = $connection->table('usdt_txid_submissions')->where('submission_key', $submissionKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    if ((int) $existing->usdt_payment_authority_id !== (int) $authority->id
                        || (int) $existing->user_id !== $userId
                        || ! hash_equals((string) $existing->txid, $txid)
                        || ! hash_equals(strtolower((string) $existing->request_payload_hash), $payloadHash)) {
                        throw new RuntimeException('USDT TXID submission key conflicts with accepted evidence.');
                    }

                    return $this->receipt($connection, $existing, true);
                }

                $duplicate = $connection->table('usdt_txid_submissions')->where('txid', $txid)->lockForUpdate()->first(['id','usdt_payment_authority_id']);
                if ($duplicate !== null) {
                    throw new RuntimeException('USDT TXID is already bound to another payment authority.');
                }
                if ($intent->state !== PaymentIntentState::AwaitingUserAction->value || $intent->captured_at !== null) {
                    throw new RuntimeException('USDT purchase intent is not awaiting a TXID submission.');
                }

                $now = $this->timestamp();
                $submissionId = (int) $connection->table('usdt_txid_submissions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'submission_key' => $submissionKey,
                    'usdt_payment_authority_id' => (int) $authority->id,
                    'payment_intent_id' => (int) $authority->payment_intent_id,
                    'user_id' => $userId,
                    'txid' => $txid,
                    'private_evidence_reference' => $privateEvidenceReference,
                    'evidence_content_hash' => $evidenceContentHash,
                    'state' => 'submitted',
                    'request_payload_hash' => $payloadHash,
                    'submitted_at' => $now,
                    'created_at' => $now,
                ]);

                PaymentIntentState::AwaitingUserAction->transitionTo(PaymentIntentState::Submitted);
                $updated = $connection->table('payment_intents')
                    ->where('id', $authority->payment_intent_id)
                    ->where('state', PaymentIntentState::AwaitingUserAction->value)
                    ->whereNull('captured_at')
                    ->update(['state' => PaymentIntentState::Submitted->value, 'updated_at' => $now]);
                if ($updated !== 1) {
                    throw new RuntimeException('USDT purchase intent TXID transition changed concurrently.');
                }
                $connection->table('payment_intent_state_histories')->insert([
                    'payment_intent_id' => (int) $authority->payment_intent_id,
                    'from_state' => PaymentIntentState::AwaitingUserAction->value,
                    'to_state' => PaymentIntentState::Submitted->value,
                    'reason_code' => 'usdt_bep20_txid_submitted',
                    'correlation_id' => $correlationId,
                    'created_at' => $now,
                ]);
                $stored = $connection->table('usdt_txid_submissions')->where('id', $submissionId)->first();
                if ($stored === null) {
                    throw new RuntimeException('USDT TXID submission persistence failed.');
                }

                return $this->receipt($connection, $stored, false);
            }, 3);
        } catch (QueryException $exception) {
            $duplicate = $this->database->connection()->table('usdt_txid_submissions')->where('txid', $txid)->first(['id']);
            if ($duplicate !== null) {
                throw new RuntimeException('USDT TXID is already bound to another payment authority.', 0, $exception);
            }
            throw $exception;
        }
    }

    private function receipt(Connection $connection, object $row, bool $replayed): UsdtTxidSubmissionReceipt
    {
        $authority = $connection->table('usdt_payment_authorities')->where('id', $row->usdt_payment_authority_id)->first(['public_id']);
        $intent = $connection->table('payment_intents')->where('id', $row->payment_intent_id)->first(['public_id']);
        if ($authority === null || $intent === null) {
            throw new RuntimeException('USDT TXID submission linked authority is unavailable.');
        }

        return new UsdtTxidSubmissionReceipt(
            (int) $row->id,
            (string) $row->public_id,
            (string) $authority->public_id,
            (string) $intent->public_id,
            (string) $row->txid,
            (string) $row->state,
            $replayed,
        );
    }

    private function normalizeTxid(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/\A0x[a-f0-9]{64}\z/', $value) !== 1) {
            throw new DomainException('USDT TXID must be one lowercase-compatible EVM transaction hash.');
        }
        return $value;
    }

    private function normalizeHash(?string $value, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = strtolower(trim($value));
        if (preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
        return $value;
    }

    private function boundedOptional(?string $value, int $maximum, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (mb_strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new DomainException($label.' is invalid.');
        }
        return $value;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function assertKey(string $value, string $label): void
    {
        if (strlen($value) < 8 || strlen($value) > 128 || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertCorrelation(string $value): void
    {
        if (strlen($value) !== 64 || preg_match('/\A[a-fA-F0-9]{64}\z/', $value) !== 1) {
            throw new DomainException('USDT TXID correlation ID must be a SHA-256 hex digest.');
        }
    }
}

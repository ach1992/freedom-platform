<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use App\Shared\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

final readonly class CardToCardReconciliationService
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @return list<string> finding public IDs */
    public function inspectTransaction(string $transactionPublicId, string $correlationId): array
    {
        if (! Str::isUlid($transactionPublicId) || preg_match('/\A[A-Za-z0-9:_.-]{8,64}\z/', $correlationId) !== 1) {
            throw new \DomainException('C2C reconciliation identity is invalid.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($transactionPublicId, $correlationId): array {
            $transaction = $connection->table('c2c_bank_transactions')
                ->where('public_id', $transactionPublicId)
                ->lockForUpdate()
                ->first();
            if ($transaction === null) {
                throw new \DomainException('C2C bank transaction does not exist for reconciliation.');
            }
            $match = $connection->table('c2c_transaction_matches')
                ->where('c2c_bank_transaction_id', $transaction->id)
                ->first();

            $findings = [];
            if ($transaction->status === 'settled'
                && ($match === null || ($match->state === 'matched' && $match->purchase_settlement_id === null))) {
                $findings[] = $this->finding(
                    $connection,
                    'unlinked_settled',
                    'warning',
                    (int) $transaction->id,
                    $match === null ? null : (int) $match->id,
                    (string) $transaction->provider_code,
                    hash('sha256', implode('|', [
                        (string) $transaction->public_id,
                        (string) $transaction->provider_code,
                        (string) $transaction->provider_transaction_id,
                        (string) $transaction->amount_irr,
                        (string) $transaction->last_observed_at,
                    ])),
                    $correlationId,
                );
            }

            if ($transaction->status === 'reversed' && $match !== null && $match->state === 'captured') {
                $findings[] = $this->finding(
                    $connection,
                    'captured_transaction_reversed',
                    'critical',
                    (int) $transaction->id,
                    (int) $match->id,
                    (string) $transaction->provider_code,
                    hash('sha256', implode('|', [
                        (string) $transaction->public_id,
                        (string) $match->public_id,
                        (string) $match->purchase_settlement_id,
                        (string) $transaction->last_observed_at,
                    ])),
                    $correlationId,
                );
            }

            if ($match !== null && $match->state === 'captured') {
                $settlementExists = $match->purchase_settlement_id !== null
                    && $connection->table('purchase_settlements')
                        ->where('id', $match->purchase_settlement_id)
                        ->where('payment_intent_id', $match->payment_intent_id)
                        ->where('provider_code', 'card_to_card')
                        ->exists();
                if (! $settlementExists) {
                    $findings[] = $this->finding(
                        $connection,
                        'captured_transaction_missing',
                        'critical',
                        (int) $transaction->id,
                        (int) $match->id,
                        (string) $transaction->provider_code,
                        hash('sha256', (string) $match->public_id.'|missing-settlement'),
                        $correlationId,
                    );
                }
            }

            return $findings;
        }, 3);
    }

    public function recordProviderFailure(string $providerCode, string $failureCode, string $correlationId): string
    {
        if (preg_match('/\A[A-Za-z0-9:_.-]{2,64}\z/', $providerCode) !== 1
            || $failureCode === '' || strlen($failureCode) > 191
            || preg_match('/\A[A-Za-z0-9:_.-]{8,64}\z/', $correlationId) !== 1) {
            throw new \DomainException('C2C provider reconciliation failure identity is invalid.');
        }

        return $this->database->connection()->transaction(fn (Connection $connection): string => $this->finding(
            $connection,
            'provider_cursor_failure',
            'warning',
            null,
            null,
            $providerCode,
            hash('sha256', $providerCode.'|'.$failureCode.'|'.$this->clock->now()->format('Y-m-d H:i')),
            $correlationId,
        ), 3);
    }

    private function finding(
        Connection $connection,
        string $type,
        string $severity,
        ?int $transactionId,
        ?int $matchId,
        ?string $providerCode,
        string $evidenceHash,
        string $correlationId,
    ): string {
        $findingKey = hash('sha256', implode('|', [
            $type,
            (string) $transactionId,
            (string) $matchId,
            (string) $providerCode,
            $evidenceHash,
        ]));
        $existing = $connection->table('c2c_reconciliation_findings')->where('finding_key', $findingKey)->first(['public_id']);
        if ($existing !== null) {
            return (string) $existing->public_id;
        }

        $publicId = (string) Str::ulid();
        try {
            $connection->table('c2c_reconciliation_findings')->insert([
                'public_id' => $publicId,
                'finding_key' => $findingKey,
                'c2c_bank_transaction_id' => $transactionId,
                'c2c_transaction_match_id' => $matchId,
                'provider_code' => $providerCode,
                'finding_type' => $type,
                'severity' => $severity,
                'evidence_hash' => strtolower($evidenceHash),
                'correlation_id' => $correlationId,
                'detected_at' => $this->timestamp(),
                'created_at' => $this->timestamp(),
            ]);
        } catch (QueryException $exception) {
            $raced = $connection->table('c2c_reconciliation_findings')->where('finding_key', $findingKey)->first(['public_id']);
            if ($raced === null) {
                throw $exception;
            }

            return (string) $raced->public_id;
        }

        return $publicId;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Operations\Application\Contracts\PaymentOperationsSnapshotSource;
use App\Modules\Operations\Application\OperationsCenterFact;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Throwable;

final readonly class DatabasePaymentOperationsSnapshotSource implements PaymentOperationsSnapshotSource
{
    private const UTC = 'UTC';

    private const MAX_DIMENSION_FACTS = 20;

    public function __construct(private DatabaseManager $database) {}

    public function facts(DateTimeImmutable $now): array
    {
        return [
            ...$this->paymentHealthFacts($now),
            ...$this->cardToCardFacts(),
            ...$this->giftCardFacts(),
        ];
    }

    /** @return list<OperationsCenterFact> */
    private function paymentHealthFacts(DateTimeImmutable $now): array
    {
        $connection = $this->database->connection();
        $methodCount = (int) $connection->table('payment_method_health_observations')
            ->distinct()
            ->count('method_code');

        /** @var list<string> $methodCodes */
        $methodCodes = $connection->table('payment_method_health_observations')
            ->distinct()
            ->orderBy('method_code')
            ->limit(self::MAX_DIMENSION_FACTS)
            ->pluck('method_code')
            ->filter(static fn (mixed $code): bool => is_string($code) && $code !== '')
            ->values()
            ->all();

        $facts = [];
        foreach ($methodCodes as $methodCode) {
            /** @var object{method_code:string,healthy:int|bool,observed_at:string,expires_at:string}|null $row */
            $row = $connection->table('payment_method_health_observations')
                ->where('method_code', $methodCode)
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->first(['method_code', 'healthy', 'observed_at', 'expires_at']);
            if ($row === null) {
                continue;
            }

            $code = $this->safeCode($row->method_code);
            $observed = $this->date($row->observed_at);
            $expires = $this->date($row->expires_at);
            $expired = $expires === null || $expires <= $now;
            $healthy = (bool) $row->healthy;

            $facts[] = new OperationsCenterFact(
                'payments',
                'payments.health.'.$code,
                $expired ? 'unknown' : ($healthy ? 'healthy' : 'degraded'),
                $healthy && ! $expired ? 1 : 0,
                $expired ? 'observation_expired' : null,
                $observed,
            );
        }

        if ($methodCount > self::MAX_DIMENSION_FACTS) {
            $facts[] = new OperationsCenterFact(
                'payments',
                'payments.health.truncated',
                'unknown',
                $methodCount - self::MAX_DIMENSION_FACTS,
                'methods='.$methodCount.';visible='.self::MAX_DIMENSION_FACTS.';truncated=1',
            );
        }

        if ($facts === []) {
            $facts[] = new OperationsCenterFact(
                'payments',
                'payments.health',
                'unknown',
                0,
                'no_authoritative_health_observation',
            );
        }

        return $facts;
    }

    /** @return list<OperationsCenterFact> */
    private function cardToCardFacts(): array
    {
        $connection = $this->database->connection();
        $providerCount = (int) $connection->table('c2c_provider_cursors')->count();

        /** @var list<object{provider_code:string,last_success_at:?string,last_failure_at:?string,last_failure_code:?string}> $rows */
        $rows = $connection->table('c2c_provider_cursors')
            ->orderBy('provider_code')
            ->limit(self::MAX_DIMENSION_FACTS)
            ->get(['provider_code', 'last_success_at', 'last_failure_at', 'last_failure_code'])
            ->all();

        $facts = [];
        foreach ($rows as $row) {
            $provider = $this->safeCode($row->provider_code);
            $success = $this->date($row->last_success_at);
            $failure = $this->date($row->last_failure_at);
            $state = $success === null && $failure === null
                ? 'unknown'
                : ($failure !== null && ($success === null || $failure > $success) ? 'degraded' : 'observed');
            $observed = $success === null || ($failure !== null && $failure > $success) ? $failure : $success;
            $detail = $row->last_failure_code === null
                ? null
                : 'last_failure_code='.mb_substr($row->last_failure_code, 0, 64);

            $facts[] = new OperationsCenterFact(
                'payments',
                'payments.c2c_provider.'.$provider,
                $state,
                $state === 'degraded' ? 0 : 1,
                $detail,
                $observed,
            );
        }

        if ($providerCount > self::MAX_DIMENSION_FACTS) {
            $facts[] = new OperationsCenterFact(
                'payments',
                'payments.c2c_provider.truncated',
                'unknown',
                $providerCount - self::MAX_DIMENSION_FACTS,
                'providers='.$providerCount.';visible='.self::MAX_DIMENSION_FACTS.';truncated=1',
            );
        }

        $unmatched = (int) $connection->table('c2c_bank_transactions as bank')
            ->leftJoin('c2c_transaction_matches as matches', 'matches.c2c_bank_transaction_id', '=', 'bank.id')
            ->leftJoin('c2c_match_reviews as reviews', 'reviews.c2c_bank_transaction_id', '=', 'bank.id')
            ->where('bank.status', 'settled')
            ->whereNull('matches.id')
            ->whereNull('reviews.id')
            ->count('bank.id');
        $facts[] = new OperationsCenterFact(
            'payments',
            'payments.c2c_unmatched_settled',
            $unmatched === 0 ? 'empty' : 'manual_review',
            $unmatched,
        );

        if ($providerCount === 0) {
            $facts[] = new OperationsCenterFact(
                'payments',
                'payments.c2c_provider_health',
                'unknown',
                0,
                'no_cursor_observation',
            );
        }

        return $facts;
    }

    /** @return list<OperationsCenterFact> */
    private function giftCardFacts(): array
    {
        $connection = $this->database->connection();
        $pendingCapture = (int) $connection->table('gift_card_submissions')
            ->whereIn('state', ['valid_unreserved', 'reserved', 'redeeming'])
            ->count();
        $manualReview = (int) $connection->table('gift_card_submissions')
            ->whereIn('state', ['pending_manual_review', 'provider_unavailable'])
            ->count();
        $reconciliation = (int) $connection->table('gift_card_reconciliation_findings')->count();

        return [
            new OperationsCenterFact(
                'payments',
                'payments.gift_card_pending_capture',
                $pendingCapture === 0 ? 'empty' : 'observed',
                $pendingCapture,
            ),
            new OperationsCenterFact(
                'payments',
                'payments.gift_card_manual_review',
                $manualReview === 0 ? 'empty' : 'manual_review',
                $manualReview,
            ),
            new OperationsCenterFact(
                'payments',
                'payments.gift_card_reconciliation_findings',
                $reconciliation === 0 ? 'empty' : 'manual_review',
                $reconciliation,
            ),
        ];
    }

    private function safeCode(string $value): string
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $value) === 1) {
            return $value;
        }

        return 'unknown-'.substr(hash('sha256', $value), 0, 8);
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone(self::UTC)))
                ->setTimezone(new DateTimeZone(self::UTC));
        } catch (Throwable) {
            return null;
        }
    }
}

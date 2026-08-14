<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionVerificationProvider;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

final readonly class CardToCardProviderPollingService
{
    public function __construct(
        private DatabaseManager $database,
        private CardToCardBankTransactionService $transactions,
        private CardToCardMatchingService $matching,
        private CardToCardSettlementService $settlements,
        private CardToCardReconciliationService $reconciliation,
        private Clock $clock,
    ) {}

    /** @return array{ingested:int,matched:int,captured:int,reviewed:int,next_cursor:?string} */
    public function poll(BankTransactionVerificationProvider $provider, string $correlationId): array
    {
        if (preg_match('/\A[A-Za-z0-9:_.-]{2,64}\z/', $provider->code()) !== 1) {
            throw new DomainException('C2C bank provider code is invalid.');
        }
        if (preg_match('/\A[A-Za-z0-9:_.-]{8,64}\z/', $correlationId) !== 1) {
            throw new DomainException('C2C provider poll correlation ID is invalid.');
        }

        $cursor = $this->database->connection()->table('c2c_provider_cursors')
            ->where('provider_code', $provider->code())
            ->value('cursor');
        if ($cursor !== null && ! is_string($cursor)) {
            throw new RuntimeException('Stored C2C provider cursor is invalid.');
        }

        try {
            $page = $provider->fetch($cursor);
            $ingested = $matched = $captured = $reviewed = 0;
            foreach ($page->transactions as $observation) {
                $receipt = $this->transactions->ingest($provider->code(), $observation, 'poll', $correlationId);
                $ingested++;

                if ($receipt->status === 'settled') {
                    $outcome = $this->matching->match($receipt->publicId, $correlationId);
                    if ($outcome->matchPublicId !== null) {
                        $matched++;
                        $this->settlements->capture($outcome->matchPublicId, $correlationId);
                        $captured++;
                    } elseif ($outcome->reviewId !== null) {
                        $reviewed++;
                    }
                }

                $this->reconciliation->inspectTransaction($receipt->publicId, $correlationId);
            }

            $this->database->connection()->transaction(function (Connection $connection) use ($provider, $page): void {
                $now = $this->timestamp();
                $connection->table('c2c_provider_cursors')->updateOrInsert(
                    ['provider_code' => $provider->code()],
                    [
                        'cursor' => $page->nextCursor,
                        'last_success_at' => $now,
                        'last_failure_at' => null,
                        'last_failure_code' => null,
                        'updated_at' => $now,
                    ],
                );
            });

            return [
                'ingested' => $ingested,
                'matched' => $matched,
                'captured' => $captured,
                'reviewed' => $reviewed,
                'next_cursor' => $page->nextCursor,
            ];
        } catch (Throwable $exception) {
            $failureCode = substr($exception::class, 0, 64);
            $this->database->connection()->table('c2c_provider_cursors')->updateOrInsert(
                ['provider_code' => $provider->code()],
                [
                    'last_failure_at' => $this->timestamp(),
                    'last_failure_code' => $failureCode,
                    'updated_at' => $this->timestamp(),
                ],
            );
            try {
                $this->reconciliation->recordProviderFailure($provider->code(), $failureCode, $correlationId);
            } catch (Throwable) {
                // Preserve the original provider failure. Reconciliation evidence is best-effort here.
            }
            throw $exception;
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}

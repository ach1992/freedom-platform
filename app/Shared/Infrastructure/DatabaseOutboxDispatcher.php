<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Application\Clock;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxDispatchResult;
use App\Shared\Application\OutboxMessage;
use App\Shared\Application\OutboxMessageHandler;
use DateInterval;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use JsonException;
use LogicException;
use Throwable;

final readonly class DatabaseOutboxDispatcher
{
    /** @requirement PAY-003 ARCH-004 OPS-003 QUA-004 */
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private int $leaseSeconds = 60,
        private int $maxAttempts = 5,
    ) {
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new LogicException('Outbox lease duration must be between 1 and 3600 seconds.');
        }

        if ($maxAttempts < 1 || $maxAttempts > 100) {
            throw new LogicException('Outbox maximum attempts must be between 1 and 100.');
        }
    }

    /**
     * Claims and dispatches at most one message. The handler runs after the
     * claim transaction has committed, so adapter/network I/O never holds a
     * long database transaction.
     */
    public function dispatchOne(OutboxMessageHandler $handler): ?OutboxDispatchResult
    {
        $claim = $this->claim();

        if ($claim === null) {
            return null;
        }

        try {
            $outcome = $handler->handle($claim->message);
        } catch (Throwable $exception) {
            $this->moveToReview($claim, 'unexpected_exception', $exception::class, $this->errorCode($exception));

            throw $exception;
        }

        $this->settle($claim, $outcome);

        return new OutboxDispatchResult(
            $claim->message->id,
            $claim->message->eventKey,
            $claim->message->attempt,
            $outcome,
        );
    }

    private function claim(): ?OutboxDispatchClaim
    {
        return $this->database->connection()->transaction(function (): ?OutboxDispatchClaim {
            $now = $this->clock->now();
            $nowString = $this->format($now);

            $row = $this->database->connection()->table('outbox_messages')
                ->whereNull('processed_at')
                ->whereIn('dispatch_state', ['pending', 'retry', 'leased'])
                ->where('available_at', '<=', $nowString)
                ->where(function (Builder $query) use ($nowString): void {
                    $query->whereNull('leased_until')
                        ->orWhere('leased_until', '<=', $nowString);
                })
                ->orderBy('available_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first([
                    'id',
                    'event_key',
                    'event_type',
                    'contract_version',
                    'aggregate_type',
                    'aggregate_id',
                    'payload',
                    'correlation_id',
                    'attempts',
                ]);

            if ($row === null) {
                return null;
            }

            $leaseToken = (string) Str::uuid();
            $attempt = (int) $row->attempts + 1;
            $leasedUntil = $now->add(new DateInterval('PT'.$this->leaseSeconds.'S'));

            $updated = $this->database->connection()->table('outbox_messages')
                ->where('id', $row->id)
                ->update([
                    'dispatch_state' => 'leased',
                    'lease_token' => $leaseToken,
                    'leased_until' => $this->format($leasedUntil),
                    'attempts' => $attempt,
                    'updated_at' => $nowString,
                ]);

            if ($updated !== 1) {
                throw new LogicException('Outbox message claim did not update exactly one record.');
            }

            return new OutboxDispatchClaim(
                new OutboxMessage(
                    (string) $row->id,
                    (string) $row->event_key,
                    (string) $row->event_type,
                    (string) $row->aggregate_type,
                    (string) $row->aggregate_id,
                    $this->decodePayload((string) $row->payload),
                    (string) $row->correlation_id,
                    $attempt,
                    (int) $row->contract_version,
                ),
                $leaseToken,
            );
        });
    }

    private function settle(OutboxDispatchClaim $claim, OutboxDispatchOutcome $outcome): void
    {
        $now = $this->clock->now();
        $fields = match ($outcome) {
            OutboxDispatchOutcome::Success => [
                'dispatch_state' => 'processed',
                'processed_at' => $this->format($now),
                'lease_token' => null,
                'leased_until' => null,
                'review_reason' => null,
                'last_error_class' => null,
                'last_error_code' => null,
            ],
            OutboxDispatchOutcome::RetryableFailure => $claim->message->attempt >= $this->maxAttempts
                ? [
                    'dispatch_state' => 'review_required',
                    'lease_token' => null,
                    'leased_until' => null,
                    'review_reason' => 'retry_exhausted',
                    'last_error_class' => OutboxDispatchOutcome::class,
                    'last_error_code' => $outcome->value,
                ]
                : [
                    'dispatch_state' => 'retry',
                    'available_at' => $this->format($now->add(new DateInterval('PT'.$this->retryDelaySeconds($claim->message->attempt).'S'))),
                    'lease_token' => null,
                    'leased_until' => null,
                    'review_reason' => null,
                    'last_error_class' => OutboxDispatchOutcome::class,
                    'last_error_code' => $outcome->value,
                ],
            OutboxDispatchOutcome::DefinitiveFailure, OutboxDispatchOutcome::UncertainResult => [
                'dispatch_state' => 'review_required',
                'lease_token' => null,
                'leased_until' => null,
                'review_reason' => $outcome->value,
                'last_error_class' => OutboxDispatchOutcome::class,
                'last_error_code' => $outcome->value,
            ],
        };

        $fields['updated_at'] = $this->format($now);

        $this->updateClaim($claim, $fields);
    }

    private function moveToReview(OutboxDispatchClaim $claim, string $reason, string $errorClass, string $errorCode): void
    {
        $this->updateClaim($claim, [
            'dispatch_state' => 'review_required',
            'lease_token' => null,
            'leased_until' => null,
            'review_reason' => $reason,
            'last_error_class' => $errorClass,
            'last_error_code' => $errorCode,
            'updated_at' => $this->format($this->clock->now()),
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function updateClaim(OutboxDispatchClaim $claim, array $fields): void
    {
        $updated = $this->database->connection()->table('outbox_messages')
            ->where('id', $claim->message->id)
            ->where('dispatch_state', 'leased')
            ->where('lease_token', $claim->leaseToken)
            ->update($fields);

        if ($updated !== 1) {
            throw new LogicException('Outbox message lease was lost before transition.');
        }
    }

    /** @return array<string, mixed> */
    private function decodePayload(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LogicException('Outbox payload is not valid JSON.');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new LogicException('Outbox payload must be a JSON object.');
        }

        return $decoded;
    }

    private function retryDelaySeconds(int $attempt): int
    {
        return match (true) {
            $attempt <= 1 => 5,
            $attempt === 2 => 30,
            $attempt === 3 => 120,
            default => 300,
        };
    }

    private function format(\DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.u');
    }

    private function errorCode(Throwable $exception): string
    {
        return substr(hash('sha256', $exception::class), 0, 32);
    }
}

final readonly class OutboxDispatchClaim
{
    public function __construct(
        public OutboxMessage $message,
        public string $leaseToken,
    ) {}
}

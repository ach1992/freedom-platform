<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Application\Clock;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxMessageRouter;
use App\Shared\Application\OutboxRuntime;
use App\Shared\Application\OutboxRuntimeResult;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final readonly class DatabaseOutboxRuntime implements OutboxRuntime
{
    public const MAX_BATCH_SIZE = 1000;

    /** @requirement ARCH-004 OPS-003 QUA-004 SEC-008 */
    public function __construct(
        private DatabaseOutboxDispatcher $dispatcher,
        private OutboxMessageRouter $router,
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function dispatchBatch(int $limit): OutboxRuntimeResult
    {
        if ($limit < 1 || $limit > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('Outbox dispatch limit must be between 1 and '.self::MAX_BATCH_SIZE.'.');
        }

        /** @var array<string, int> $counts */
        $counts = [
            OutboxDispatchOutcome::Success->value => 0,
            OutboxDispatchOutcome::RetryableFailure->value => 0,
            OutboxDispatchOutcome::DefinitiveFailure->value => 0,
            OutboxDispatchOutcome::UncertainResult->value => 0,
        ];
        $examined = 0;

        while ($examined < $limit) {
            $result = $this->dispatcher->dispatchOne($this->router);

            if ($result === null) {
                break;
            }

            $counts[$result->outcome->value]++;
            $examined++;
        }

        $now = $this->clock->now();
        $nowString = $now->format('Y-m-d H:i:s.u');
        $dueBacklog = $this->claimableQuery($nowString)->count();
        $oldestDue = $this->claimableQuery($nowString)->min('available_at');
        $reviewRequired = $this->database->connection()->table('outbox_messages')
            ->whereNull('processed_at')
            ->where('dispatch_state', 'review_required')
            ->count();

        return new OutboxRuntimeResult(
            $examined,
            $counts[OutboxDispatchOutcome::Success->value],
            $counts[OutboxDispatchOutcome::RetryableFailure->value],
            $counts[OutboxDispatchOutcome::DefinitiveFailure->value],
            $counts[OutboxDispatchOutcome::UncertainResult->value],
            $dueBacklog,
            $this->oldestDueAgeSeconds($oldestDue, $now),
            $reviewRequired,
        );
    }

    private function claimableQuery(string $now): Builder
    {
        return $this->database->connection()->table('outbox_messages')
            ->whereNull('processed_at')
            ->whereIn('dispatch_state', ['pending', 'retry', 'leased'])
            ->where('available_at', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('leased_until')
                    ->orWhere('leased_until', '<=', $now);
            });
    }

    private function oldestDueAgeSeconds(mixed $oldestDue, DateTimeImmutable $now): ?int
    {
        if ($oldestDue === null) {
            return null;
        }

        $oldest = new DateTimeImmutable((string) $oldestDue, new DateTimeZone('UTC'));

        return max(0, $now->getTimestamp() - $oldest->getTimestamp());
    }
}

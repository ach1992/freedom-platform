<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Shared\Application\OutboxRuntime;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/** @requirement ARCH-004 OPS-003 QUA-004 SEC-008 */
final class DispatchOutboxCommand extends Command
{
    protected $signature = 'operations:dispatch-outbox
        {--limit=100 : Maximum number of due Outbox messages to examine}
        {--json : Emit JSON only}';

    protected $description = 'Dispatch a bounded batch from the common Transactional Outbox';

    public function handle(OutboxRuntime $runtime): int
    {
        $rawLimit = $this->option('limit');
        $limit = filter_var($rawLimit, FILTER_VALIDATE_INT);

        if ($limit === false) {
            return $this->invalidInput('Outbox dispatch limit must be an integer.');
        }

        try {
            $result = $runtime->dispatchBatch($limit);
        } catch (InvalidArgumentException $exception) {
            return $this->invalidInput($exception->getMessage());
        } catch (Throwable) {
            if ($this->option('json')) {
                $this->line('{"status":"failed","code":"outbox_dispatch_failed"}');
            } else {
                $this->error('Outbox dispatch failed unexpectedly.');
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf(
                'Outbox dispatch %s: examined=%d success=%d retryable=%d definitive=%d uncertain=%d due=%d oldest_due_age_seconds=%s review_required=%d',
                $result->status(),
                $result->examined,
                $result->success,
                $result->retryableFailure,
                $result->definitiveFailure,
                $result->uncertainResult,
                $result->dueBacklog,
                $result->oldestDueAgeSeconds === null ? 'none' : (string) $result->oldestDueAgeSeconds,
                $result->reviewRequired,
            ));
        }

        return self::SUCCESS;
    }

    private function invalidInput(string $message): int
    {
        if ($this->option('json')) {
            $this->line('{"status":"invalid","code":"outbox_dispatch_invalid_input"}');
        } else {
            $this->error($message);
        }

        return self::INVALID;
    }
}

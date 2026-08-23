<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Presentation\Console;

use App\Modules\Provisioning\Application\ServiceNotificationThresholdService;
use DomainException;
use Illuminate\Console\Command;
use RuntimeException;

/** @requirement SVC-013 SVC-014 RUN-004 QUA-004 */
final class ProcessServiceNotificationsCommand extends Command
{
    protected $signature = 'services:notifications {--limit= : Maximum Services to inspect} {--json : Emit machine-readable output}';

    protected $description = 'Process Service notification thresholds and bounded delivery retry state.';

    public function handle(ServiceNotificationThresholdService $service): int
    {
        try {
            $receipt = $service->processBatch($this->limit($this->option('limit')));
        } catch (DomainException|RuntimeException $exception) {
            return $this->failure($exception->getMessage());
        }

        $payload = [
            'status' => $receipt->requiresAttention() ? 'attention_required' : 'ok',
            'candidates' => $receipt->candidates,
            'triggered' => $receipt->triggered,
            'queued' => $receipt->queued,
            'notified' => $receipt->notified,
            'escalated' => $receipt->escalated,
            'expired' => $receipt->expired,
            'skipped' => $receipt->skipped,
        ];
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf(
                'Service notifications: candidates=%d triggered=%d queued=%d notified=%d escalated=%d expired=%d skipped=%d',
                $receipt->candidates,
                $receipt->triggered,
                $receipt->queued,
                $receipt->notified,
                $receipt->escalated,
                $receipt->expired,
                $receipt->skipped,
            ));
        }

        return $receipt->requiresAttention() ? self::FAILURE : self::SUCCESS;
    }

    private function limit(mixed $raw): int
    {
        $value = $raw === null || $raw === '' ? config('service_notifications.batch_limit', 50) : $raw;
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($validated === false) {
            throw new DomainException('Service notification --limit must be an integer between 1 and 500.');
        }

        return (int) $validated;
    }

    private function failure(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(['status' => 'error', 'message' => $message], JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}

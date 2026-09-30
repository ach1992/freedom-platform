<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Console;

use App\Modules\Reporting\Application\ReportScheduleRunner;
use Illuminate\Console\Command;
use Throwable;

/** @requirement REP-003 OPS-003 RUN-003 QUA-004 */
final class RunReportSchedulesCommand extends Command
{
    protected $signature = 'reporting:run-schedules
        {--limit=5 : Maximum number of due report schedules to examine}
        {--json : Emit JSON only}';

    protected $description = 'Queue a bounded batch of due permission-aware report schedules';

    public function handle(ReportScheduleRunner $runner): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 20) {
            return $this->invalidInput('Report schedule limit must be an integer between 1 and 20.');
        }

        try {
            $summary = $runner->runDue($limit);
        } catch (Throwable) {
            if ($this->option('json')) {
                $this->line('{"status":"failed","code":"report_schedule_run_failed"}');
            } else {
                $this->error('Report schedule processing failed unexpectedly.');
            }

            return self::FAILURE;
        }

        $payload = ['status' => 'ok', ...$summary->toArray()];
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf(
                'Report schedules: examined=%d queued=%d retry_scheduled=%d disabled=%d',
                $summary->examined,
                $summary->queued,
                $summary->retryScheduled,
                $summary->disabled,
            ));
        }

        return self::SUCCESS;
    }

    private function invalidInput(string $message): int
    {
        if ($this->option('json')) {
            $this->line('{"status":"invalid","code":"report_schedule_invalid_input"}');
        } else {
            $this->error($message);
        }

        return self::INVALID;
    }
}

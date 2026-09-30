<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Shared\Application\Clock;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class ReportingAudit
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function recordView(
        int $administratorId,
        ReportSnapshot $snapshot,
        string $correlationId,
        string $requestKey,
    ): void {
        $this->record(
            $administratorId,
            'report.view',
            $snapshot->range->code,
            [
                'period' => $snapshot->range->code,
                'starts_at_utc' => $snapshot->range->databaseStart(),
                'ends_before_utc' => $snapshot->range->databaseEndExclusive(),
                'metric_count' => count($snapshot->metrics),
                'report_sha256' => $snapshot->fingerprint(),
            ],
            'report_requested',
            'Permission-aware report generated.',
            $correlationId,
            $requestKey,
        );
    }

    public function recordExport(
        int $administratorId,
        ReportSnapshot $snapshot,
        ReportExportFile $file,
        string $format,
        string $correlationId,
        string $requestKey,
    ): void {
        $this->record(
            $administratorId,
            'report.export',
            $snapshot->range->code,
            [
                'period' => $snapshot->range->code,
                'format' => $format,
                'filename' => $file->filename,
                'bytes' => strlen($file->contents),
                'content_sha256' => $file->sha256(),
                'report_sha256' => $snapshot->fingerprint(),
            ],
            'report_exported',
            'Permission-aware report export generated.',
            $correlationId,
            $requestKey,
        );
    }

    /** @param array<string, bool|int|string|null> $safeData */
    public function recordDelivery(
        int $administratorId,
        string $targetId,
        array $safeData,
        string $correlationId,
        string $requestKey,
    ): void {
        $this->record(
            $administratorId,
            'report.deliver',
            $targetId,
            $safeData,
            'report_delivery_queued',
            'Permission-aware report delivery queued.',
            $correlationId,
            $requestKey,
        );
    }

    /** @param array<string, bool|int|string|null> $safeData */
    public function recordScheduleExecution(
        int $administratorId,
        string $schedulePublicId,
        array $safeData,
        string $correlationId,
        string $requestKey,
    ): void {
        $this->record(
            $administratorId,
            'report.schedule_execute',
            $schedulePublicId,
            $safeData,
            'report_schedule_executed',
            'Permission-aware report schedule execution recorded.',
            $correlationId,
            $requestKey,
        );
    }

    /** @param array<string, bool|int|string|null> $safeData */
    public function recordSchedule(
        int $administratorId,
        string $schedulePublicId,
        array $safeData,
        string $correlationId,
        string $requestKey,
    ): void {
        $this->record(
            $administratorId,
            'report.schedule',
            $schedulePublicId,
            $safeData,
            'report_schedule_changed',
            'Permission-aware report schedule changed.',
            $correlationId,
            $requestKey,
        );
    }

    /** @param array<string, bool|int|string|null> $safeData */
    private function record(
        int $administratorId,
        string $action,
        string $targetId,
        array $safeData,
        string $reasonCode,
        string $reason,
        string $correlationId,
        string $requestKey,
    ): void {
        $this->validateContext($administratorId, $correlationId, $requestKey);
        if (preg_match('/\Areport\.[a-z_]+\z/', $action) !== 1
            || preg_match('/\A[a-zA-Z0-9._:-]{1,128}\z/', $targetId) !== 1
        ) {
            throw new InvalidArgumentException('Reporting audit identity is invalid.');
        }

        $this->database->connection()->table('audit_logs')->insert([
            'actor_type' => 'administrator',
            'actor_id' => (string) $administratorId,
            'action' => $action,
            'target_type' => 'reporting',
            'target_id' => $targetId,
            'before_safe_data' => null,
            'after_safe_data' => json_encode($safeData, JSON_THROW_ON_ERROR),
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'correlation_id' => $correlationId,
            'request_fingerprint' => hash('sha256', $action."\0".$requestKey),
            'created_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function validateContext(int $administratorId, string $correlationId, string $requestKey): void
    {
        if ($administratorId < 1
            || preg_match('/\A[a-zA-Z0-9._:-]{1,64}\z/', $correlationId) !== 1
            || trim($requestKey) === ''
            || strlen($requestKey) > 256
        ) {
            throw new InvalidArgumentException('Reporting audit context is invalid.');
        }
    }
}

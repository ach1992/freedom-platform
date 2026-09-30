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
        $this->validateContext($administratorId, $correlationId, $requestKey);

        $safe = [
            'period' => $snapshot->range->code,
            'starts_at_utc' => $snapshot->range->databaseStart(),
            'ends_before_utc' => $snapshot->range->databaseEndExclusive(),
            'metric_count' => count($snapshot->metrics),
            'report_sha256' => $snapshot->fingerprint(),
        ];

        $this->database->connection()->table('audit_logs')->insert([
            'actor_type' => 'administrator',
            'actor_id' => (string) $administratorId,
            'action' => 'report.view',
            'target_type' => 'report_snapshot',
            'target_id' => $snapshot->range->code,
            'before_safe_data' => null,
            'after_safe_data' => json_encode($safe, JSON_THROW_ON_ERROR),
            'reason_code' => 'report_requested',
            'reason' => 'Permission-aware report generated.',
            'correlation_id' => $correlationId,
            'request_fingerprint' => hash('sha256', "report.view\0".$requestKey),
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

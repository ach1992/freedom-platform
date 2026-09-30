<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Reporting\Application\Contracts\ReportingBroadcastMetricsSource;
use App\Modules\Reporting\Application\ReportDateRange;
use App\Modules\Reporting\Application\ReportMetric;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use RuntimeException;

final readonly class TelegramReportingBroadcastMetricsSource implements ReportingBroadcastMetricsSource
{
    public function __construct(private DatabaseManager $database) {}

    /** @requirement REP-001 REP-002 DAT-002 DAT-003 */
    public function metrics(ReportDateRange $range): array
    {
        $campaigns = $this->database->connection()->table('broadcast_campaigns');
        $this->applyRange($campaigns, 'created_at', $range);

        $sent = $this->database->connection()->table('broadcast_recipients')->whereNotNull('sent_at');
        $this->applyRange($sent, 'sent_at', $range);

        $metrics = [
            new ReportMetric(
                'broadcasts',
                'broadcasts.campaigns_created',
                'Broadcast campaigns created',
                $this->toInt($campaigns->count()),
                'count',
            ),
            new ReportMetric(
                'broadcasts',
                'broadcasts.recipients_sent',
                'Broadcast recipients sent',
                $this->toInt($sent->count()),
                'count',
            ),
        ];

        $failures = $this->database->connection()->table('broadcast_recipient_messages')
            ->select('state')
            ->selectRaw('COUNT(*) AS aggregate')
            ->whereIn('state', ['retryable', 'failed', 'uncertain'])
            ->whereNotNull('provider_boundary_finished_at')
            ->groupBy('state')
            ->orderBy('state');
        $this->applyRange($failures, 'provider_boundary_finished_at', $range);

        $failureTotal = 0;
        /** @var object{state:string,aggregate:int|string} $row */
        foreach ($failures->get() as $row) {
            $count = $this->toInt($row->aggregate);
            $failureTotal += $count;
            $metrics[] = new ReportMetric(
                'broadcasts',
                'broadcasts.delivery_failure_events',
                'Broadcast delivery failure events',
                $count,
                'count',
                $row->state,
            );
        }
        $metrics[] = new ReportMetric(
            'broadcasts',
            'broadcasts.delivery_failure_events_total',
            'Broadcast delivery failure events total',
            $failureTotal,
            'count',
        );

        return $metrics;
    }

    private function applyRange(Builder $query, string $column, ReportDateRange $range): void
    {
        if ($range->startsAtUtc !== null) {
            $query->where($column, '>=', $range->databaseStart());
        }
        $query->where($column, '<', $range->databaseEndExclusive());
    }

    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException('Telegram reporting aggregate is outside the supported integer range.');
    }
}

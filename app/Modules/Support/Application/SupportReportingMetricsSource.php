<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\Reporting\Application\Contracts\ReportingSupportMetricsSource;
use App\Modules\Reporting\Application\ReportDateRange;
use App\Modules\Reporting\Application\ReportMetric;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use RuntimeException;

final readonly class SupportReportingMetricsSource implements ReportingSupportMetricsSource
{
    public function __construct(private DatabaseManager $database) {}

    /** @requirement REP-001 REP-002 DAT-002 DAT-003 */
    public function metrics(ReportDateRange $range): array
    {
        $tickets = $this->database->connection()->table('support_tickets');
        $this->applyRange($tickets, 'created_at', $range);

        $metrics = [
            new ReportMetric(
                'tickets',
                'tickets.created',
                'Support tickets created',
                $this->toInt($tickets->count()),
                'count',
            ),
        ];

        $states = $this->database->connection()->table('support_ticket_state_histories')
            ->select('to_state')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('to_state')
            ->orderBy('to_state')
            ->limit(100);
        $this->applyRange($states, 'created_at', $range);

        /** @var object{to_state:string,aggregate:int|string} $row */
        foreach ($states->get() as $row) {
            $metrics[] = new ReportMetric(
                'tickets',
                'tickets.state_transitions',
                'Ticket state transitions',
                $this->toInt($row->aggregate),
                'count',
                $row->to_state,
            );
        }

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

        throw new RuntimeException('Support reporting aggregate is outside the supported integer range.');
    }
}

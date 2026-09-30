<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Reporting\Application\Contracts\ReportingBroadcastMetricsSource;
use App\Modules\Reporting\Application\Contracts\ReportingSupportMetricsSource;
use App\Shared\Application\Clock;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use RuntimeException;

final readonly class DatabaseReportingSnapshotService
{
    public function __construct(
        private DatabaseManager $database,
        private ReportingBroadcastMetricsSource $broadcasts,
        private ReportingSupportMetricsSource $support,
        private AdministratorUserPermissionAuthorizer $authorizer,
        private ReportMetricVisibilityPolicy $visibility,
        private ReportingAudit $audit,
        private Clock $clock,
    ) {}

    /** @requirement REP-001 REP-002 REP-003 ACL-001 ACL-002 DAT-002 DAT-003 SEC-002 */
    public function generate(
        int $actorUserId,
        ReportDateRange $range,
        string $correlationId,
        string $requestKey,
    ): ReportSnapshot {
        $administratorId = $this->authorizer->authorizeUser($actorUserId, ReportingPermissions::VIEW);
        $current = $this->periodMetrics($actorUserId, $range);
        $priorRange = $range->prior();
        $prior = $priorRange === null
            ? []
            : $this->indexByKey($this->periodMetrics($actorUserId, $priorRange));

        $metrics = [];
        foreach ($current as $metric) {
            $metrics[] = $metric->withPrior($prior[$metric->key()]->value ?? null);
        }

        foreach ($this->pointInTimeMetrics($actorUserId, $range) as $metric) {
            if ($priorRange !== null && $metric->code === 'wallet.liability_irr') {
                $priorLiability = $this->walletLiabilityAt($priorRange->endsBeforeUtc);
                $metric = $metric->withPrior($priorLiability);
            }
            $metrics[] = $metric;
        }

        usort($metrics, static fn (ReportMetric $left, ReportMetric $right): int => [$left->section, $left->code, $left->dimension ?? ''] <=> [$right->section, $right->code, $right->dimension ?? '']);

        $snapshot = new ReportSnapshot(
            $range,
            $priorRange,
            $this->clock->now()->setTimezone(new DateTimeZone('UTC')),
            $metrics,
        );
        $this->audit->recordView($administratorId, $snapshot, $correlationId, $requestKey);

        return $snapshot;
    }

    /** @return list<ReportMetric> */
    private function periodMetrics(int $actorUserId, ReportDateRange $range): array
    {
        $metrics = [];

        if ($this->visibility->allowsMetricCode($actorUserId, 'users.created')) {
            $metrics[] = $this->metric('users', 'users.created', 'Users created', $this->count($this->database->connection()->table('users')->whereIn('account_type', ['customer', 'agent']), 'created_at', $range), 'count');
            foreach ($this->groupedCount(
                $this->database->connection()->table('users')
                    ->whereIn('account_type', ['customer', 'agent'])
                    ->select('account_type')
                    ->selectRaw('COUNT(*) AS aggregate')
                    ->groupBy('account_type')
                    ->orderBy('account_type')
                    ->limit(100),
                'account_type',
                'created_at',
                $range,
            ) as $dimension => $count) {
                $metrics[] = $this->metric('users', 'users.created_by_account_type', 'Users created by account type', $count, 'count', $dimension);
            }
        }

        if ($this->visibility->allowsMetricCode($actorUserId, 'orders.created')) {
            $metrics[] = $this->metric('orders', 'orders.created', 'Orders created', $this->count($this->database->connection()->table('orders'), 'created_at', $range), 'count');
            foreach ($this->groupedCount(
                $this->database->connection()->table('order_state_histories')
                    ->select('to_state')
                    ->selectRaw('COUNT(*) AS aggregate')
                    ->groupBy('to_state')
                    ->orderBy('to_state')
                    ->limit(100),
                'to_state',
                'created_at',
                $range,
            ) as $dimension => $count) {
                $metrics[] = $this->metric('orders', 'orders.state_transitions', 'Order state transitions', $count, 'count', $dimension);
            }
        }

        if ($this->visibility->allowsMetricCode($actorUserId, 'sales.captured_irr')) {
            $capturedCount = $this->count($this->database->connection()->table('purchase_settlements'), 'settled_at', $range);
            $capturedIrr = $this->sum($this->database->connection()->table('purchase_settlements'), 'amount_irr', 'settled_at', $range);
            $discountIrr = $this->discounts($range);
            $refundCount = $this->count($this->database->connection()->table('purchase_refunds'), 'refunded_at', $range);
            $refundIrr = $this->sum($this->database->connection()->table('purchase_refunds'), 'amount_irr', 'refunded_at', $range);
            $metrics[] = $this->metric('financial', 'sales.captured_count', 'Captured purchases', $capturedCount, 'count');
            $metrics[] = $this->metric('financial', 'sales.captured_irr', 'Captured sales', $capturedIrr, 'IRR');
            $metrics[] = $this->metric('financial', 'sales.discount_irr', 'Discounts', $discountIrr, 'IRR');
            $metrics[] = $this->metric('financial', 'sales.gross_irr', 'Gross sales before discounts', $capturedIrr + $discountIrr, 'IRR');
            $metrics[] = $this->metric('financial', 'sales.refund_count', 'Purchase refunds', $refundCount, 'count');
            $metrics[] = $this->metric('financial', 'sales.refund_irr', 'Purchase refunds', $refundIrr, 'IRR');
            $metrics[] = $this->metric('financial', 'sales.net_irr', 'Net captured sales after refunds', $capturedIrr - $refundIrr, 'IRR');
        }

        if ($this->visibility->allowsMetricCode($actorUserId, 'wallet.exact_adjustment_irr')) {
            $metrics[] = $this->metric('financial', 'wallet.exact_adjustment_irr', 'Exact wallet adjustments', $this->walletAdjustments($range), 'IRR');
        }
        if ($this->visibility->allowsMetricCode($actorUserId, 'gateways.captured_count')) {
            array_push($metrics, ...$this->gatewayMetrics($range));
        }
        if ($this->visibility->allowsMetricCode($actorUserId, 'catalog.sold_count')) {
            array_push($metrics, ...$this->catalogMetrics($range));
        }
        if ($this->visibility->allowsMetricCode($actorUserId, 'services.created')) {
            $metrics[] = $this->metric('services', 'services.created', 'Service subscriptions created', $this->count($this->database->connection()->table('service_subscriptions'), 'created_at', $range), 'count');
        }
        if ($this->visibility->allowsMetricCode($actorUserId, 'agents.approved')) {
            $metrics[] = $this->metric('agents', 'agents.approved', 'Agents approved', $this->count($this->database->connection()->table('agent_profiles'), 'approved_at', $range), 'count');
        }
        if ($this->visibility->allowsMetricCode($actorUserId, 'tickets.created')) {
            array_push($metrics, ...$this->support->metrics($range));
        }
        if ($this->visibility->allowsMetricCode($actorUserId, 'referrals.accrued_count')) {
            $metrics[] = $this->metric('referrals', 'referrals.accrued_count', 'Referral reward accruals', $this->count($this->database->connection()->table('referral_reward_accruals'), 'created_at', $range), 'count');
            $metrics[] = $this->metric('referrals', 'referrals.accrued_irr', 'Referral reward accrual amount', $this->sum($this->database->connection()->table('referral_reward_accruals'), 'reward_amount_irr', 'created_at', $range), 'IRR');
            foreach ($this->groupedCount(
                $this->database->connection()->table('referral_reward_accruals')
                    ->select('recipient_policy')
                    ->selectRaw('COUNT(*) AS aggregate')
                    ->groupBy('recipient_policy')
                    ->orderBy('recipient_policy')
                    ->limit(100),
                'recipient_policy',
                'created_at',
                $range,
            ) as $dimension => $count) {
                $metrics[] = $this->metric('referrals', 'referrals.accrued_by_recipient_policy', 'Referral accruals by recipient policy', $count, 'count', $dimension);
            }
        }
        if ($this->visibility->allowsMetricCode($actorUserId, 'broadcasts.campaigns_created')) {
            array_push($metrics, ...$this->broadcasts->metrics($range));
        }
        if ($this->visibility->allowsMetricCode($actorUserId, 'failures.failed_jobs')) {
            $metrics[] = $this->metric('operations', 'failures.failed_jobs', 'Failed queue jobs', $this->count($this->database->connection()->table('failed_jobs'), 'failed_at', $range), 'count');
            $provisioningFailures = $this->provisioningFailureEvents($range);
            $metrics[] = $this->metric('operations', 'failures.provisioning_events_total', 'Provisioning failure/review events total', array_sum($provisioningFailures), 'count');
            foreach ($provisioningFailures as $dimension => $count) {
                $metrics[] = $this->metric('operations', 'failures.provisioning_events', 'Provisioning failure/review events', $count, 'count', $dimension);
            }
        }

        return $metrics;
    }

    /** @return list<ReportMetric> */
    private function pointInTimeMetrics(int $actorUserId, ReportDateRange $range): array
    {
        $metrics = [];
        if ($this->visibility->allowsMetricCode($actorUserId, 'wallet.liability_irr')) {
            $metrics[] = $this->metric('financial', 'wallet.liability_irr', 'Wallet liability as of range end', $this->walletLiabilityAt($range->endsBeforeUtc), 'IRR');
        }
        if ($this->visibility->allowsMetricCode($actorUserId, 'outbox.pending_with_error')) {
            $metrics[] = $this->metric('operations', 'outbox.pending_with_error', 'Pending Outbox messages with an error', $this->toInt($this->database->connection()->table('outbox_messages')->whereNull('processed_at')->whereNotNull('last_error_code')->count()), 'count');
        }
        if ($this->visibility->allowsMetricCode($actorUserId, 'services.current_inventory_by_lifecycle_state')) {
            /** @var list<object{lifecycle_state:string,aggregate:int|string}> $rows */
            $rows = $this->database->connection()->table('service_subscriptions')->select('lifecycle_state')->selectRaw('COUNT(*) AS aggregate')->groupBy('lifecycle_state')->orderBy('lifecycle_state')->limit(100)->get()->all();
            foreach ($rows as $row) {
                $metrics[] = $this->metric('services', 'services.current_inventory_by_lifecycle_state', 'Current service inventory by lifecycle state', $this->toInt($row->aggregate), 'count', $row->lifecycle_state);
            }
        }
        if ($this->visibility->allowsMetricCode($actorUserId, 'agents.current_inventory_by_status')) {
            /** @var list<object{status:string,aggregate:int|string}> $rows */
            $rows = $this->database->connection()->table('agent_profiles')->select('status')->selectRaw('COUNT(*) AS aggregate')->groupBy('status')->orderBy('status')->limit(100)->get()->all();
            foreach ($rows as $row) {
                $metrics[] = $this->metric('agents', 'agents.current_inventory_by_status', 'Current agent inventory by status', $this->toInt($row->aggregate), 'count', $row->status);
            }
        }
        if ($this->visibility->allowsMetricCode($actorUserId, 'panels.current_inventory')) {
            /** @var list<object{provider_type:string,state:string,aggregate:int|string}> $rows */
            $rows = $this->database->connection()->table('panel_connections')->select(['provider_type', 'state'])->selectRaw('COUNT(*) AS aggregate')->groupBy('provider_type', 'state')->orderBy('provider_type')->orderBy('state')->limit(100)->get()->all();
            foreach ($rows as $row) {
                $metrics[] = $this->metric('panels', 'panels.current_inventory', 'Current panel inventory', $this->toInt($row->aggregate), 'count', $row->provider_type.':'.$row->state);
            }
        }

        return $metrics;
    }

    /** @return list<ReportMetric> */
    private function gatewayMetrics(ReportDateRange $range): array
    {
        $query = $this->database->connection()->table('purchase_settlements')
            ->select('provider_code')
            ->selectRaw('COUNT(*) AS aggregate_count, COALESCE(SUM(amount_irr), 0) AS aggregate_irr')
            ->groupBy('provider_code')
            ->orderBy('provider_code')
            ->limit(100);
        $this->applyRange($query, 'settled_at', $range);

        $metrics = [];
        /** @var object{provider_code:string,aggregate_count:int|string,aggregate_irr:int|string} $row */
        foreach ($query->get() as $row) {
            $metrics[] = $this->metric('gateways', 'gateways.captured_count', 'Captured payments by gateway', $this->toInt($row->aggregate_count), 'count', $row->provider_code);
            $metrics[] = $this->metric('gateways', 'gateways.captured_irr', 'Captured amount by gateway', $this->toInt($row->aggregate_irr), 'IRR', $row->provider_code);
        }

        return $metrics;
    }

    /** @return list<ReportMetric> */
    private function catalogMetrics(ReportDateRange $range): array
    {
        $query = $this->database->connection()->table('order_items as item')
            ->join('orders as order_row', 'order_row.id', '=', 'item.order_id')
            ->join('plan_offerings as offering', 'offering.id', '=', 'item.plan_offering_id')
            ->join('products as product', 'product.id', '=', 'offering.product_id')
            ->select('product.code')
            ->selectRaw('COUNT(*) AS aggregate_count, COALESCE(SUM(item.final_price_irr), 0) AS aggregate_irr')
            ->whereNotNull('order_row.paid_at')
            ->groupBy('product.code')
            ->orderByDesc('aggregate_irr')
            ->orderBy('product.code')
            ->limit(100);
        $this->applyRange($query, 'order_row.paid_at', $range);

        $metrics = [];
        /** @var object{code:string,aggregate_count:int|string,aggregate_irr:int|string} $row */
        foreach ($query->get() as $row) {
            $metrics[] = $this->metric('catalog', 'catalog.sold_count', 'Sold order items by product', $this->toInt($row->aggregate_count), 'count', $row->code);
            $metrics[] = $this->metric('catalog', 'catalog.sales_irr', 'Captured product sales', $this->toInt($row->aggregate_irr), 'IRR', $row->code);
        }

        return $metrics;
    }

    private function discounts(ReportDateRange $range): int
    {
        $query = $this->database->connection()->table('order_items as item')
            ->join('orders as order_row', 'order_row.id', '=', 'item.order_id')
            ->whereNotNull('order_row.paid_at');
        $this->applyRange($query, 'order_row.paid_at', $range);

        return $this->toInt($query->sum('item.discount_irr'));
    }

    private function walletAdjustments(ReportDateRange $range): int
    {
        $query = $this->database->connection()->table('wallet_corrections as correction')
            ->join('wallet_correction_previews as preview', 'preview.id', '=', 'correction.preview_id');
        $this->applyRange($query, 'correction.created_at', $range);
        /** @var object{aggregate:int|string|null}|null $row */
        $row = $query->selectRaw("COALESCE(SUM(CASE WHEN preview.direction = 'credit' THEN preview.amount_irr ELSE -preview.amount_irr END), 0) AS aggregate")->first();

        return $this->toInt($row->aggregate ?? 0);
    }

    private function walletLiabilityAt(\DateTimeImmutable $endsBeforeUtc): int
    {
        /** @var object{aggregate:int|string|null}|null $row */
        $row = $this->database->connection()->table('ledger_entries as entry')
            ->join('ledger_accounts as account', 'account.id', '=', 'entry.ledger_account_id')
            ->join('ledger_transactions as transaction_row', 'transaction_row.id', '=', 'entry.ledger_transaction_id')
            ->whereNotNull('account.owner_user_id')
            ->whereNotNull('account.wallet_bucket')
            ->where('account.account_class', 'liability')
            ->whereNotNull('transaction_row.finalized_at')
            ->where('transaction_row.finalized_at', '<', $endsBeforeUtc->format('Y-m-d H:i:s.u'))
            ->selectRaw("COALESCE(SUM(CASE WHEN entry.direction = 'credit' THEN entry.amount_irr ELSE -entry.amount_irr END), 0) AS aggregate")
            ->first();

        return $this->toInt($row->aggregate ?? 0);
    }

    /** @return array<string,int> */
    private function provisioningFailureEvents(ReportDateRange $range): array
    {
        $query = $this->database->connection()->table('provisioning_operation_histories')
            ->select('to_state')
            ->selectRaw('COUNT(*) AS aggregate')
            ->whereIn('to_state', ['uncertain_remote_result', 'failed_final', 'needs_review'])
            ->groupBy('to_state')
            ->orderBy('to_state');
        $this->applyRange($query, 'created_at', $range);

        $result = [];
        /** @var object{to_state:string,aggregate:int|string} $row */
        foreach ($query->get() as $row) {
            $result[$row->to_state] = $this->toInt($row->aggregate);
        }

        return $result;
    }

    private function count(Builder $query, string $timeColumn, ReportDateRange $range): int
    {
        $this->applyRange($query, $timeColumn, $range);

        return $this->toInt($query->count());
    }

    private function sum(Builder $query, string $valueColumn, string $timeColumn, ReportDateRange $range): int
    {
        $this->applyRange($query, $timeColumn, $range);

        return $this->toInt($query->sum($valueColumn));
    }

    /** @return array<string,int> */
    private function groupedCount(Builder $query, string $dimensionColumn, string $timeColumn, ReportDateRange $range): array
    {
        $this->applyRange($query, $timeColumn, $range);

        $result = [];
        foreach ($query->get() as $row) {
            /** @var mixed $dimension */
            $dimension = $row->{$dimensionColumn};
            /** @var mixed $aggregate */
            $aggregate = $row->aggregate;
            if (! is_string($dimension)) {
                throw new RuntimeException('Reporting dimension must be a string.');
            }
            $result[$dimension] = $this->toInt($aggregate);
        }

        return $result;
    }

    private function applyRange(Builder $query, string $column, ReportDateRange $range): void
    {
        if ($range->startsAtUtc !== null) {
            $query->where($column, '>=', $range->databaseStart());
        }
        $query->where($column, '<', $range->databaseEndExclusive());
    }

    private function metric(string $section, string $code, string $label, int $value, string $unit, ?string $dimension = null): ReportMetric
    {
        return new ReportMetric($section, $code, $label, $value, $unit, $dimension);
    }

    /**
     * Index metrics by stable report key.
     *
     * @param  list<ReportMetric>  $metrics
     * @return array<string, ReportMetric>
     */
    private function indexByKey(array $metrics): array
    {
        $indexed = [];
        foreach ($metrics as $metric) {
            $indexed[$metric->key()] = $metric;
        }

        return $indexed;
    }

    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/\A-?[0-9]+\z/', $value) === 1) {
            $integer = (int) $value;
            if ((string) $integer === $value || ($value === '-0' && $integer === 0)) {
                return $integer;
            }
        }

        throw new RuntimeException('Reporting aggregate is outside the supported integer range.');
    }
}

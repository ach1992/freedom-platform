<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Agents\Domain\AgentStatus;
use App\Modules\Telegram\Application\Contracts\TelegramAgentReport;
use App\Modules\Telegram\Application\Contracts\TelegramAgentReportSnapshot;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Read-only Agent reporting over accepted purchase settlement and canonical Order authority.
 *
 * "Spending" is historical gross captured settlement value. "Sales" is the gross commercial
 * value of canonical materialized Agent purchase Orders. The platform has no separate downstream
 * reseller-revenue authority, so this service deliberately does not infer one.
 */
final readonly class AgentReportService implements TelegramAgentReport
{
    private const RECENT_OFFERING_LIMIT = 5;

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement AGT-006 DAT-002 DAT-003 SEC-003 */
    public function forSelf(int $actorUserId, int $subjectUserId, string $period): TelegramAgentReportSnapshot
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Agent report is self-only.');
        }
        if (! in_array($period, TelegramAgentReport::PERIODS, true)) {
            throw new DomainException('Agent report period is unsupported.');
        }

        $this->assertCurrentAgent($subjectUserId);
        $endsAt = $this->clock->now();
        $startsAt = $this->startsAt($endsAt, $period);

        $purchaseQuery = $this->acceptedPurchases($subjectUserId, $startsAt, $endsAt);
        /** @var object{purchase_count:int|string|null,gross_spending_irr:int|string|null}|null $purchases */
        $purchases = (clone $purchaseQuery)->selectRaw(
            'COUNT(settlement.id) AS purchase_count, COALESCE(SUM(settlement.amount_irr), 0) AS gross_spending_irr'
        )->first();

        /** @var object{sales_count:int|string|null,gross_sales_irr:int|string|null}|null $sales */
        $sales = (clone $purchaseQuery)
            ->join('orders as purchase_order', 'purchase_order.purchase_settlement_id', '=', 'settlement.id')
            ->selectRaw(
                'COUNT(purchase_order.id) AS sales_count, COALESCE(SUM(purchase_order.total_amount_irr), 0) AS gross_sales_irr'
            )
            ->first();

        /** @var object{aggregate:int|string}|null $services */
        $services = (clone $purchaseQuery)
            ->join('orders as service_order', 'service_order.purchase_settlement_id', '=', 'settlement.id')
            ->join('order_items as service_item', 'service_item.order_id', '=', 'service_order.id')
            ->selectRaw('COUNT(service_item.id) AS aggregate')
            ->first();

        /** @var list<object{offering_code_snapshot:string}> $recent */
        $recent = (clone $purchaseQuery)
            ->orderByDesc('settlement.settled_at')
            ->orderByDesc('settlement.id')
            ->limit(self::RECENT_OFFERING_LIMIT)
            ->get(['quote.offering_code_snapshot'])
            ->all();

        return new TelegramAgentReportSnapshot(
            $period,
            $startsAt?->format('Y-m-d H:i:s.u'),
            $endsAt->format('Y-m-d H:i:s.u'),
            (int) $purchases->purchase_count,
            (int) $purchases->gross_spending_irr,
            (int) $sales->sales_count,
            (int) $sales->gross_sales_irr,
            (int) $services->aggregate,
            array_map(static fn (object $row): string => $row->offering_code_snapshot, $recent),
        );
    }

    private function assertCurrentAgent(int $userId): void
    {
        $connection = $this->database->connection();
        /** @var object{account_type:string,account_status:string,agent_status:string}|null $agent */
        $agent = $connection->table('users as user')
            ->join('agent_profiles as profile', 'profile.user_id', '=', 'user.id')
            ->where('user.id', $userId)
            ->first([
                'user.account_type',
                'user.account_status',
                'profile.status as agent_status',
            ]);
        if ($agent === null
            || $agent->account_type !== 'agent'
            || $agent->account_status === 'deleted'
            || AgentStatus::tryFrom($agent->agent_status) === null) {
            throw new AuthorizationException('Agent report requires a current Agent account.');
        }
    }

    private function acceptedPurchases(
        int $userId,
        ?DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
    ): Builder {
        $query = $this->database->connection()->table('purchase_settlements as settlement')
            ->join('payment_intents as intent', 'intent.id', '=', 'settlement.payment_intent_id')
            ->join('quotes as quote', 'quote.id', '=', 'settlement.source_quote_id')
            ->where('settlement.user_id', $userId)
            ->where('intent.user_id', $userId)
            ->where('intent.purpose', 'purchase')
            ->whereNotNull('intent.captured_at')
            ->whereIn('intent.state', ['captured', 'refund_pending', 'refunded', 'partially_refunded'])
            ->whereColumn('intent.source_quote_id', 'quote.id')
            ->whereColumn('intent.source_quote_public_id', 'quote.public_id')
            ->whereColumn('settlement.source_quote_public_id', 'quote.public_id')
            ->where('quote.user_id', $userId)
            ->where('quote.account_type_snapshot', 'agent')
            ->where('quote.action_snapshot', 'purchase')
            ->whereNotNull('quote.agent_pricing_resolution_id')
            ->where('quote.agent_pricing_action_snapshot', 'purchase')
            ->where('settlement.settled_at', '<=', $endsAt->format('Y-m-d H:i:s.u'));

        if ($startsAt !== null) {
            $query->where('settlement.settled_at', '>=', $startsAt->format('Y-m-d H:i:s.u'));
        }

        return $query;
    }

    private function startsAt(DateTimeImmutable $now, string $period): ?DateTimeImmutable
    {
        if ($period === TelegramAgentReport::PERIOD_ALL) {
            return null;
        }

        $tehran = $now->setTimezone(new DateTimeZone('Asia/Tehran'))->setTime(0, 0, 0, 0);
        $start = match ($period) {
            TelegramAgentReport::PERIOD_TODAY => $tehran,
            TelegramAgentReport::PERIOD_SEVEN_DAYS => $tehran->modify('-6 days'),
            TelegramAgentReport::PERIOD_THIRTY_DAYS => $tehran->modify('-29 days'),
            default => throw new DomainException('Agent report period is unsupported.'),
        };

        return $start->setTimezone(new DateTimeZone('UTC'));
    }
}

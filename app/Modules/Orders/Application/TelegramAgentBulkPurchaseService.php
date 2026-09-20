<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Telegram\Application\Contracts\TelegramAgentBulkPurchase;
use App\Modules\Telegram\Application\TelegramAgentBulkPurchaseCandidate;
use App\Modules\Telegram\Application\TelegramAgentBulkPurchasePage;
use App\Modules\Telegram\Application\TelegramAgentBulkPurchaseResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use RuntimeException;

/**
 * Telegram projection/orchestration for the accepted Agent bulk Order authority.
 * It never creates or captures payment authority: selected children must already
 * be authoritative Agent purchase settlements and AgentBulkOrderService rechecks
 * that invariant at execution time.
 */
final readonly class TelegramAgentBulkPurchaseService implements TelegramAgentBulkPurchase
{
    private const MAXIMUM_PAGE_SIZE = 6;

    private const MAXIMUM_SELECTION = 50;

    public function __construct(
        private DatabaseManager $database,
        private AgentBulkOrderService $bulkOrders,
    ) {}

    /** @requirement AGT-004 PAY-002 PAY-003 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function pageForSelf(
        int $actorUserId,
        int $subjectUserId,
        int $page,
        int $pageSize,
    ): TelegramAgentBulkPurchasePage {
        $this->assertSelf($actorUserId, $subjectUserId);
        if ($page < 1 || $pageSize < 1 || $pageSize > self::MAXIMUM_PAGE_SIZE) {
            throw new InvalidArgumentException('Telegram Agent bulk purchase page request is invalid.');
        }

        $connection = $this->database->connection();
        $this->assertActiveAgent($connection, $subjectUserId);
        $query = $this->eligibleQuery($connection, $subjectUserId);
        $totalItems = (int) (clone $query)->count('settlement.id');
        $totalPages = max(1, (int) ceil($totalItems / $pageSize));
        $effectivePage = min($page, $totalPages);
        $rows = (clone $query)
            ->orderByDesc('settlement.settled_at')
            ->orderByDesc('settlement.id')
            ->forPage($effectivePage, $pageSize)
            ->get($this->projectionColumns())
            ->all();

        return new TelegramAgentBulkPurchasePage(
            array_values(array_map(fn (object $row): TelegramAgentBulkPurchaseCandidate => $this->candidate($row), $rows)),
            $effectivePage,
            $totalPages,
            $totalItems,
        );
    }

    /**
     * @param  list<string>  $purchaseSettlementPublicIds
     * @return list<TelegramAgentBulkPurchaseCandidate>
     */
    public function candidatesForSelf(
        int $actorUserId,
        int $subjectUserId,
        array $purchaseSettlementPublicIds,
    ): array {
        $this->assertSelf($actorUserId, $subjectUserId);
        $ids = $this->normalizeSettlementPublicIds($purchaseSettlementPublicIds);
        $connection = $this->database->connection();
        $this->assertActiveAgent($connection, $subjectUserId);
        $rows = $this->eligibleQuery($connection, $subjectUserId)
            ->whereIn('settlement.public_id', $ids)
            ->get($this->projectionColumns())
            ->keyBy('settlement_public_id');

        $ordered = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            if (! is_object($row)) {
                throw new AuthorizationException('Selected Agent bulk purchase is no longer eligible.');
            }
            $ordered[] = $this->candidate($row);
        }

        return $ordered;
    }

    /** @param list<string> $purchaseSettlementPublicIds */
    public function executeForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $batchKey,
        array $purchaseSettlementPublicIds,
        string $correlationId,
    ): TelegramAgentBulkPurchaseResult {
        $this->assertSelf($actorUserId, $subjectUserId);
        $ids = $this->normalizeSettlementPublicIds($purchaseSettlementPublicIds);
        $items = [];
        foreach ($ids as $index => $id) {
            $items[] = [
                'child_key' => 'tg-item:'.($index + 1).':'.strtolower($id),
                'purchase_settlement_public_id' => $id,
            ];
        }

        $receipt = $this->bulkOrders->execute($batchKey, $subjectUserId, $items, $correlationId);

        return new TelegramAgentBulkPurchaseResult(
            $receipt->bulkOrderPublicId,
            $receipt->succeededCount,
            $receipt->failedCount,
            $receipt->replayed,
        );
    }

    private function eligibleQuery(Connection $connection, int $userId): Builder
    {
        return $connection->table('purchase_settlements as settlement')
            ->join('payment_intents as intent', 'intent.id', '=', 'settlement.payment_intent_id')
            ->join('quotes as quote', 'quote.id', '=', 'settlement.source_quote_id')
            ->leftJoin('orders as purchase_order', 'purchase_order.purchase_settlement_id', '=', 'settlement.id')
            ->leftJoin('agent_bulk_order_items as bulk_item', 'bulk_item.purchase_settlement_id', '=', 'settlement.id')
            ->where('settlement.user_id', $userId)
            ->where('intent.user_id', $userId)
            ->where('intent.purpose', 'purchase')
            ->whereNotNull('intent.captured_at')
            ->where('intent.state', 'captured')
            ->whereColumn('intent.source_quote_id', 'quote.id')
            ->whereColumn('intent.source_quote_public_id', 'quote.public_id')
            ->whereColumn('settlement.source_quote_id', 'quote.id')
            ->whereColumn('settlement.source_quote_public_id', 'quote.public_id')
            ->where('quote.user_id', $userId)
            ->where('quote.account_type_snapshot', 'agent')
            ->where('quote.action_snapshot', 'purchase')
            ->whereNotNull('quote.agent_pricing_resolution_id')
            ->where('quote.agent_pricing_action_snapshot', 'purchase')
            ->whereNull('purchase_order.id')
            ->whereNull('bulk_item.id');
    }

    /** @return list<string> */
    private function projectionColumns(): array
    {
        return [
            'settlement.public_id as settlement_public_id',
            'settlement.amount_irr',
            'settlement.settled_at',
            'quote.offering_code_snapshot',
        ];
    }

    private function candidate(object $row): TelegramAgentBulkPurchaseCandidate
    {
        return new TelegramAgentBulkPurchaseCandidate(
            $this->databaseUlid($row->settlement_public_id ?? null, 'Agent purchase settlement public ID'),
            $this->databaseToken($row->offering_code_snapshot ?? null, 'Agent purchase offering code'),
            $this->positiveDatabaseInt($row->amount_irr ?? null, 'Agent purchase settlement amount'),
            $this->databaseString($row->settled_at ?? null, 'Agent purchase settlement timestamp'),
        );
    }

    private function assertActiveAgent(Connection $connection, int $userId): void
    {
        $row = $connection->table('users as user')
            ->join('agent_profiles as profile', 'profile.user_id', '=', 'user.id')
            ->where('user.id', $userId)
            ->first(['user.account_type', 'user.account_status', 'profile.status', 'profile.pricing_profile_code']);
        if ($row === null
            || $row->account_type !== 'agent'
            || $row->account_status !== 'active'
            || $row->status !== 'active'
            || $row->pricing_profile_code === null) {
            throw new AuthorizationException('Telegram Agent bulk purchase requires an active Agent account.');
        }
    }

    /** @param list<string> $values
     * @return list<string>
     */
    private function normalizeSettlementPublicIds(array $values): array
    {
        if ($values === [] || count($values) > self::MAXIMUM_SELECTION) {
            throw new InvalidArgumentException('Telegram Agent bulk purchase requires between 1 and 50 selections.');
        }
        $normalized = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('Telegram Agent bulk purchase selection is invalid.');
            }
            $id = strtoupper(trim($value));
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $id) !== 1 || isset($normalized[$id])) {
                throw new InvalidArgumentException('Telegram Agent bulk purchase selections must be unique ULIDs.');
            }
            $normalized[$id] = true;
        }

        return array_keys($normalized);
    }

    private function assertSelf(int $actorUserId, int $subjectUserId): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram Agent bulk purchase is self-only.');
        }
    }

    private function databaseUlid(mixed $value, string $label): string
    {
        $value = $this->databaseString($value, $label);
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function databaseToken(mixed $value, string $label): string
    {
        $value = $this->databaseString($value, $label);
        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function databaseString(mixed $value, string $label): string
    {
        if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function positiveDatabaseInt(mixed $value, string $label): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalized === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Shared\Application\Clock;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Groups already-authoritative agent purchase settlements into one replay-safe parent bulk Order.
 *
 * The parent and child rows are orchestration/audit state only. They never create PaymentIntents,
 * capture money, or reinterpret settlement facts. Every successful child delegates to the accepted
 * PurchaseOrderService and therefore produces the canonical purchase Order/OrderItem pair.
 *
 * @phpstan-type BulkItemInput array{child_key:string,purchase_settlement_public_id:string}
 * @phpstan-type BulkParentRow object{id:int|string,public_id:string,batch_key:string,request_payload_hash:string,user_id:int|string,item_count:int|string,creation_correlation_id:string}
 * @phpstan-type SettlementAuthorityRow object{settlement_id:int|string,settlement_public_id:string,source_quote_id:int|string,source_quote_public_id:string}
 * @phpstan-type BulkItemRow object{id:int|string,public_id:string,agent_bulk_order_id:int|string,line_number:int|string,child_key:string,purchase_settlement_id:int|string,purchase_settlement_public_id:string,source_quote_id:int|string,source_quote_public_id:string,state:string,attempt_count:int|string,order_id:int|string|null,order_item_id:int|string|null,last_error_code:string|null,order_public_id:string|null,order_item_public_id:string|null}
 */
final readonly class AgentBulkOrderService
{
    private const MAX_ITEMS = 50;

    private const CHILD_AUTHORITY = 'agent_bulk_child_v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private PurchaseOrderService $purchaseOrders,
    ) {}

    /**
     * @param  list<BulkItemInput>  $items
     *
     * @requirement AGT-003 AGT-004 BUY-001 BUY-002 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004
     */
    public function execute(
        string $batchKey,
        int $agentUserId,
        array $items,
        string $correlationId,
    ): AgentBulkOrderReceipt {
        $normalizedBatchKey = $this->normalizedKey($batchKey, 'Agent bulk Order batch key');
        if ($agentUserId < 1) {
            throw new DomainException('Agent bulk Order user ID is invalid.');
        }
        $this->assertToken($correlationId, 'Agent bulk Order correlation ID', 8, 64);
        $normalizedItems = $this->normalizedItems($items);
        $requestHash = $this->requestHash($agentUserId, $normalizedItems);

        [$parent, $replayed] = $this->ensureParent(
            $normalizedBatchKey,
            $agentUserId,
            $normalizedItems,
            $requestHash,
            $correlationId,
        );

        /** @var list<BulkItemRow> $children */
        $children = $this->children((int) $parent->id);
        foreach ($children as $child) {
            if ($child->state === 'succeeded') {
                continue;
            }

            try {
                $order = $this->purchaseOrders->createFromSettlement(
                    $child->purchase_settlement_public_id,
                    $this->childCorrelation($correlationId, $child->child_key),
                );
                $this->markSucceeded($parent, $child, $order);
            } catch (Throwable $exception) {
                $this->markFailed($parent, $child, $this->failureCode($exception));
            }
        }

        return $this->receipt($parent, $replayed);
    }

    /**
     * @param  list<BulkItemInput>  $items
     * @return array{0:BulkParentRow,1:bool}
     */
    private function ensureParent(
        string $batchKey,
        int $agentUserId,
        array $items,
        string $requestHash,
        string $correlationId,
    ): array {
        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $batchKey,
                $agentUserId,
                $items,
                $requestHash,
                $correlationId,
            ): array {
                $existing = $this->parentByBatchKey($connection, $batchKey, true);
                if ($existing !== null) {
                    $this->assertReplay($existing, $agentUserId, count($items), $requestHash);

                    return [$existing, true];
                }

                $this->assertActiveAgent($connection, $agentUserId);
                $authorities = $this->settlementAuthorities($connection, $agentUserId, $items);
                $timestamp = $this->timestamp();
                $parentId = (int) $connection->table('agent_bulk_orders')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'batch_key' => $batchKey,
                    'request_payload_hash' => $requestHash,
                    'user_id' => $agentUserId,
                    'item_count' => count($items),
                    'creation_correlation_id' => $correlationId,
                    'created_at' => $timestamp,
                ]);

                foreach ($items as $offset => $item) {
                    $authority = $authorities[$item['purchase_settlement_public_id']] ?? null;
                    if ($authority === null) {
                        throw new RuntimeException('Agent bulk settlement authority mapping disappeared.');
                    }
                    $connection->table('agent_bulk_order_items')->insert([
                        'public_id' => (string) Str::ulid(),
                        'agent_bulk_order_id' => $parentId,
                        'line_number' => $offset + 1,
                        'child_key' => $item['child_key'],
                        'purchase_settlement_id' => (int) $authority->settlement_id,
                        'purchase_settlement_public_id' => $authority->settlement_public_id,
                        'source_quote_id' => (int) $authority->source_quote_id,
                        'source_quote_public_id' => $authority->source_quote_public_id,
                        'state' => 'pending',
                        'attempt_count' => 0,
                        'order_id' => null,
                        'order_item_id' => null,
                        'last_error_code' => null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                }

                $created = $this->parentById($connection, $parentId);
                if ($created === null) {
                    throw new RuntimeException('Agent bulk parent Order persistence failed.');
                }

                return [$created, false];
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->parentByBatchKey($this->database->connection(), $batchKey, false);
            if ($existing !== null) {
                $this->assertReplay($existing, $agentUserId, count($items), $requestHash);

                return [$existing, true];
            }

            throw $exception;
        }
    }

    private function assertActiveAgent(Connection $connection, int $userId): void
    {
        /** @var object{account_type:string,account_status:string,status:string,pricing_profile_code:string|null}|null $row */
        $row = $connection->table('users as user_row')
            ->join('agent_profiles as agent_row', 'agent_row.user_id', '=', 'user_row.id')
            ->where('user_row.id', $userId)
            ->lockForUpdate()
            ->first([
                'user_row.account_type',
                'user_row.account_status',
                'agent_row.status',
                'agent_row.pricing_profile_code',
            ]);
        if ($row === null
            || $row->account_type !== 'agent'
            || $row->account_status !== 'active'
            || $row->status !== 'active'
            || $row->pricing_profile_code === null
        ) {
            throw new DomainException('Agent bulk Order requires one active agent with active pricing authority.');
        }
    }

    /**
     * @param  list<BulkItemInput>  $items
     * @return array<string,SettlementAuthorityRow>
     */
    private function settlementAuthorities(Connection $connection, int $agentUserId, array $items): array
    {
        $publicIds = array_map(
            static fn (array $item): string => $item['purchase_settlement_public_id'],
            $items,
        );

        /** @var list<SettlementAuthorityRow> $rows */
        $rows = $connection->table('purchase_settlements as settlement_row')
            ->join('payment_intents as intent_row', 'intent_row.id', '=', 'settlement_row.payment_intent_id')
            ->join('quotes as quote_row', 'quote_row.id', '=', 'settlement_row.source_quote_id')
            ->whereIn('settlement_row.public_id', $publicIds)
            ->where('settlement_row.user_id', $agentUserId)
            ->where('intent_row.purpose', 'purchase')
            ->where('intent_row.user_id', $agentUserId)
            ->whereNotNull('intent_row.captured_at')
            ->whereIn('intent_row.state', ['captured', 'refund_pending', 'refunded', 'partially_refunded'])
            ->where('quote_row.user_id', $agentUserId)
            ->where('quote_row.account_type_snapshot', 'agent')
            ->whereNotNull('quote_row.agent_pricing_resolution_id')
            ->orderBy('settlement_row.id')
            ->lockForUpdate()
            ->get([
                'settlement_row.id as settlement_id',
                'settlement_row.public_id as settlement_public_id',
                'settlement_row.source_quote_id',
                'settlement_row.source_quote_public_id',
            ])
            ->all();

        if (count($rows) !== count($items)) {
            throw new DomainException('Every agent bulk child requires one accepted agent purchase settlement.');
        }

        $mapped = [];
        foreach ($rows as $row) {
            $mapped[$row->settlement_public_id] = $row;
        }

        return $mapped;
    }

    /**
     * @param  BulkParentRow  $parent
     * @param  BulkItemRow  $child
     */
    private function markSucceeded(object $parent, object $child, PurchaseOrderReceipt $order): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($parent, $child, $order): void {
            $locked = $this->childById($connection, (int) $child->id, true);
            if ($locked === null || $locked->state === 'succeeded') {
                return;
            }

            /** @var object{id:int|string}|null $orderItem */
            $orderItem = $connection->table('order_items')
                ->where('public_id', $order->orderItemPublicId)
                ->where('order_id', $order->orderId)
                ->first(['id']);
            if ($orderItem === null) {
                throw new RuntimeException('Agent bulk canonical Order Item disappeared.');
            }

            $this->setChildAuthority($connection, $parent);
            try {
                $updated = $connection->table('agent_bulk_order_items')
                    ->where('id', (int) $locked->id)
                    ->where('state', $locked->state)
                    ->where('attempt_count', (int) $locked->attempt_count)
                    ->update([
                        'state' => 'succeeded',
                        'attempt_count' => (int) $locked->attempt_count + 1,
                        'order_id' => $order->orderId,
                        'order_item_id' => (int) $orderItem->id,
                        'last_error_code' => null,
                        'updated_at' => $this->timestamp(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Agent bulk child success lost its state.');
                }
            } finally {
                $this->clearChildAuthority($connection);
            }
        }, 3);
    }

    /**
     * @param  BulkParentRow  $parent
     * @param  BulkItemRow  $child
     */
    private function markFailed(object $parent, object $child, string $failureCode): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($parent, $child, $failureCode): void {
            $locked = $this->childById($connection, (int) $child->id, true);
            if ($locked === null || $locked->state === 'succeeded') {
                return;
            }

            $this->setChildAuthority($connection, $parent);
            try {
                $updated = $connection->table('agent_bulk_order_items')
                    ->where('id', (int) $locked->id)
                    ->where('state', $locked->state)
                    ->where('attempt_count', (int) $locked->attempt_count)
                    ->update([
                        'state' => 'failed',
                        'attempt_count' => (int) $locked->attempt_count + 1,
                        'order_id' => null,
                        'order_item_id' => null,
                        'last_error_code' => $failureCode,
                        'updated_at' => $this->timestamp(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Agent bulk child failure lost its state.');
                }
            } finally {
                $this->clearChildAuthority($connection);
            }
        }, 3);
    }

    private function receipt(object $parent, bool $replayed): AgentBulkOrderReceipt
    {
        $items = [];
        $succeeded = 0;
        $failed = 0;
        foreach ($this->children((int) $parent->id) as $child) {
            if ($child->state === 'succeeded') {
                $succeeded++;
            } elseif ($child->state === 'failed') {
                $failed++;
            }
            $items[] = [
                'child_key' => $child->child_key,
                'purchase_settlement_public_id' => $child->purchase_settlement_public_id,
                'state' => $child->state,
                'order_public_id' => $child->order_public_id,
                'order_item_public_id' => $child->order_item_public_id,
                'last_error_code' => $child->last_error_code,
                'attempt_count' => (int) $child->attempt_count,
            ];
        }

        return new AgentBulkOrderReceipt(
            (int) $parent->id,
            $parent->public_id,
            (int) $parent->user_id,
            $items,
            $succeeded,
            $failed,
            $replayed,
        );
    }

    /** @return list<BulkItemRow> */
    private function children(int $parentId): array
    {
        /** @var list<BulkItemRow> $rows */
        $rows = $this->database->connection()->table('agent_bulk_order_items as child_row')
            ->leftJoin('orders as order_row', 'order_row.id', '=', 'child_row.order_id')
            ->leftJoin('order_items as item_row', 'item_row.id', '=', 'child_row.order_item_id')
            ->where('child_row.agent_bulk_order_id', $parentId)
            ->orderBy('child_row.line_number')
            ->get([
                'child_row.id', 'child_row.public_id', 'child_row.agent_bulk_order_id', 'child_row.line_number',
                'child_row.child_key', 'child_row.purchase_settlement_id', 'child_row.purchase_settlement_public_id',
                'child_row.source_quote_id', 'child_row.source_quote_public_id', 'child_row.state',
                'child_row.attempt_count', 'child_row.order_id', 'child_row.order_item_id', 'child_row.last_error_code',
                'order_row.public_id as order_public_id', 'item_row.public_id as order_item_public_id',
            ])
            ->all();

        return $rows;
    }

    /** @return BulkParentRow|null */
    private function parentByBatchKey(Connection $connection, string $batchKey, bool $lock): ?object
    {
        $query = $connection->table('agent_bulk_orders')->where('batch_key', $batchKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var BulkParentRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'batch_key', 'request_payload_hash', 'user_id', 'item_count', 'creation_correlation_id',
        ]);

        return $row;
    }

    /** @return BulkParentRow|null */
    private function parentById(Connection $connection, int $id): ?object
    {
        /** @var BulkParentRow|null $row */
        $row = $connection->table('agent_bulk_orders')->where('id', $id)->first([
            'id', 'public_id', 'batch_key', 'request_payload_hash', 'user_id', 'item_count', 'creation_correlation_id',
        ]);

        return $row;
    }

    /** @return BulkItemRow|null */
    private function childById(Connection $connection, int $id, bool $lock): ?object
    {
        $query = $connection->table('agent_bulk_order_items as child_row')
            ->leftJoin('orders as order_row', 'order_row.id', '=', 'child_row.order_id')
            ->leftJoin('order_items as item_row', 'item_row.id', '=', 'child_row.order_item_id')
            ->where('child_row.id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var BulkItemRow|null $row */
        $row = $query->first([
            'child_row.id', 'child_row.public_id', 'child_row.agent_bulk_order_id', 'child_row.line_number',
            'child_row.child_key', 'child_row.purchase_settlement_id', 'child_row.purchase_settlement_public_id',
            'child_row.source_quote_id', 'child_row.source_quote_public_id', 'child_row.state',
            'child_row.attempt_count', 'child_row.order_id', 'child_row.order_item_id', 'child_row.last_error_code',
            'order_row.public_id as order_public_id', 'item_row.public_id as order_item_public_id',
        ]);

        return $row;
    }

    /** @param BulkParentRow $row */
    private function assertReplay(object $row, int $agentUserId, int $itemCount, string $requestHash): void
    {
        if ((int) $row->user_id !== $agentUserId
            || (int) $row->item_count !== $itemCount
            || ! hash_equals(strtolower($row->request_payload_hash), $requestHash)
        ) {
            throw new RuntimeException('Agent bulk Order batch key conflicts with another request.');
        }
    }

    /**
     * @param  list<BulkItemInput>  $items
     * @return list<BulkItemInput>
     */
    private function normalizedItems(array $items): array
    {
        if ($items === [] || count($items) > self::MAX_ITEMS) {
            throw new DomainException('Agent bulk Order requires between 1 and 50 child purchases.');
        }

        $normalized = [];
        $childKeys = [];
        $settlements = [];
        foreach ($items as $item) {
            if (! is_array($item)
                || ! isset($item['child_key'], $item['purchase_settlement_public_id'])
                || ! is_string($item['child_key'])
                || ! is_string($item['purchase_settlement_public_id'])
            ) {
                throw new DomainException('Agent bulk Order child request shape is invalid.');
            }
            $childKey = $this->normalizedKey($item['child_key'], 'Agent bulk child key');
            $settlementPublicId = strtoupper(trim($item['purchase_settlement_public_id']));
            $this->assertUlid($settlementPublicId, 'Agent bulk child purchase settlement public ID');
            if (isset($childKeys[$childKey]) || isset($settlements[$settlementPublicId])) {
                throw new DomainException('Agent bulk Order child keys and settlements must be unique.');
            }
            $childKeys[$childKey] = true;
            $settlements[$settlementPublicId] = true;
            $normalized[] = [
                'child_key' => $childKey,
                'purchase_settlement_public_id' => $settlementPublicId,
            ];
        }

        return $normalized;
    }

    /** @param list<BulkItemInput> $items */
    private function requestHash(int $agentUserId, array $items): string
    {
        try {
            $json = json_encode([
                'items' => $items,
                'user_id' => $agentUserId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Agent bulk Order request could not be normalized.', 0, $exception);
        }

        return hash('sha256', $json);
    }

    private function normalizedKey(string $value, string $label): string
    {
        $normalized = strtolower(trim($value));
        if (preg_match('/\A[a-z0-9][a-z0-9._:-]{7,127}\z/', $normalized) !== 1) {
            throw new DomainException($label.' is invalid.');
        }

        return $normalized;
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertUlid(string $value, string $label): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function childCorrelation(string $correlationId, string $childKey): string
    {
        return hash('sha256', $correlationId.':'.$childKey);
    }

    private function failureCode(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof DomainException => 'domain_rejected',
            $exception instanceof QueryException => 'database_rejected',
            $exception instanceof RuntimeException => 'authority_rejected',
            default => 'unexpected_failure',
        };
    }

    /** @param BulkParentRow $parent */
    private function setChildAuthority(Connection $connection, object $parent): void
    {
        $connection->statement(
            'SET @app_agent_bulk_order_authority = ?, @app_agent_bulk_order_public_id = ?, @app_agent_bulk_order_correlation_id = ?',
            [self::CHILD_AUTHORITY, $parent->public_id, $parent->creation_correlation_id],
        );
    }

    private function clearChildAuthority(Connection $connection): void
    {
        $connection->statement('SET @app_agent_bulk_order_authority = NULL, @app_agent_bulk_order_public_id = NULL, @app_agent_bulk_order_correlation_id = NULL');
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}

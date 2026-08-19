<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

/**
 * @phpstan-type AgentBulkChildReceipt array{child_key:string,purchase_settlement_public_id:string,state:string,order_public_id:?string,order_item_public_id:?string,last_error_code:?string,attempt_count:int}
 */
final readonly class AgentBulkOrderReceipt
{
    /**
     * @param list<AgentBulkChildReceipt> $items
     */
    public function __construct(
        public int $bulkOrderId,
        public string $bulkOrderPublicId,
        public int $agentUserId,
        public array $items,
        public int $succeededCount,
        public int $failedCount,
        public bool $replayed = false,
    ) {}
}

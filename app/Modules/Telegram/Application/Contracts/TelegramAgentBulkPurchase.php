<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramAgentBulkPurchaseCandidate;
use App\Modules\Telegram\Application\TelegramAgentBulkPurchasePage;
use App\Modules\Telegram\Application\TelegramAgentBulkPurchaseResult;

interface TelegramAgentBulkPurchase
{
    public function pageForSelf(
        int $actorUserId,
        int $subjectUserId,
        int $page,
        int $pageSize,
    ): TelegramAgentBulkPurchasePage;

    /**
     * @param  list<string>  $purchaseSettlementPublicIds
     * @return list<TelegramAgentBulkPurchaseCandidate>
     */
    public function candidatesForSelf(
        int $actorUserId,
        int $subjectUserId,
        array $purchaseSettlementPublicIds,
    ): array;

    /** @param list<string> $purchaseSettlementPublicIds */
    public function executeForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $batchKey,
        array $purchaseSettlementPublicIds,
        string $correlationId,
    ): TelegramAgentBulkPurchaseResult;
}

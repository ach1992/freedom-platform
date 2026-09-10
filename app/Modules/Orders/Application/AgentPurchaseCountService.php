<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Agents\Domain\AgentStatus;
use App\Modules\Telegram\Application\Contracts\TelegramAgentPurchaseCount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

final readonly class AgentPurchaseCountService implements TelegramAgentPurchaseCount
{
    public function __construct(private DatabaseManager $database) {}

    /** @requirement AGT-006 SEC-003 */
    public function forSelf(int $actorUserId, int $subjectUserId): int
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Agent purchase count is self-only.');
        }

        $connection = $this->database->connection();
        /** @var object{account_type:string,account_status:string,agent_status:string}|null $agent */
        $agent = $connection->table('users as user')
            ->join('agent_profiles as profile', 'profile.user_id', '=', 'user.id')
            ->where('user.id', $subjectUserId)
            ->first([
                'user.account_type',
                'user.account_status',
                'profile.status as agent_status',
            ]);
        if ($agent === null
            || $agent->account_type !== 'agent'
            || $agent->account_status === 'deleted'
            || AgentStatus::tryFrom($agent->agent_status) === null) {
            throw new AuthorizationException('Agent purchase count requires a current Agent account.');
        }

        return (int) $connection->table('purchase_settlements as settlement')
            ->join('payment_intents as intent', 'intent.id', '=', 'settlement.payment_intent_id')
            ->join('quotes as quote', 'quote.id', '=', 'settlement.source_quote_id')
            ->where('settlement.user_id', $subjectUserId)
            ->where('intent.user_id', $subjectUserId)
            ->where('intent.purpose', 'purchase')
            ->whereNotNull('intent.captured_at')
            ->whereIn('intent.state', ['captured', 'refund_pending', 'refunded', 'partially_refunded'])
            ->whereColumn('intent.source_quote_id', 'quote.id')
            ->whereColumn('intent.source_quote_public_id', 'quote.public_id')
            ->whereColumn('settlement.source_quote_public_id', 'quote.public_id')
            ->where('quote.user_id', $subjectUserId)
            ->where('quote.account_type_snapshot', 'agent')
            ->where('quote.action_snapshot', 'purchase')
            ->whereNotNull('quote.agent_pricing_resolution_id')
            ->where('quote.agent_pricing_action_snapshot', 'purchase')
            ->count('settlement.id');
    }
}

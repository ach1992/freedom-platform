<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final class DatabaseOutboxClaimQuery
{
    public static function claimable(Connection $connection, string $now): Builder
    {
        return $connection->table('outbox_messages')
            ->whereNull('processed_at')
            ->whereIn('dispatch_state', ['pending', 'retry', 'leased'])
            ->where('available_at', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('leased_until')
                    ->orWhere('leased_until', '<=', $now);
            });
    }

    public static function reviewRequired(Connection $connection): Builder
    {
        return $connection->table('outbox_messages')
            ->whereNull('processed_at')
            ->where('dispatch_state', 'review_required');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Serializes canonical membership-configuration writes with provider-bound
 * membership presentation/authorization resolution. Membership rules are
 * non-deletable, so once policy exists the oldest rule is a stable
 * transactional fence row. Before the first rule exists, the oldest required
 * channel fences rule creation. Before even the first required channel exists,
 * the oldest administrator is stable because every canonical configuration
 * mutation requires an authorized administrator; without one, no competing
 * configuration mutation can occur.
 */
final readonly class TelegramMembershipConfigurationFence
{
    public function acquire(Connection $connection): void
    {
        $row = $connection->table('channel_membership_rules')
            ->orderBy('id')
            ->lockForUpdate()
            ->first(['id']);

        if ($row === null) {
            $row = $connection->table('required_channels')
                ->orderBy('id')
                ->lockForUpdate()
                ->first(['id']);
            if ($row === null) {
                $row = $connection->table('administrators')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first(['id']);
                if ($row === null) {
                    return;
                }
            }
        }

        $id = filter_var($row->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException('Telegram membership configuration fence identity is invalid.');
        }
    }
}

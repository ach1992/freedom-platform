<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Serializes canonical membership-configuration writes with provider-bound
 * membership presentation resolution. Membership rules are non-deletable, so
 * once policy exists the oldest rule is a stable transactional fence row.
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
            return;
        }

        $id = filter_var($row->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException('Telegram membership configuration fence identity is invalid.');
        }
    }
}

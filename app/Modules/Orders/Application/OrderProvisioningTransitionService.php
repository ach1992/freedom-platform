<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\OrderState;
use Illuminate\Database\Connection;

final readonly class OrderProvisioningTransitionService
{
    public function transition(
        Connection $connection,
        int $orderId,
        OrderState $fromState,
        int $fromVersion,
        int $toVersion,
        string $timestamp,
    ): int {
        return $connection->table('orders')
            ->where('id', $orderId)
            ->where('state', $fromState->value)
            ->where('state_version', $fromVersion)
            ->update([
                'state' => OrderState::ProvisioningQueued->value,
                'state_version' => $toVersion,
                'updated_at' => $timestamp,
            ]);
    }
}

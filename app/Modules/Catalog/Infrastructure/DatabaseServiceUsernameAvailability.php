<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\Catalog\Application\ServiceUsernameAvailability;
use DomainException;
use Illuminate\Database\Connection;

final class DatabaseServiceUsernameAvailability implements ServiceUsernameAvailability
{
    public function assertAvailable(Connection $connection, string $normalizedUsername, ?int $calculationId = null): void
    {
        $query = $connection->table('service_username_registry')
            ->where('active_normalized_username', $normalizedUsername);
        if ($calculationId !== null) {
            $query->where(static function ($nested) use ($calculationId): void {
                $nested->whereNull('custom_plan_calculation_id')
                    ->orWhere('custom_plan_calculation_id', '<>', $calculationId);
            });
        }
        if ($query->exists()) {
            throw new DomainException('Service username is unavailable.');
        }
    }
}

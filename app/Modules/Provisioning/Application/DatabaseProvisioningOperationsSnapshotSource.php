<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Operations\Application\Contracts\ProvisioningOperationsSnapshotSource;
use App\Modules\Operations\Application\OperationsCenterFact;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;

final readonly class DatabaseProvisioningOperationsSnapshotSource implements ProvisioningOperationsSnapshotSource
{
    public function __construct(private DatabaseManager $database) {}

    public function facts(DateTimeImmutable $now): array
    {
        $connection = $this->database->connection();
        $review = (int) $connection->table('provisioning_operations')
            ->whereIn('state', ['uncertain_remote_result', 'failed_final', 'needs_review'])
            ->count();
        $unresolved = (int) $connection->table('service_sync_anomalies')
            ->whereIn('state', ['open', 'manual_review', 'action_requested'])
            ->count();

        return [
            new OperationsCenterFact(
                'provisioning',
                'provisioning.manual_review',
                $review === 0 ? 'empty' : 'manual_review',
                $review,
            ),
            new OperationsCenterFact(
                'provisioning',
                'provisioning.sync_anomalies',
                $unresolved === 0 ? 'empty' : 'manual_review',
                $unresolved,
            ),
        ];
    }
}

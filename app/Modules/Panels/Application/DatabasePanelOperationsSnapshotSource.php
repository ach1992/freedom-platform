<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Operations\Application\Contracts\PanelOperationsSnapshotSource;
use App\Modules\Operations\Application\OperationsCenterFact;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Throwable;

final readonly class DatabasePanelOperationsSnapshotSource implements PanelOperationsSnapshotSource
{
    private const UTC = 'UTC';

    private const MAX_PROVIDER_FACTS = 20;

    public function __construct(private DatabaseManager $database) {}

    public function facts(DateTimeImmutable $now): array
    {
        $connection = $this->database->connection();
        $total = (int) $connection->table('panel_connections')->count();
        if ($total === 0) {
            return [new OperationsCenterFact('panels', 'panels.inventory', 'empty', 0)];
        }

        $providerCount = (int) $connection->table('panel_connections')
            ->distinct()
            ->count('provider_type');

        /** @var list<object{provider_type:string,connection_count:int|string,tested_count:int|string,failed_count:int|string,last_tested_at:?string}> $rows */
        $rows = $connection->table('panel_connections')
            ->selectRaw(
                "provider_type, COUNT(*) AS connection_count, ".
                "SUM(CASE WHEN last_test_status IS NOT NULL THEN 1 ELSE 0 END) AS tested_count, ".
                "SUM(CASE WHEN last_test_status = 'failure' THEN 1 ELSE 0 END) AS failed_count, ".
                'MAX(last_tested_at) AS last_tested_at',
            )
            ->groupBy('provider_type')
            ->orderBy('provider_type')
            ->limit(self::MAX_PROVIDER_FACTS)
            ->get()
            ->all();

        $facts = [
            new OperationsCenterFact(
                'panels',
                'panels.inventory',
                $providerCount > self::MAX_PROVIDER_FACTS ? 'unknown' : 'observed',
                $total,
                $providerCount > self::MAX_PROVIDER_FACTS
                    ? 'providers='.$providerCount.';visible_groups='.self::MAX_PROVIDER_FACTS.';truncated=1'
                    : 'providers='.$providerCount,
            ),
        ];

        foreach ($rows as $row) {
            $provider = $this->safeCode($row->provider_type);
            $tested = (int) $row->tested_count;
            $failed = (int) $row->failed_count;
            $facts[] = new OperationsCenterFact(
                'panels',
                'panels.provider.'.$provider,
                $failed > 0 ? 'degraded' : ($tested > 0 ? 'observed' : 'unknown'),
                (int) $row->connection_count,
                'provider='.$provider.';tested='.$tested.';failed='.$failed,
                $this->date($row->last_tested_at),
            );
        }

        return $facts;
    }

    private function safeCode(string $value): string
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $value) === 1) {
            return $value;
        }

        return 'unknown-'.substr(hash('sha256', $value), 0, 8);
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone(self::UTC)))
                ->setTimezone(new DateTimeZone(self::UTC));
        } catch (Throwable) {
            return null;
        }
    }
}

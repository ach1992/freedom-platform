<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class OperationalAlertQueryService
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
    ) {}

    /**
     * @return list<array{
     *   id:string,severity:string,event_name:string,occurrence_count:int,activation_sequence:int,
     *   first_seen_at:string,last_seen_at:string,acknowledged_at:?string,resolved_at:?string
     * }>
     */
    public function unresolved(int $actorUserId, int $limit = 10): array
    {
        $this->administrators->authorizeUser($actorUserId, OperationsPermissions::VIEW);
        if ($limit < 1 || $limit > 25) {
            throw new RuntimeException('Operational alert query limit must be between 1 and 25.');
        }

        /** @var list<object{id:string,severity:string,event_name:string,occurrence_count:int|string,activation_sequence:int|string,first_seen_at:string,last_seen_at:string,acknowledged_at:?string,resolved_at:?string}> $rows */
        $rows = $this->database->connection()->table('alerts')
            ->whereNull('resolved_at')
            ->orderByRaw("CASE severity WHEN 'security' THEN 1 WHEN 'critical' THEN 2 WHEN 'warning' THEN 3 ELSE 4 END")
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->limit($limit)
            ->get([
                'id',
                'severity',
                'event_name',
                'occurrence_count',
                'activation_sequence',
                'first_seen_at',
                'last_seen_at',
                'acknowledged_at',
                'resolved_at',
            ])
            ->all();

        return array_map(static fn (object $row): array => [
            'id' => $row->id,
            'severity' => $row->severity,
            'event_name' => $row->event_name,
            'occurrence_count' => (int) $row->occurrence_count,
            'activation_sequence' => (int) $row->activation_sequence,
            'first_seen_at' => $row->first_seen_at,
            'last_seen_at' => $row->last_seen_at,
            'acknowledged_at' => $row->acknowledged_at,
            'resolved_at' => $row->resolved_at,
        ], $rows);
    }

    /**
     * @return array{
     *   id:string,severity:string,event_name:string,occurrence_count:int,activation_sequence:int,
     *   first_seen_at:string,last_seen_at:string,acknowledged_at:?string,resolved_at:?string
     * }|null
     */
    public function find(int $actorUserId, string $alertId): ?array
    {
        $this->administrators->authorizeUser($actorUserId, OperationsPermissions::VIEW);
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $alertId) !== 1) {
            throw new RuntimeException('Operational alert identity is invalid.');
        }

        /** @var object{id:string,severity:string,event_name:string,occurrence_count:int|string,activation_sequence:int|string,first_seen_at:string,last_seen_at:string,acknowledged_at:?string,resolved_at:?string}|null $row */
        $row = $this->database->connection()->table('alerts')
            ->where('id', $alertId)
            ->first([
                'id',
                'severity',
                'event_name',
                'occurrence_count',
                'activation_sequence',
                'first_seen_at',
                'last_seen_at',
                'acknowledged_at',
                'resolved_at',
            ]);
        if ($row === null) {
            return null;
        }

        return [
            'id' => $row->id,
            'severity' => $row->severity,
            'event_name' => $row->event_name,
            'occurrence_count' => (int) $row->occurrence_count,
            'activation_sequence' => (int) $row->activation_sequence,
            'first_seen_at' => $row->first_seen_at,
            'last_seen_at' => $row->last_seen_at,
            'acknowledged_at' => $row->acknowledged_at,
            'resolved_at' => $row->resolved_at,
        ];
    }
}

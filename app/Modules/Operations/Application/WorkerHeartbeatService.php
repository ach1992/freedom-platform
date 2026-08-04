<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Shared\Application\Clock;
use DateInterval;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @requirement OPS-001 OPS-003
 */
final readonly class WorkerHeartbeatService
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function record(string $workerId, string $queue, ?string $releaseVersion = null): void
    {
        $workerId = trim($workerId);
        $queue = trim($queue);

        if ($workerId === '' || mb_strlen($workerId) > 191) {
            throw new InvalidArgumentException('Worker ID must contain between 1 and 191 characters.');
        }

        if ($queue === '' || mb_strlen($queue) > 64) {
            throw new InvalidArgumentException('Queue name must contain between 1 and 64 characters.');
        }

        $now = $this->clock->now();

        $this->database->table('worker_heartbeats')->updateOrInsert(
            ['worker_id' => $workerId],
            [
                'queue' => $queue,
                'host_hash' => hash('sha256', gethostname() ?: 'unknown'),
                'release_version' => $releaseVersion,
                'last_seen_at' => $now->format('Y-m-d H:i:s.u'),
                'created_at' => $now->format('Y-m-d H:i:s.u'),
                'updated_at' => $now->format('Y-m-d H:i:s.u'),
            ],
        );
    }

    /**
     * @return list<string>
     */
    public function detectAndAlertStale(int $maxAgeSeconds): array
    {
        if ($maxAgeSeconds < 1 || $maxAgeSeconds > 86400) {
            throw new InvalidArgumentException('Maximum heartbeat age must be between 1 and 86400 seconds.');
        }

        $now = $this->clock->now();
        $cutoff = $now->sub(new DateInterval(sprintf('PT%dS', $maxAgeSeconds)));

        /** @var list<object{worker_id:string, queue:string, last_seen_at:string}> $stale */
        $stale = $this->database->table('worker_heartbeats')
            ->select(['worker_id', 'queue', 'last_seen_at'])
            ->where('last_seen_at', '<', $cutoff->format('Y-m-d H:i:s.u'))
            ->orderBy('worker_id')
            ->get()
            ->all();

        foreach ($stale as $heartbeat) {
            $deduplicationKey = hash('sha256', 'worker-heartbeat-stale:'.$heartbeat->worker_id);
            $safeContext = json_encode([
                'worker_id' => $heartbeat->worker_id,
                'queue' => $heartbeat->queue,
                'last_seen_at' => $heartbeat->last_seen_at,
                'max_age_seconds' => $maxAgeSeconds,
            ], JSON_THROW_ON_ERROR);

            $existing = $this->database->table('alerts')
                ->where('event_name', 'operations.worker_heartbeat_stale')
                ->where('deduplication_key', $deduplicationKey)
                ->first();

            if ($existing === null) {
                $this->database->table('alerts')->insert([
                    'id' => (string) Str::uuid(),
                    'severity' => 'critical',
                    'event_name' => 'operations.worker_heartbeat_stale',
                    'deduplication_key' => $deduplicationKey,
                    'correlation_id' => (string) Str::uuid(),
                    'safe_context' => $safeContext,
                    'occurrence_count' => 1,
                    'first_seen_at' => $now->format('Y-m-d H:i:s.u'),
                    'last_seen_at' => $now->format('Y-m-d H:i:s.u'),
                    'created_at' => $now->format('Y-m-d H:i:s.u'),
                    'updated_at' => $now->format('Y-m-d H:i:s.u'),
                ]);

                continue;
            }

            $this->database->table('alerts')
                ->where('id', $existing->id)
                ->update([
                    'safe_context' => $safeContext,
                    'occurrence_count' => ((int) $existing->occurrence_count) + 1,
                    'last_seen_at' => $now->format('Y-m-d H:i:s.u'),
                    'resolved_at' => null,
                    'updated_at' => $now->format('Y-m-d H:i:s.u'),
                ]);
        }

        return array_map(
            static fn (object $heartbeat): string => $heartbeat->worker_id,
            $stale,
        );
    }
}

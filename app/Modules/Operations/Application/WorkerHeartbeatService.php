<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Shared\Application\Clock;
use App\Shared\Application\OperationalAlertRecorder;
use DateInterval;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @requirement OPS-001 OPS-003
 */
final readonly class WorkerHeartbeatService
{
    private DatabaseManager $database;

    private Clock $clock;

    private OperationalAlertRecorder $alerts;

    public function __construct(
        DatabaseManager $database,
        Clock $clock,
        ?OperationalAlertRecorder $alerts = null,
    ) {
        $this->database = $database;
        $this->clock = $clock;
        $this->alerts = $alerts ?? new DatabaseOperationalAlertRecorder($database, $clock);
    }

    public function record(
        string $workerId,
        string $queue,
        ?string $releaseVersion = null,
        ?string $bootId = null,
    ): void {
        $workerId = trim($workerId);
        $queue = trim($queue);
        $releaseVersion = $releaseVersion === null ? null : trim($releaseVersion);
        $bootId = $bootId === null ? null : strtolower(trim($bootId));

        if ($workerId === '' || mb_strlen($workerId) > 191) {
            throw new InvalidArgumentException('Worker ID must contain between 1 and 191 characters.');
        }

        if ($queue === '' || mb_strlen($queue) > 191) {
            throw new InvalidArgumentException('Queue name must contain between 1 and 191 characters.');
        }

        if ($releaseVersion !== null
            && ($releaseVersion === '' || strlen($releaseVersion) > 64 || preg_match('/[\x00-\x1F\x7F]/', $releaseVersion) === 1)
        ) {
            throw new InvalidArgumentException('Worker release identity must contain between 1 and 64 safe characters.');
        }

        if ($bootId !== null && preg_match('/\A[0-9a-f]{32}\z/', $bootId) !== 1) {
            throw new InvalidArgumentException('Worker boot identity must be 32 lowercase hexadecimal characters.');
        }

        $now = $this->clock->now();
        $timestamp = $now->format('Y-m-d H:i:s.u');

        $this->database->table('worker_heartbeats')->updateOrInsert(
            ['worker_id' => $workerId],
            [
                'queue' => $queue,
                'host_hash' => hash('sha256', gethostname() ?: 'unknown'),
                'release_version' => $releaseVersion,
                'boot_id' => $bootId,
                'last_seen_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        );

        $this->alerts->resolve(
            'operations.worker_heartbeat_stale',
            $this->deduplicationKey($workerId),
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
            $this->alerts->raise(
                'critical',
                'operations.worker_heartbeat_stale',
                $this->deduplicationKey($heartbeat->worker_id),
                (string) Str::uuid(),
                [
                    'worker_id' => $heartbeat->worker_id,
                    'queue' => $heartbeat->queue,
                    'last_seen_at' => $heartbeat->last_seen_at,
                    'max_age_seconds' => $maxAgeSeconds,
                ],
            );
        }

        return array_map(
            static fn (object $heartbeat): string => $heartbeat->worker_id,
            $stale,
        );
    }

    private function deduplicationKey(string $workerId): string
    {
        return hash('sha256', 'worker-heartbeat-stale:'.$workerId);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\UpdateSafetyInspector;
use App\Modules\Operations\Application\UpdateMigrationIdentity;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class DatabaseUpdateSafetyInspector implements UpdateSafetyInspector
{
    public function __construct(
        private DatabaseManager $database,
        private string $currentMigrationsDirectory,
        private string $supervisorTemplatePath,
    ) {}

    /**
     * @requirement UPD-001 OPS-003 PAY-003 PRV-003 QUA-001
     *
     * @phpstan-impure
     */
    public function currentSchemaSha256(): string
    {
        return $this->installedSchemaSha256(
            $this->currentMigrationsDirectory,
            'The current schema migration authority does not match the active release files.',
        );
    }

    /**
     * @requirement UPD-001 RUN-002 RUN-006 QUA-001
     *
     * @phpstan-impure
     */
    public function installedSchemaSha256ForRelease(string $releasePath): string
    {
        if (! str_starts_with($releasePath, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The installed release schema path must be absolute.');
        }

        return $this->installedSchemaSha256(
            $releasePath.'/database/migrations',
            'The installed schema migration authority does not match the specified release files.',
        );
    }

    /** @requirement UPD-001 RUN-006 QUA-001 */
    public function releaseSchemaSha256(string $releasePath): string
    {
        if (! str_starts_with($releasePath, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The release schema path must be absolute.');
        }

        return UpdateMigrationIdentity::fromDirectory($releasePath.'/database/migrations');
    }

    /** @requirement UPD-001 PAY-003 PRV-003 QUA-001 */
    public function assertNoUnsafeWork(): void
    {
        $connection = $this->database->connection();
        $schema = $connection->getSchemaBuilder();

        if ($schema->hasTable('purchase_provider_mutation_attempts')
            && $connection->table('purchase_provider_mutation_attempts')
                ->whereIn('state', ['prepared', 'external_started', 'reconciliation_required'])
                ->exists()
        ) {
            throw new RuntimeException('Unsafe purchase-provider mutation work is unresolved.');
        }

        if ($schema->hasTable('provisioning_operations')
            && $connection->table('provisioning_operations')
                ->whereIn('state', ['running', 'uncertain_remote_result', 'compensating'])
                ->exists()
        ) {
            throw new RuntimeException('Unsafe provisioning/provider work is active or unresolved.');
        }

        if ($schema->hasTable('service_sync_runs')
            && $connection->table('service_sync_runs')->where('state', 'running')->exists()
        ) {
            throw new RuntimeException('Unsafe provider synchronization work is active.');
        }
    }

    /** @return array<string, string> */
    public function workerBootIds(): array
    {
        $expectedWorkers = $this->expectedWorkerIds();
        if ($expectedWorkers === []) {
            throw new RuntimeException('The reviewed worker runtime topology is unavailable.');
        }

        $rows = $this->workerRows($expectedWorkers);
        $bootIds = [];
        foreach ($rows as $row) {
            if (! is_string($row->worker_id ?? null)
                || ! is_string($row->boot_id ?? null)
                || preg_match('/\\A[0-9a-f]{32}\\z/', $row->boot_id) !== 1
            ) {
                continue;
            }

            $bootIds[$row->worker_id] = $row->boot_id;
        }

        return $bootIds;
    }

    /**
     * @param  array<string, string>  $previousBootIds
     *
     * @requirement UPD-001 RUN-003 OPS-003 QUA-001
     */
    public function assertWorkersRestartedAfter(
        string $restartedAfter,
        string $expectedReleaseId,
        array $previousBootIds,
    ): void {
        $restart = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s.u',
            $restartedAfter,
            new \DateTimeZone('UTC'),
        );
        if ($restart === false || $restart->format('Y-m-d H:i:s.u') !== $restartedAfter) {
            throw new RuntimeException('The worker restart verification timestamp is invalid.');
        }
        if (preg_match('/\\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\\z/', $expectedReleaseId) !== 1
            || str_contains($expectedReleaseId, '..')
        ) {
            throw new RuntimeException('The expected worker release identity is invalid.');
        }
        foreach ($previousBootIds as $workerId => $bootId) {
            if (! is_string($workerId)
                || ! is_string($bootId)
                || preg_match('/\\A[0-9a-f]{32}\\z/', $bootId) !== 1
            ) {
                throw new RuntimeException('The previous worker boot evidence is invalid.');
            }
        }

        $expectedWorkers = $this->expectedWorkerIds();
        if ($expectedWorkers === []) {
            throw new RuntimeException('The reviewed worker runtime topology is unavailable.');
        }

        $actual = [];
        foreach ($this->workerRows($expectedWorkers) as $row) {
            $workerId = $row->worker_id ?? null;
            $releaseVersion = $row->release_version ?? null;
            $bootId = $row->boot_id ?? null;
            $lastSeenAt = $row->last_seen_at ?? null;

            if (! is_string($workerId)
                || ! in_array($workerId, $expectedWorkers, true)
                || ! is_string($releaseVersion)
                || ! hash_equals($expectedReleaseId, $releaseVersion)
                || ! is_string($bootId)
                || preg_match('/\\A[0-9a-f]{32}\\z/', $bootId) !== 1
                || ! is_string($lastSeenAt)
            ) {
                continue;
            }

            $lastSeen = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s.u',
                $lastSeenAt,
                new \DateTimeZone('UTC'),
            );
            if ($lastSeen === false
                || $lastSeen->format('Y-m-d H:i:s.u') !== $lastSeenAt
                || $lastSeen < $restart
            ) {
                continue;
            }

            $previousBootId = $previousBootIds[$workerId] ?? null;
            if (is_string($previousBootId) && hash_equals($previousBootId, $bootId)) {
                continue;
            }

            $actual[$workerId] = true;
        }

        $actualWorkers = array_keys($actual);
        sort($actualWorkers, SORT_STRING);
        sort($expectedWorkers, SORT_STRING);

        if ($actualWorkers !== $expectedWorkers) {
            throw new RuntimeException('The activated release worker boot verification failed.');
        }
    }

    /**
     * @param  list<string>  $expectedWorkers
     * @return list<\stdClass>
     */
    private function workerRows(array $expectedWorkers): array
    {
        $rows = $this->database->connection()
            ->table('worker_heartbeats')
            ->whereIn('worker_id', $expectedWorkers)
            ->get(['worker_id', 'release_version', 'boot_id', 'last_seen_at'])
            ->all();

        $objects = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $objects[] = $row;
            }
        }

        return $objects;
    }

    private function installedSchemaSha256(string $migrationsDirectory, string $mismatchMessage): string
    {
        $connection = $this->database->connection();
        if (! $connection->getSchemaBuilder()->hasTable('migrations')) {
            throw new RuntimeException('The current schema migration authority is unavailable.');
        }

        $migrations = $connection->table('migrations')->pluck('migration')->all();
        if (! is_array($migrations) || $migrations === []) {
            throw new RuntimeException('The current schema migration identity is unavailable.');
        }

        $names = [];
        foreach ($migrations as $migration) {
            if (! is_string($migration)) {
                throw new RuntimeException('The current schema migration identity is malformed.');
            }
            $names[] = $migration;
        }
        sort($names, SORT_STRING);

        if ($names !== UpdateMigrationIdentity::namesFromDirectory($migrationsDirectory)) {
            throw new RuntimeException($mismatchMessage);
        }

        return UpdateMigrationIdentity::fromDirectory($migrationsDirectory);
    }

    /** @return list<string> */
    private function expectedWorkerIds(): array
    {
        if (! str_starts_with($this->supervisorTemplatePath, DIRECTORY_SEPARATOR)
            || ! is_file($this->supervisorTemplatePath)
            || is_link($this->supervisorTemplatePath)
            || ! is_readable($this->supervisorTemplatePath)
        ) {
            throw new RuntimeException('The reviewed worker Supervisor template is unavailable.');
        }

        $contents = file_get_contents($this->supervisorTemplatePath);
        if (! is_string($contents)) {
            throw new RuntimeException('The reviewed worker Supervisor template is unreadable.');
        }

        $workers = [];
        $blocks = preg_split('/(?=\[program:[^\]]+\])/', $contents, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($blocks)) {
            throw new RuntimeException('The reviewed worker Supervisor topology is invalid.');
        }

        foreach ($blocks as $block) {
            if (preg_match('/\A\[program:([^\]]+)\]/m', trim($block), $program) !== 1) {
                continue;
            }
            if (preg_match('/^numprocs=(\d+)$/m', $block, $count) !== 1) {
                throw new RuntimeException('A reviewed worker program has no exact process count.');
            }

            $numprocs = (int) $count[1];
            if ($numprocs < 1 || $numprocs > 32) {
                throw new RuntimeException('A reviewed worker program process count is invalid.');
            }

            for ($index = 0; $index < $numprocs; $index++) {
                $workers[] = sprintf('%s_%02d', $program[1], $index);
            }
        }

        if (count($workers) !== count(array_unique($workers))) {
            throw new RuntimeException('The reviewed worker topology contains duplicate process identities.');
        }

        return $workers;
    }
}

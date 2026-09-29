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

    /** @requirement UPD-001 RUN-003 OPS-003 QUA-001 */
    public function assertWorkersRestartedAfter(string $activatedAfter): void
    {
        $activation = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $activatedAfter, new \DateTimeZone('UTC'));
        if ($activation === false || $activation->format('Y-m-d H:i:s.u') !== $activatedAfter) {
            throw new RuntimeException('The activated worker verification timestamp is invalid.');
        }

        $expectedWorkers = $this->expectedWorkerIds();
        if ($expectedWorkers === []) {
            throw new RuntimeException('The reviewed worker runtime topology is unavailable.');
        }

        $rows = $this->database->connection()
            ->table('worker_heartbeats')
            ->whereIn('worker_id', $expectedWorkers)
            ->where('last_seen_at', '>=', $activatedAfter)
            ->pluck('worker_id')
            ->all();

        $actual = [];
        foreach ($rows as $workerId) {
            if (is_string($workerId)) {
                $actual[] = $workerId;
            }
        }
        sort($actual, SORT_STRING);
        sort($expectedWorkers, SORT_STRING);

        if ($actual !== $expectedWorkers) {
            throw new RuntimeException('The activated release worker boot verification failed.');
        }
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

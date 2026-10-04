<?php

declare(strict_types=1);

namespace App\Modules\Installer\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final class WriteInstallerReportCommand extends Command
{
    protected $signature = 'installer:write-report {--json : Emit a machine-readable secret-free result}';

    protected $description = 'Write the final secret-free installation verification report';

    public function handle(DatabaseManager $database): int
    {
        $ownerCount = $database->connection()->table('administrators')
            ->where('is_owner', true)
            ->where('status', 'active')
            ->count();

        if ($ownerCount !== 1) {
            $this->components->error('Installation report requires exactly one active Owner.');

            return self::FAILURE;
        }

        $path = (string) config('installer.report_path');
        if ($path === '' || ! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $this->components->error('Installation report path is invalid.');

            return self::FAILURE;
        }

        $report = [
            'version' => 1,
            'status' => 'verified',
            'application_version' => (string) config('app.version', 'unknown'),
            'environment' => (string) app()->environment(),
            'owner_count' => 1,
            'completed_at_utc' => now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
        ];
        $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        try {
            $this->atomicWrite($path, $encoded);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line(json_encode([
                'status' => 'written',
                'application_version' => $report['application_version'],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Secret-free installation report written.');

        return self::SUCCESS;
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Installation report directory could not be prepared.');
        }

        $temporary = tempnam($directory, '.install-report-');
        if ($temporary === false) {
            throw new RuntimeException('Installation report temporary file could not be created.');
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || ! chmod($temporary, 0600)
                || ! rename($temporary, $path)
            ) {
                throw new RuntimeException('Installation report could not be written securely.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}

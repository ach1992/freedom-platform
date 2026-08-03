<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Modules\Operations\Application\RuntimeHealthProbe;
use Illuminate\Console\Command;

final class HealthCheckCommand extends Command
{
    protected $signature = 'health:check {--critical : Compatibility flag; failures are always non-zero} {--json : Emit JSON only} {--redact : Compatibility flag; output is always redacted}';

    protected $description = 'Check application, database, Redis, configuration, and writable runtime paths';

    public function handle(RuntimeHealthProbe $probe): int
    {
        $checks = $probe->checks();

        $failed = array_keys(array_filter($checks, fn (array $check): bool => ! $check['passed']));
        $result = [
            'status' => $failed === [] ? 'healthy' : 'unhealthy',
            'release' => config('app.version', 'unversioned'),
            'checks' => $checks,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $name => $check) {
                $this->line(sprintf('[%s] %s', $check['passed'] ? 'PASS' : 'FAIL', $name));
            }
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}

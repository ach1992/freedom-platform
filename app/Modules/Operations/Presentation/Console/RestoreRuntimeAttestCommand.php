<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Modules\Operations\Application\Contracts\RestoreCriticalAuthorityIdentity;
use App\Modules\Operations\Application\RuntimeHealthProbe;
use Illuminate\Console\Command;

final class RestoreRuntimeAttestCommand extends Command
{
    protected $signature = 'operations:restore-runtime-attest
        {--expected-authority-fingerprint= : Expected non-secret critical authority fingerprint}
        {--json : Emit JSON only}';

    protected $description = 'Verify restored runtime health and critical authority continuity';

    /** @requirement BAK-002 OPS-001 OPS-003 SEC-001 QUA-001 */
    public function handle(
        RuntimeHealthProbe $health,
        RestoreCriticalAuthorityIdentity $authority,
    ): int {
        $expected = $this->option('expected-authority-fingerprint');
        if (! is_string($expected) || preg_match('/\A[0-9a-f]{64}\z/', $expected) !== 1) {
            $this->error('Expected restore authority fingerprint is invalid.');

            return self::FAILURE;
        }

        $checks = $health->checks();
        $runtimeHealthy = $health->isHealthy($checks);
        $authorityMatches = hash_equals($expected, $authority->fingerprint());
        $result = [
            'status' => $runtimeHealthy && $authorityMatches ? 'healthy' : 'unhealthy',
            'runtime_health' => $runtimeHealthy,
            'critical_authority_identity' => $authorityMatches,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf('[%s] runtime health', $runtimeHealthy ? 'PASS' : 'FAIL'));
            $this->line(sprintf('[%s] critical authority identity', $authorityMatches ? 'PASS' : 'FAIL'));
        }

        return $runtimeHealthy && $authorityMatches ? self::SUCCESS : self::FAILURE;
    }
}
